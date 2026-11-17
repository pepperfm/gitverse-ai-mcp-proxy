<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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

it('adds the local repository list tool without removing GitVerse tools or pagination', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response([
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'tools' => [
                ['name' => 'get_my_user_info', 'inputSchema' => ['type' => 'object']],
            ],
            'nextCursor' => 'next-page',
        ],
    ])]);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list_user_repositories')
        ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true)
        ->assertJsonPath('result.tools.0.inputSchema.properties.per_page.maximum', 50)
        ->assertJsonPath('result.tools.1.name', 'get_my_user_info')
        ->assertJsonPath('result.nextCursor', 'next-page');

    Http::assertSentCount(1);
});

it('preserves empty JSON objects in upstream tool schemas', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response(
        '{"jsonrpc":"2.0","id":1,"result":{"tools":[{"name":"get_my_user_info","inputSchema":{"type":"object","properties":{}},"annotations":{}}]}}',
        200,
        ['Content-Type' => 'application/json'],
    )]);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list_user_repositories')
        ->assertJsonPath('result.tools.1.name', 'get_my_user_info');

    $upstream = json_decode($response->getContent())->result->tools[1];

    expect($upstream->inputSchema->properties)->toBeInstanceOf(stdClass::class)
        ->and($upstream->annotations)->toBeInstanceOf(stdClass::class);
});

it('does not repeat the local tool on later discovery pages', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response([
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => ['tools' => [['name' => 'get_repository', 'inputSchema' => ['type' => 'object']]]],
    ])]);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => ['cursor' => 'next-page'],
    ])->assertOk()
        ->assertJsonCount(1, 'result.tools')
        ->assertJsonPath('result.tools.0.name', 'get_repository');
});

it('does not duplicate a tool already published by the upstream', function (): void {
    Http::fake(['https://mcp.gitverse.ru*' => Http::response([
        'jsonrpc' => '2.0',
        'id' => 3,
        'result' => ['tools' => [['name' => 'list_user_repositories', 'inputSchema' => ['type' => 'object']]]],
    ])]);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/list',
    ])->assertOk()->assertJsonCount(1, 'result.tools');
});

it('lists private repositories through the current users GitVerse PAT', function (): void {
    Http::fake(['https://api.gitverse.ru/user/repos*' => Http::response([
        [
            'id' => 12,
            'full_name' => 'example/private-project',
            'private' => true,
            'archived' => false,
            'default_branch' => 'master',
        ],
    ])]);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'repos',
        'method' => 'tools/call',
        'params' => [
            'name' => 'list_user_repositories',
            'arguments' => ['page' => 2, 'per_page' => 25],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('id', 'repos')
        ->assertJsonPath('result.content.0.type', 'text');

    $repositories = json_decode($response->json('result.content.0.text'), true);

    expect($repositories['page'])->toBe(2)
        ->and($repositories['per_page'])->toBe(25)
        ->and($repositories['repositories'][0]['private'])->toBeTrue()
        ->and($repositories['repositories'][0]['full_name'])->toBe('example/private-project');

    Http::assertSentCount(1);
    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://api.gitverse.ru/user/repos?')
            && $request->hasHeader('Authorization', 'Bearer personal-gitverse-token')
            && $request->hasHeader('Accept', 'application/vnd.gitverse.object+json;version=1')
            && $query === ['page' => '2', 'per_page' => '25'];
    });
});

it('rejects invalid pagination without calling GitVerse', function (): void {
    Http::fake();

    foreach ([
        ['page' => 0],
        ['page' => '1'],
        ['per_page' => 51],
        ['per_page' => 0],
        ['other' => 1],
        'invalid',
    ] as $arguments) {
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_user_repositories',
                'arguments' => $arguments,
            ],
        ])->assertOk()->assertJsonPath('result.isError', true);
    }

    Http::assertNothingSent();
});

it('returns an MCP tool error without exposing upstream error bodies', function (): void {
    Http::fake(['https://api.gitverse.ru/user/repos*' => Http::response([
        'error' => 'secret upstream error detail',
    ], 403)]);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => 'tools/call',
        'params' => ['name' => 'list_user_repositories', 'arguments' => new stdClass],
    ]);

    $response->assertOk()->assertJsonPath('result.isError', true);
    expect($response->getContent())->not->toContain('secret upstream error detail');
});

it('requires Passport authorization before serving local tools', function (): void {
    Passport::actingAs(User::factory()->create(), []);
    Http::fake();

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list_user_repositories', 'arguments' => new stdClass],
    ])->assertUnauthorized();

    Http::assertNothingSent();
});
