<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class OAuthController
{
    public static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function resource(): string
    {
        return self::base() . '/mcp';
    }

    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => self::resource(),
            'authorization_servers' => [self::base()],
            'scopes_supported' => [config('gitverse.scope')],
        ]);
    }

    public function serverMetadata(): JsonResponse
    {
        return response()->json([
            'issuer' => self::base(),
            'authorization_endpoint' => self::base() . '/oauth/authorize',
            'token_endpoint' => self::base() . '/oauth/token',
            'registration_endpoint' => self::base() . '/oauth/register',
            'authorization_response_iss_parameter_supported' => true,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [config('gitverse.scope')],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');

        if (!is_array($uris) || count($uris) < 1 || count($uris) > 5) {
            return $this->error('invalid_redirect_uri');
        }

        foreach ($uris as $uri) {
            if (!is_string($uri) || !$this->allowedRedirectUri($uri)) {
                return $this->error('invalid_redirect_uri');
            }
        }

        $methods = $request->input('token_endpoint_auth_method', 'none');
        $grants = $request->input('grant_types', ['authorization_code', 'refresh_token']);
        $responses = $request->input('response_types', ['code']);

        if (
            $methods !== 'none'
            || !is_array($grants)
            || !is_array($responses)
            || array_diff($grants, ['authorization_code', 'refresh_token'])
            || array_diff($responses, ['code'])
            || !in_array('authorization_code', $grants, true)
            || !in_array('code', $responses, true)
            || count(array_filter($grants, 'is_string')) !== count($grants)
            || count(array_filter($responses, 'is_string')) !== count($responses)
        ) {
            return $this->error('invalid_client_metadata');
        }

        $name = $request->input('client_name', 'Remote MCP client');

        if (!is_string($name) || strlen($name) > 150 || trim($name) === '') {
            return $this->error('invalid_client_metadata');
        }

        $id = str()->random(48);

        DB::table('mcp_oauth_clients')->insert([
            'id' => $id,
            'name' => $name,
            'redirect_uris' => $uris
                |> array_unique(...)
                |> array_values(...)
                |> (static fn($x) => json_encode($x, JSON_THROW_ON_ERROR)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'client_id' => $id,
            'client_id_issued_at' => now()->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => array_values(array_unique($uris)),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => config('gitverse.scope'),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function authorize(Request $request): View
    {
        $clientId = $request->query('client_id');
        $redirect = $request->query('redirect_uri');
        $challenge = $request->query('code_challenge');
        $state = $request->query('state');
        $scope = $request->query('scope', config('gitverse.scope'));

        $client = is_string($clientId)
            ? DB::table('mcp_oauth_clients')->where('id', $clientId)->first()
            : null;

        if (
            !$client
            || !is_string($redirect)
            || !is_string($challenge)
            || !is_string($state)
            || !$this->allowedRedirectUri($redirect)
            || $request->query('response_type') !== 'code'
            || $request->query('code_challenge_method') !== 'S256'
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1
            || strlen($state) > 1024
            || $scope !== config('gitverse.scope')
            || !in_array($redirect, json_decode($client->redirect_uris, true, 512, JSON_THROW_ON_ERROR), true)
            || $request->query('resource') !== self::resource()
        ) {
            abort(400, 'Invalid OAuth authorization request');
        }

        $request->session()->put('mcp_oauth_pending', [
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_challenge' => $challenge,
            'scope' => $scope,
            'resource' => self::resource(),
            'state' => $state,
        ]);

        return view('gitverse-connect', [
            'clientName' => $client->name,
            'callbackHost' => parse_url($redirect, PHP_URL_HOST),
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('mcp_oauth_pending');
        if (!is_array($pending)) {
            abort(400, 'OAuth session expired. Restart the MCP connection.');
        }

        $request->validate([
            'gitverse_token' => ['required', 'string', 'min:12', 'max:8192'],
        ]);

        $gitverseToken = $request->str('gitverse_token')->value();
        $fingerprint = hash('sha256', $gitverseToken);
        try {
            $probe = Http::withToken($gitverseToken)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->withOptions(['allow_redirects' => false])
                ->timeout(15)
                ->post((string) config('gitverse.endpoint'), [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2025-03-26',
                        'capabilities' => new \stdClass,
                        'clientInfo' => ['name' => 'gitverse-ai-mcp-proxy', 'version' => '1.0.0'],
                    ],
                ]);
        } catch (ConnectionException) {
            return back()->withErrors([
                'gitverse_token' => 'GitVerse MCP недоступен. Повтори попытку позже.',
            ]);
        }

        if (!$probe->successful()) {
            return back()->withErrors([
                'gitverse_token' => 'GitVerse не принял токен или MCP вернул ошибку.',
            ]);
        }

        DB::table('mcp_gitverse_credentials')->updateOrInsert(
            ['fingerprint' => $fingerprint],
            [
                'encrypted_token' => Crypt::encryptString($gitverseToken),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $credentialId = DB::table('mcp_gitverse_credentials')
            ->where('fingerprint', $fingerprint)
            ->value('id');

        $code = str()->random(80);

        DB::table('mcp_oauth_codes')->insert([
            'code_hash' => hash('sha256', $code),
            'client_id' => $pending['client_id'],
            'credential_id' => $credentialId,
            'redirect_uri' => $pending['redirect_uri'],
            'code_challenge' => $pending['code_challenge'],
            'scope' => $pending['scope'],
            'resource' => $pending['resource'],
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request->session()->forget('mcp_oauth_pending');

        $query = http_build_query([
            'code' => $code,
            'state' => $pending['state'],
            'iss' => self::base(),
        ], '', '&', PHP_QUERY_RFC3986);

        $separator = str_contains($pending['redirect_uri'], '?') ? '&' : '?';

        return redirect()->away($pending['redirect_uri'] . $separator . $query)
            ->header('Cache-Control', 'no-store');
    }

    public function token(Request $request): JsonResponse
    {
        $grant = $request->input('grant_type');
        $clientId = $request->input('client_id');

        if (
            !is_string($clientId)
            || !DB::table('mcp_oauth_clients')->where('id', $clientId)->exists()
        ) {
            return $this->error('invalid_client', 401);
        }
        if ($request->input('resource') !== self::resource()) {
            return $this->error('invalid_target');
        }
        if ($grant === 'authorization_code') {
            return $this->exchangeCode($request, $clientId);
        }
        if ($grant === 'refresh_token') {
            return $this->refresh($request, $clientId);
        }

        return $this->error('unsupported_grant_type');
    }

    private function exchangeCode(Request $request, string $clientId): JsonResponse
    {
        $code = $request->input('code');
        $verifier = $request->input('code_verifier');
        $redirect = $request->input('redirect_uri');

        if (
            !is_string($code)
            || !is_string($verifier)
            || !is_string($redirect)
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1
        ) {
            return $this->error('invalid_grant');
        }

        $challenge = hash('sha256', $verifier, true)
            |> base64_encode(...)
            |> (static fn($x) => strtr($x, '+/', '-_'))
            |> (static fn($x) => rtrim($x, '='));

        $issued = DB::transaction(function () use ($code, $clientId, $redirect, $challenge): ?array {
            $grant = DB::table('mcp_oauth_codes')
                ->where('code_hash', hash('sha256', $code))
                ->lockForUpdate()
                ->first();

            if (
                !$grant
                || $grant->client_id !== $clientId
                || $grant->redirect_uri !== $redirect
                || $grant->consumed_at !== null
                || $grant->resource !== self::resource()
                || !hash_equals($grant->code_challenge, $challenge)
                || Carbon::parse($grant->expires_at)->isPast()
            ) {
                return null;
            }

            DB::table('mcp_oauth_codes')
                ->where('id', $grant->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            return $this->issuePair($clientId, $grant->credential_id, $grant->scope);
        });

        return $issued ? $this->tokenResponse($issued) : $this->error('invalid_grant');
    }

    private function refresh(Request $request, string $clientId): JsonResponse
    {
        $refreshToken = $request->input('refresh_token');

        if (!is_string($refreshToken) || $refreshToken === '') {
            return $this->error('invalid_grant');
        }

        $issued = DB::transaction(function () use ($refreshToken, $clientId): ?array {
            $token = DB::table('mcp_oauth_tokens')
                ->where('token_hash', hash('sha256', $refreshToken))
                ->lockForUpdate()
                ->first();

            if (
                !$token || $token->client_id !== $clientId
                || $token->kind !== 'refresh'
                || $token->revoked_at !== null
                || $token->resource !== self::resource()
                || $token->scope !== config('gitverse.scope')
                || Carbon::parse($token->expires_at)->isPast()
            ) {
                return null;
            }

            DB::table('mcp_oauth_tokens')
                ->where('id', $token->id)
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            return $this->issuePair($clientId, $token->credential_id, $token->scope);
        });

        return $issued ? $this->tokenResponse($issued) : $this->error('invalid_grant');
    }

    private function issuePair(string $clientId, int $credentialId, string $scope): array
    {
        $access = str()->random(80);
        $refresh = str()->random(80);
        $now = now();

        DB::table('mcp_oauth_tokens')->insert([
            [
                'token_hash' => hash('sha256', $access),
                'client_id' => $clientId,
                'credential_id' => $credentialId,
                'kind' => 'access',
                'resource' => self::resource(),
                'scope' => $scope,
                'expires_at' => $now->copy()->addHour(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'token_hash' => hash('sha256', $refresh),
                'client_id' => $clientId,
                'credential_id' => $credentialId,
                'kind' => 'refresh',
                'resource' => self::resource(),
                'scope' => $scope,
                'expires_at' => $now->copy()->addDays(30),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }

    private function tokenResponse(array $tokens): JsonResponse
    {
        return response()->json($tokens)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    private function error(string $error, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error], $status)->header('Cache-Control', 'no-store');
    }

    private function allowedRedirectUri(string $uri): bool
    {
        if (strlen($uri) > 2048 || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($uri);

        if (
            !is_array($parts)
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || strtolower($parts['scheme'] ?? '') !== 'https'
        ) {
            return false;
        }

        $host = strtolower($parts['host']);

        return str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
