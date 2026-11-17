<?php

declare(strict_types=1);

use App\Models\GitVerseCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.url', 'https://proxy.example.test');
    Config::set('gitverse.endpoint', 'https://mcp.gitverse.ru');
});

function registerGatewayClient($testCase, string $callback = 'https://chatgpt.com/connector_platform_oauth_redirect'): string
{
    return $testCase->postJson('/oauth/register', [
        'client_name' => 'Cloud AI',
        'redirect_uris' => [$callback],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ])->assertCreated()->json('client_id');
}

function gatewayAuthorizationUrl(string $clientId): string
{
    $verifier = str_repeat('A', 64);

    return 'https://proxy.example.test/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
        'response_type' => 'code',
        'scope' => 'gitverse:access',
        'resource' => 'https://proxy.example.test/mcp',
        'state' => 'oauth-test',
        'code_challenge_method' => 'S256',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
    ]);
}

it('publishes MCP OAuth discovery and uses the Passport token endpoint', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()->assertJsonPath('resource', 'https://proxy.example.test/mcp');

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()->assertJsonPath('authorization_endpoint', 'https://proxy.example.test/oauth/authorize')
        ->assertJsonPath('code_challenge_methods_supported.0', 'S256');

    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code'])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_target');
});

it('creates public Passport clients for multiple remote AI services', function (): void {
    $first = registerGatewayClient($this);
    $second = registerGatewayClient($this, 'https://claude.ai/api/mcp/auth_callback');

    expect(Str::isUuid($first))->toBeTrue()->and($first)->not->toBe($second);
    $client = Passport::client()->newQuery()->findOrFail($first);
    expect($client->confidential())->toBeFalse()
        ->and($client->hasGrantType('authorization_code'))->toBeTrue();
});

it('registers local loopback callbacks for MCP Inspector', function (): void {
    foreach ([
        'http://localhost:6274/oauth/callback',
        'http://127.0.0.1:6276/oauth/callback',
        'http://[::1]:6274/oauth/callback',
    ] as $callback) {
        $clientId = registerGatewayClient($this, $callback);
        $client = Passport::client()->newQuery()->findOrFail($clientId);

        expect($client->redirect_uris)->toContain($callback);
    }
});

it('rejects unsafe callback addresses', function (): void {
    foreach ([
        'http://example.com/callback',
        'http://localhost.evil.example/oauth/callback',
        'http://127.0.0.2:6274/oauth/callback',
        'http://[::2]:6274/oauth/callback',
        'http://localhost:6274/oauth/callback#fragment',
        'http://evil.example@localhost:6274/oauth/callback',
        'https://localhost/callback',
        'https://127.0.0.1/callback',
        'https://bad.example@localhost/callback',
        'https://cloud.example/path#fragment',
        'https://cloud.example:8443/callback',
    ] as $url) {
        $this->postJson('/oauth/register', ['redirect_uris' => [$url]])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    }
});

it('migrates only GitVerse credentials and Passport OAuth tables, not the prototype tables', function (): void {
    foreach (['oauth_clients', 'oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'mcp_gitverse_credentials'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    foreach (['mcp_oauth_clients', 'mcp_oauth_codes', 'mcp_oauth_tokens', 'personal_access_tokens'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('requires a pending OAuth request to enter a GitVerse credential', function (): void {
    $this->get('/connect')->assertForbidden();
    $this->post('/connect', ['gitverse_token' => 'test-secret-token'])->assertForbidden();
});

it('saves GitVerse PAT encrypted and associated with a UUID user', function (): void {
    $client = registerGatewayClient($this);
    $oauthUrl = gatewayAuthorizationUrl($client);

    Http::fake(['https://api.gitverse.ru/user' => Http::response([
        'id' => 42, 'login' => 'test-user',
    ], 200)]);

    $this->withSession(['url.intended' => $oauthUrl])
        ->get('https://proxy.example.test/connect')->assertOk();

    $this->post('https://proxy.example.test/connect', [
        'gitverse_token' => 'test-gitverse-secret',
    ])->assertRedirect($oauthUrl);

    $credential = GitVerseCredential::query()->sole();
    $user = User::query()->sole();
    expect(Str::isUuid($credential->id))->toBeTrue()
        ->and($credential->user_id)->toBe($user->id)
        ->and($credential->encrypted_token)->toBe('test-gitverse-secret')
        ->and($credential->getRawOriginal('encrypted_token'))->not->toContain('test-gitverse-secret');
});

it('rejects an invalid GitVerse PAT with a specific message', function (): void {
    $client = registerGatewayClient($this);
    Http::fake(['https://api.gitverse.ru/user' => Http::response(['error' => 'Unauthorized'], 401)]);

    $this->withSession(['url.intended' => gatewayAuthorizationUrl($client)])
        ->post('https://proxy.example.test/connect', ['gitverse_token' => 'invalid-gitverse-token'])
        ->assertSessionHasErrors(['gitverse_token' => 'GitVerse отклонил токен. Проверь его и попробуй снова.']);

    expect(User::query()->count())->toBe(0);
});

it('validates GitVerse tokens through the authenticated user API instead of MCP initialize', function (): void {
    $client = registerGatewayClient($this);

    Http::fake(['https://api.gitverse.ru/user' => Http::response([
        'id' => 42, 'login' => 'test-user',
    ])]);

    $this->withSession(['url.intended' => gatewayAuthorizationUrl($client)])
        ->post('https://proxy.example.test/connect', [
            'gitverse_token' => 'test-gitverse-secret',
        ])->assertRedirect();

    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.gitverse.ru/user'
        && $request->hasHeader('Authorization', 'Bearer test-gitverse-secret')
        && $request->hasHeader('Accept', 'application/vnd.gitverse.object+json;version=1'));
});

it('distinguishes GitVerse token permissions from service errors', function (): void {
    $client = registerGatewayClient($this);

    foreach ([
        403 => 'У токена нет права на чтение данных пользователя GitVerse.',
        429 => 'GitVerse ограничил число запросов. Попробуй позже.',
        503 => 'Не удалось проверить токен: GitVerse API вернул HTTP 503.',
    ] as $status => $message) {
        Http::fake(['https://api.gitverse.ru/user' => Http::response(['error' => 'upstream details'], $status)]);

        $this->withSession(['url.intended' => gatewayAuthorizationUrl($client)])
            ->post('https://proxy.example.test/connect', [
                'gitverse_token' => 'test-gitverse-secret',
            ])->assertSessionHasErrors(['gitverse_token' => $message]);

        expect(User::query()->count())->toBe(0);
    }
});

it('rejects a malformed successful GitVerse API response without storing a PAT', function (): void {
    $client = registerGatewayClient($this);

    Http::fake(['https://api.gitverse.ru/user' => Http::response(['invalid' => true], 200)]);

    $this->withSession(['url.intended' => gatewayAuthorizationUrl($client)])
        ->post('https://proxy.example.test/connect', [
            'gitverse_token' => 'test-gitverse-secret',
        ])->assertSessionHasErrors(['gitverse_token' => 'GitVerse API вернул некорректный ответ. Попробуй позже.']);

    expect(User::query()->count())->toBe(0);
});

it('handles GitVerse API connection failures without storing the PAT', function (): void {
    $client = registerGatewayClient($this);

    Http::fake([
        'https://api.gitverse.ru/user' => static function (): never {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out.');
        },
    ]);

    $this->withSession(['url.intended' => gatewayAuthorizationUrl($client)])
        ->post('https://proxy.example.test/connect', [
            'gitverse_token' => 'test-gitverse-secret',
        ])->assertSessionHasErrors(['gitverse_token' => 'Не удалось связаться с GitVerse API. Попробуй позже.']);

    expect(User::query()->count())->toBe(0);
});

it('does not expose a CSRF prop and follows an Inertia OAuth approval back to the client', function (): void {
    $this->artisan('passport:keys', ['--force' => true])->assertExitCode(0);

    $clientId = registerGatewayClient($this);
    $oauthUrl = gatewayAuthorizationUrl($clientId);

    $this->get($oauthUrl)->assertRedirect('https://proxy.example.test/connect');

    Http::fake(['https://api.gitverse.ru/user' => Http::response([
        'id' => 42, 'login' => 'test-user',
    ], 200)]);

    $connect = $this->get('https://proxy.example.test/connect')->assertOk();
    preg_match('/data-page="([^"]+)"/', $connect->getContent(), $connectMatches);

    $connectPage = json_decode(html_entity_decode($connectMatches[1], ENT_QUOTES), true);
    expect($connectPage['props'])->not->toHaveKey('csrfToken');

    $this->post('https://proxy.example.test/connect', [
        'gitverse_token' => 'integration-secret',
    ])->assertRedirect($oauthUrl);

    $consent = $this->get($oauthUrl)->assertOk();
    preg_match('/data-page="([^"]+)"/', $consent->getContent(), $consentMatches);

    $consentPage = json_decode(html_entity_decode($consentMatches[1], ENT_QUOTES), true);
    expect($consentPage['props'])->not->toHaveKey('csrfToken')
        ->and($consentPage['props']['authToken'])->toBeString();

    $response = $this->post('https://proxy.example.test/oauth/authorize', [
        'auth_token' => $consentPage['props']['authToken'],
    ], [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);

    $response->assertStatus(409)
        ->assertHeader('X-Inertia-Location');

    $callback = (string) $response->headers->get('X-Inertia-Location');

    expect($callback)->toStartWith('https://chatgpt.com/connector_platform_oauth_redirect?');
    parse_str((string) parse_url($callback, PHP_URL_QUERY), $parameters);
    expect($parameters['state'])->toBe('oauth-test')
        ->and($parameters['code'])->toBeString();
});

it('challenges unauthenticated MCP requests with OAuth metadata', function (): void {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized()->assertHeader('WWW-Authenticate');
});

it('proxies the current Passport users credential, not another users credential', function (): void {
    $first = User::factory()->create();
    $first->gitverseCredential()->create(['fingerprint' => hash('sha256', 'first-token'), 'encrypted_token' => 'first-token']);
    $second = User::factory()->create();
    $second->gitverseCredential()->create(['fingerprint' => hash('sha256', 'second-token'), 'encrypted_token' => 'second-token']);

    Http::fake(['https://mcp.gitverse.ru*' => Http::response([
        'jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []],
    ], 200)]);

    Passport::actingAs($first, ['gitverse:access']);
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertOk();

    Passport::actingAs($second, ['gitverse:access']);
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertOk();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer first-token'));
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer second-token'));
});

it('rejects Passport users without the GitVerse scope', function (): void {
    $user = User::factory()->create();
    $user->gitverseCredential()->create(['fingerprint' => hash('sha256', 'scope-token'), 'encrypted_token' => 'scope-token']);

    Passport::actingAs($user, []);
    Http::fake();
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    Http::assertNothingSent();
});

it('completes the real Passport authorization code + PKCE flow', function (): void {
    $this->artisan('passport:keys', ['--force' => true])->assertExitCode(0);

    $clientId = registerGatewayClient($this);
    $oauthUrl = gatewayAuthorizationUrl($clientId);
    $callbackUri = 'https://chatgpt.com/connector_platform_oauth_redirect';

    $this->get($oauthUrl)->assertRedirect('https://proxy.example.test/connect');

    Http::fake(['https://api.gitverse.ru/user' => Http::response([
        'id' => 42, 'login' => 'test-user',
    ], 200)]);

    $this->get('https://proxy.example.test/connect')->assertOk();
    $redirect = $this->post('https://proxy.example.test/connect', [
        'gitverse_token' => 'integration-secret',
    ])->assertRedirect();

    $actualUrl = (string) $redirect->headers->get('Location');

    foreach ([PHP_URL_SCHEME, PHP_URL_HOST, PHP_URL_PATH] as $component) {
        expect(parse_url($actualUrl, $component))->toBe(parse_url($oauthUrl, $component));
    }

    parse_str((string) parse_url($actualUrl, PHP_URL_QUERY), $actualQuery);
    parse_str((string) parse_url($oauthUrl, PHP_URL_QUERY), $expectedQuery);

    ksort($actualQuery);
    ksort($expectedQuery);

    expect($actualQuery)->toBe($expectedQuery);

    $consent = $this->get($oauthUrl)->assertOk();
    preg_match('/data-page="([^"]+)"/', $consent->getContent(), $matches);
    expect($matches)->toHaveCount(2);

    $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);
    expect($page['component'])->toBe('Connect')
        ->and($page['props']['mode'])->toBe('consent')
        ->and($page['props']['authToken'])->toBeString();

    $approved = $this->post('https://proxy.example.test/oauth/authorize', [
        'auth_token' => $page['props']['authToken'],
    ])->assertRedirect();

    $location = (string) $approved->headers->get('Location');
    expect($location)->toStartWith($callbackUri);
    parse_str((string) parse_url($location, PHP_URL_QUERY), $values);
    expect($values['state'])->toBe('oauth-test');

    $request = [
        'client_id' => $clientId,
        'grant_type' => 'authorization_code',
        'code' => $values['code'],
        'redirect_uri' => $callbackUri,
        'code_verifier' => str_repeat('A', 64),
        'resource' => 'https://proxy.example.test/mcp',
    ];

    $issued = $this->post('https://proxy.example.test/oauth/token', $request)->assertOk();
    expect($issued->json('access_token'))->toBeString()
        ->and($issued->json('refresh_token'))->toBeString()
        ->and($issued->json('access_token'))->not->toBe('integration-secret');

    $this->post('https://proxy.example.test/oauth/token', $request)->assertStatus(400);
});
