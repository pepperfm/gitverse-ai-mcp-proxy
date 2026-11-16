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
    Config::set('gitverse.allowed_token_sha256', hash('sha256', 'valid-personal-gitverse-token'));
});

function registerChatGptClient($testCase): string
{
    return $testCase->postJson('/oauth/register', [
        'client_name' => 'ChatGPT',
        'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ])->assertCreated()->json('client_id');
}

/**
 * @return array{client_id: string, code: string, verifier: string, redirect_uri: string}
 */
function beginMcpAuthorization($testCase): array
{
    $client = registerChatGptClient($testCase);
    $verifier = str_repeat('v', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';

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
        'gitverse_token' => 'valid-personal-gitverse-token',
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

it('rejects malicious oauth callback urls during client registration', function (): void {
    $this->postJson('/oauth/register', [
        'redirect_uris' => ['https://attacker.example/callback'],
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

    expect(DB::table('mcp_oauth_clients')->count())->toBe(0);
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

it('does not permit an arbitrary gitverse token to authorize the connection', function (): void {
    $client = registerChatGptClient($this);
    $verifier = str_repeat('v', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client,
        'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
        'code_challenge' => $challenge,
        'scope' => 'gitverse:access',
        'resource' => 'https://proxy.example.test/mcp',
        'state' => 'another-state',
    ]))->assertOk();

    Http::fake();

    $this->post('/oauth/authorize', [
        'gitverse_token' => 'someone-elses-valid-token',
    ])->assertSessionHasErrors('gitverse_token');

    Http::assertNothingSent();
    expect(DB::table('mcp_gitverse_credentials')->count())->toBe(0);
});
