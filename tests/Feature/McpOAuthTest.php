<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.url', 'https://proxy.example.test');
    Config::set('gitverse.endpoint', 'https://mcp.gitverse.ru');
});

function registerRemoteClient($testCase, string $callback = 'https://chatgpt.com/connector_platform_oauth_redirect', string $name = 'ChatGPT'): string
{
    return $testCase->postJson('/oauth/register', [
        'client_name' => $name,
        'redirect_uris' => [$callback],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ])->assertCreated()->json('client_id');
}

/**
 * @return array{client_id: string, code: string, verifier: string, redirect_uri: string}
 */
function beginMcpAuthorization($testCase, string $personalToken = 'valid-personal-gitverse-token', string $callback = 'https://chatgpt.com/connector_platform_oauth_redirect', string $name = 'ChatGPT'): array
{
    $client = registerRemoteClient($testCase, $callback, $name);
    $verifier = str_repeat('v', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirect = $callback;

    $testCase->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
        'code_challenge' => $challenge,
        'scope' => 'gitverse:access',
        'resource' => 'https://proxy.example.test/mcp',
        'state' => 'a-state-value',
    ]))->assertOk()->assertSee('Подключить GitVerse');

    Http::fake([
        'https://mcp.gitverse.ru*' => Http::response(['jsonrpc' => '2.0', 'result' => ['protocolVersion' => '2025-03-26']], 200),
    ]);

    $response = $testCase->post('/oauth/authorize', [
        'gitverse_token' => $personalToken,
    ])->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $parameters);

    expect($parameters['iss'])->toBe('https://proxy.example.test');
    expect($parameters['state'])->toBe('a-state-value');

    return [
        'client_id' => $client,
        'code' => $parameters['code'],
        'verifier' => $verifier,
        'redirect_uri' => $redirect,
    ];
}

function exchangeMcpCode($testCase, array $authorization, ?string $verifier = null): \Illuminate\Testing\TestResponse
{
    return $testCase->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $authorization['client_id'],
        'code' => $authorization['code'],
        'code_verifier' => $verifier ?? $authorization['verifier'],
        'redirect_uri' => $authorization['redirect_uri'],
        'resource' => 'https://proxy.example.test/mcp',
    ]);
}

it('advertises protected resource and oauth metadata for chatgpt', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('resource', 'https://proxy.example.test/mcp')
        ->assertJsonPath('authorization_servers.0', 'https://proxy.example.test');

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('token_endpoint_auth_methods_supported.0', 'none')
        ->assertJsonPath('code_challenge_methods_supported.0', 'S256')
        ->assertJsonPath('authorization_response_iss_parameter_supported', true);
});

it('accepts arbitrary HTTPS provider callbacks and rejects unsafe redirects', function (): void {
    $clientId = registerRemoteClient($this, 'https://claude.ai/api/mcp/auth_callback', 'Claude Web');

    expect($clientId)->toBeString();

    foreach ([
        'http://example.com/callback',
        'https://localhost/callback',
        'https://127.0.0.1/callback',
        'https://attacker.example@localhost/callback',
        'https://cloud.example/callback#fragment',
        'https://cloud.example:8443/callback',
    ] as $unsafeUri) {
        $this->postJson('/oauth/register', [
            'redirect_uris' => [$unsafeUri],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    }

    expect(DB::table('mcp_oauth_clients')->count())->toBe(1);
});

it('exchanges a single use authorization code with pkce and never gives chatgpt the gitverse token', function (): void {
    $authorization = beginMcpAuthorization($this);

    exchangeMcpCode($this, $authorization, str_repeat('x', 64))
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $response = exchangeMcpCode($this, $authorization)
        ->assertOk()->assertJsonPath('token_type', 'Bearer');

    expect($response->json('access_token'))->not->toBe('valid-personal-gitverse-token');
    expect($response->json('refresh_token'))->not->toBe('valid-personal-gitverse-token');

    exchangeMcpCode($this, $authorization)
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(DB::table('mcp_gitverse_credentials')->count())->toBe(1);
});

it('creates UUID primary keys and matching credential foreign keys', function (): void {
    $authorization = beginMcpAuthorization($this);
    exchangeMcpCode($this, $authorization)->assertOk();

    $credential = DB::table('mcp_gitverse_credentials')->first();
    $code = DB::table('mcp_oauth_codes')->first();
    $tokens = DB::table('mcp_oauth_tokens')->get();

    expect(str()->isUuid($credential->id))->toBeTrue()
        ->and(str()->isUuid($code->id))->toBeTrue()
        ->and($code->credential_id)->toBe($credential->id)
        ->and($tokens)->toHaveCount(2);

    foreach ($tokens as $token) {
        expect(str()->isUuid($token->id))->toBeTrue()
            ->and($token->credential_id)->toBe($credential->id);
    }
});

it('rotates refresh tokens and rejects replayed refresh tokens', function (): void {
    $authorization = beginMcpAuthorization($this);
    $tokens = exchangeMcpCode($this, $authorization)->assertOk()->json();

    $renewed = $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $authorization['client_id'],
        'refresh_token' => $tokens['refresh_token'],
        'resource' => 'https://proxy.example.test/mcp',
    ])->assertOk()->json();

    expect($renewed['refresh_token'])->not->toBe($tokens['refresh_token']);

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $authorization['client_id'],
        'refresh_token' => $tokens['refresh_token'],
        'resource' => 'https://proxy.example.test/mcp',
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('challenges unauthenticated mcp requests with resource metadata', function (): void {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

it('proxies an authenticated mcp call without forwarding chatgpt access token to gitverse', function (): void {
    $authorization = beginMcpAuthorization($this);
    $tokens = exchangeMcpCode($this, $authorization)->assertOk()->json();

    Http::fake([
        'https://mcp.gitverse.ru*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['tools' => []],
        ], 200, ['Content-Type' => 'application/json']),
    ]);

    $result = $this->withToken($tokens['access_token'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

    $result->assertOk();
    expect($result->streamedContent())->toContain('"tools"');

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer valid-personal-gitverse-token')
            && $request->method() === 'POST';
    });
});

it('rejects a GitVerse credential that the upstream MCP refuses', function (): void {
    $clientId = registerRemoteClient($this);
    $verifier = str_repeat('v', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
        'code_challenge' => $challenge,
        'scope' => 'gitverse:access',
        'resource' => 'https://proxy.example.test/mcp',
        'state' => 'another-state',
    ]))->assertOk();

    Http::fake(['https://mcp.gitverse.ru*' => Http::response(['error' => 'Unauthorized'], 401)]);

    $this->post('/oauth/authorize', [
        'gitverse_token' => 'an-invalid-GitVerse-token',
    ])->assertSessionHasErrors('gitverse_token');

    expect(DB::table('mcp_gitverse_credentials')->count())->toBe(0);
});

it('isolates separate AI clients and users by their GitVerse credentials', function (): void {
    $first = beginMcpAuthorization($this, 'first-user-personal-token');
    $firstTokens = exchangeMcpCode($this, $first)->assertOk()->json();

    $second = beginMcpAuthorization($this, 'second-user-personal-token', 'https://claude.ai/api/mcp/auth_callback', 'Claude Web');
    $secondTokens = exchangeMcpCode($this, $second)->assertOk()->json();

    expect(DB::table('mcp_gitverse_credentials')->count())->toBe(2);

    Http::fake([
        'https://mcp.gitverse.ru*' => Http::response(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $this->withToken($firstTokens['access_token'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
        ->assertOk();
    $this->withToken($secondTokens['access_token'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
        ->assertOk();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer first-user-personal-token'));
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer second-user-personal-token'));
});


it('uses Passport for the complete authorization code and PKCE exchange', function (): void {
    $this->artisan('passport:keys', ['--force' => true])->assertExitCode(0);

    $clientId = registerGatewayClient($this);
    $redirectUri = 'https://chatgpt.com/connector_platform_oauth_redirect';
    $verifier = str_repeat('A', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $query = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'gitverse:access',
        'state' => 'oauth-integration-test',
        'resource' => 'https://proxy.example.test/mcp',
        'code_challenge_method' => 'S256',
        'code_challenge' => $challenge,
    ]);

    $authorizeUrl = 'https://proxy.example.test/oauth/authorize?'.$query;

    $this->get($authorizeUrl)->assertRedirect('https://proxy.example.test/connect');

    Http::fake([
        'https://mcp.gitverse.ru*' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['protocolVersion' => '2025-03-26'],
        ], 200),
    ]);

    $this->get('https://proxy.example.test/connect')->assertOk();
    $this->post('https://proxy.example.test/connect', [
        'gitverse_token' => 'integration-gitverse-token',
    ])->assertRedirect($authorizeUrl);

    $consent = $this->get($authorizeUrl)->assertOk();
    $this->assertAuthenticated('web');

    preg_match('/name="auth_token" value="([^"]+)"/', $consent->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    $approved = $this->post('https://proxy.example.test/oauth/authorize', [
        'auth_token' => $matches[1],
    ])->assertRedirect();

    $callback = $approved->headers->get('Location');
    expect($callback)->toStartWith($redirectUri);

    parse_str((string) parse_url($callback, PHP_URL_QUERY), $params);
    expect($params['state'])->toBe('oauth-integration-test');

    $issued = $this->post('https://proxy.example.test/oauth/token', [
        'client_id' => $clientId,
        'grant_type' => 'authorization_code',
        'code' => $params['code'],
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
        'resource' => 'https://proxy.example.test/mcp',
    ])->assertOk();

    expect($issued->json('access_token'))->toBeString()
        ->and($issued->json('refresh_token'))->toBeString()
        ->and($issued->json('access_token'))->not->toBe('integration-gitverse-token');

    $this->post('https://proxy.example.test/oauth/token', [
        'client_id' => $clientId,
        'grant_type' => 'authorization_code',
        'code' => $params['code'],
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
        'resource' => 'https://proxy.example.test/mcp',
    ])->assertStatus(400);
});
