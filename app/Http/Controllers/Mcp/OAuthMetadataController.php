<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;

final class OAuthMetadataController
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

    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => self::base(),
            'authorization_endpoint' => self::base() . '/oauth/authorize',
            'token_endpoint' => self::base() . '/oauth/token',
            'registration_endpoint' => self::base() . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [config('gitverse.scope')],
        ]);
    }

    public function register(Request $request, ClientRepository $clients): JsonResponse
    {
        $uris = $request->input('redirect_uris');
        $name = $request->input('client_name', 'Remote MCP client');
        $grants = $request->input('grant_types', ['authorization_code', 'refresh_token']);
        $responses = $request->input('response_types', ['code']);

        if (!is_array($uris) || count($uris) < 1 || count($uris) > 5) {
            return $this->badRequest('invalid_redirect_uri');
        }
        if (array_any($uris, fn($uri) => !is_string($uri) || !$this->validRedirectUri($uri))) {
            return $this->badRequest('invalid_redirect_uri');
        }

        if (
            !is_string($name)
            || !is_array($grants)
            || !is_array($responses)
            || !in_array('code', $responses, true)
            || trim($name) === ''
            || strlen($name) > 150
            || !in_array('authorization_code', $grants, true)
            || array_diff($responses, ['code'])
            || $request->input('token_endpoint_auth_method', 'none') !== 'none'
            || array_diff($grants, ['authorization_code', 'refresh_token'])
            || count(array_filter($grants, 'is_string')) !== count($grants)
            || count(array_filter($responses, 'is_string')) !== count($responses)
        ) {
            return $this->badRequest('invalid_client_metadata');
        }

        $redirects = array_values(array_unique($uris));
        $client = $clients->createAuthorizationCodeGrantClient($name, $redirects, confidential: false);

        return response()->json([
            'client_id' => $client->getKey(),
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $redirects,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => config('gitverse.scope'),
        ], 201)->header('Cache-Control', 'no-store');
    }

    private function badRequest(string $error): JsonResponse
    {
        return response()
            ->json(['error' => $error], 400)
            ->header('Cache-Control', 'no-store');
    }

    private function validRedirectUri(string $uri): bool
    {
        if (strlen($uri) > 2048 || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($uri);

        if (
            !is_array($parts)
            || !isset($parts['host'], $parts['scheme'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        // OAuth callbacks for local MCP clients.
        if ($scheme === 'http' && in_array($host, [
            'localhost',
            '127.0.0.1',
            '[::1]',
        ], true)) {
            return true;
        }

        // Remote clients must use public HTTPS domains.
        return $scheme === 'https'
            && (!isset($parts['port']) || $parts['port'] === 443)
            && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
