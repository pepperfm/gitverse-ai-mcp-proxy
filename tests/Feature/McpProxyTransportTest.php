<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.url', 'https://proxy.example.test');
    Config::set('gitverse.endpoint', 'https://mcp.gitverse.ru');

    $user = User::factory()->create();
    $user->gitverseCredential()->create([
        'fingerprint' => hash('sha256', 'personal-gitverse-token'),
        'encrypted_token' => 'personal-gitverse-token',
    ]);

    Passport::actingAs($user, ['gitverse:access']);
});

it('rejects optional GET subscriptions without blocking subsequent tools/list POST requests', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response(
        '{"jsonrpc":"2.0","id":5,"result":{"tools":[{"name":"get_my_user_info","inputSchema":{"type":"object"}}]}}',
        200,
        ['Content-Type' => 'application/json'],
    )]);

    $this->get('/mcp', ['Accept' => 'text/event-stream'])
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST, DELETE')
        ->assertContent('');

    Http::assertNothingSent();

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 5,
        'method' => 'tools/list',
    ])->assertOk()->assertJsonPath('result.tools.0.name', 'get_my_user_info');

    Http::assertSentCount(1);
});

it('finishes initialize as JSON and preserves the upstream session header', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response(
        '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-11-25","capabilities":{"logging":{},"tools":{}},"serverInfo":{"name":"GitVerse","version":"1"}}}',
        200,
        ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'upstream-session'],
    )]);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new stdClass,
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ],
    ]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Mcp-Session-Id', 'upstream-session')
        ->assertJsonPath('result.protocolVersion', '2025-11-25')
        ->assertJsonPath('result.serverInfo.name', 'GitVerse')
        ->assertJsonPath('result.serverInfo.version', '1')
        ->assertJsonPath('result.serverInfo.websiteUrl', 'https://proxy.example.test');

    $result = json_decode($response->getContent())->result;
    expect($result->capabilities->logging)->toBeInstanceOf(stdClass::class)
        ->and($result->capabilities->tools)->toBeInstanceOf(stdClass::class);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer personal-gitverse-token'));
});

it('logs initialize transport and JSON shapes without recording secrets', function (): void {
    Config::set('gitverse.initialize_diagnostics', true);

    $entries = [];
    Log::shouldReceive('warning')->times(4)->andReturnUsing(function (string $message, array $context) use (&$entries): void {
        $entries[$message] = $context;
    });

    Http::fake(['https://mcp.gitverse.ru*' => Http::response(
        '{"jsonrpc":"2.0","id":"private-rpc-id","result":{"protocolVersion":"2025-11-25","capabilities":{"logging":{},"tools":{"listChanged":true}},"serverInfo":{"name":"private-server-name","version":"private-server-version"}}}',
        200,
        ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'private-session-id'],
    )]);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'private-rpc-id',
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new stdClass,
            'clientInfo' => ['name' => 'private-client-name', 'version' => '1'],
        ],
    ])->assertOk();

    expect($entries['MCP initialize upstream']['status'])->toBe(200)
        ->and($entries['MCP initialize upstream']['has_session_id'])->toBeTrue()
        ->and($entries['MCP initialize upstream_body']['logging_type'])->toBe('stdClass')
        ->and($entries['MCP initialize response']['logging_type'])->toBe('stdClass')
        ->and($entries['MCP initialize response']['website_url_present'])->toBeTrue()
        ->and($entries['MCP initialize response']['id_matches'])->toBeTrue()
        ->and(array_unique(array_column($entries, 'trace_id')))->toHaveCount(1);

    $logged = json_encode($entries, JSON_THROW_ON_ERROR);

    foreach (['personal-gitverse-token', 'private-session-id', 'private-rpc-id', 'private-client-name', 'private-server-name', 'private-server-version'] as $secret) {
        expect($logged)->not->toContain($secret);
    }
});

it('does not log initialize diagnostics unless enabled', function (): void {
    Config::set('gitverse.initialize_diagnostics', false);
    Log::shouldReceive('warning')->never();

    Http::fake(['https://mcp.gitverse.ru*' => Http::response(
        '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-11-25","capabilities":{},"serverInfo":{"name":"GitVerse","version":"dev"}}}',
        200,
        ['Content-Type' => 'application/json'],
    )]);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
    ])->assertOk();
});

it('finishes SSE tools/list at the matching JSON-RPC result rather than streaming forever', function (): void {
    $sse = ": keepalive\n\n"
        . "data: {\"jsonrpc\":\"2.0\",\"id\":99,\"result\":{\"tools\":[]}}\n\n"
        . "event: message\n"
        . "data: {\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{\"tools\":[{\"name\":\"get_my_user_info\",\"description\":\"Current user\",\"inputSchema\":{\"type\":\"object\"}}]}}\n\n"
        . ": trailing keepalive\n\n";

    Http::fake(['https://mcp.gitverse.ru*' => Http::response(
        $sse,
        200,
        ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'upstream-session'],
    )]);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
    ], [
        'Mcp-Session-Id' => 'upstream-session',
        'MCP-Protocol-Version' => '2025-11-25',
        'Accept' => 'application/json, text/event-stream',
    ]);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Mcp-Session-Id', 'upstream-session')
        ->assertJsonPath('id', 2)
        ->assertJsonPath('result.tools.0.name', 'get_my_user_info');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Mcp-Session-Id', 'upstream-session'));
});

it('proxies individual tools/call requests with the users own PAT', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response(
        '{"jsonrpc":"2.0","id":"user-info","result":{"content":[{"type":"text","text":"{\"Result\":{\"login\":\"example\"}}"}]}}',
        200,
        ['Content-Type' => 'application/json'],
    )]);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'user-info',
        'method' => 'tools/call',
        'params' => ['name' => 'get_my_user_info', 'arguments' => new stdClass],
    ])->assertOk()->assertJsonPath('result.content.0.type', 'text');

    Http::assertSent(function ($request): bool {
        $payload = json_decode($request->body(), true);

        return $request->hasHeader('Authorization', 'Bearer personal-gitverse-token')
            && ($payload['method'] ?? null) === 'tools/call'
            && ($payload['params']['name'] ?? null) === 'get_my_user_info';
    });
});

it('acknowledges MCP notifications without opening a response stream', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response('', 202)]);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'notifications/initialized',
        'params' => new stdClass,
    ])->assertStatus(202)->assertContent('');
});

it('reports malformed successful upstream JSON instead of an endless stream', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response(
        'invalid JSON',
        200,
        ['Content-Type' => 'application/json'],
    )]);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/list',
    ])->assertStatus(502)->assertJsonPath('error', 'GitVerse returned an incomplete MCP response');
});
