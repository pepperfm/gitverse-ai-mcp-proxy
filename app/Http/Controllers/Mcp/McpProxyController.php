<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

final class McpProxyController
{
    public function handle(Request $request): Response
    {
        if ($request->hasHeader('Origin') && ! in_array($request->header('Origin'), config('gitverse.allowed_origins', []), true)) {
            return response('Origin not allowed', 403);
        }

        $bearer = $request->bearerToken();
        $row = $bearer ? DB::table('mcp_oauth_tokens')
            ->join('mcp_gitverse_credentials', 'mcp_gitverse_credentials.id', '=', 'mcp_oauth_tokens.credential_id')
            ->where('mcp_oauth_tokens.token_hash', hash('sha256', $bearer))
            ->where('mcp_oauth_tokens.kind', 'access')
            ->where('mcp_oauth_tokens.resource', OAuthController::resource())
            ->where('mcp_oauth_tokens.scope', config('gitverse.scope'))
            ->whereNull('mcp_oauth_tokens.revoked_at')
            ->where('mcp_oauth_tokens.expires_at', '>', now())
            ->select('mcp_gitverse_credentials.encrypted_token')
            ->first() : null;

        if (! $row) {
            return response()->json(['error' => 'unauthorized'], 401)
                ->header('WWW-Authenticate', 'Bearer resource_metadata="'.OAuthController::base().'/.well-known/oauth-protected-resource", scope="'.config('gitverse.scope').'"');
        }

        $rawBody = $request->getContent();

        if (strlen($rawBody) > 2 * 1024 * 1024) {
            return response('MCP request too large', 413);
        }

        $headers = [
            'Authorization' => 'Bearer '.Crypt::decryptString($row->encrypted_token),
            'Accept' => $request->header('Accept', 'application/json, text/event-stream'),
        ];

        foreach (['Content-Type', 'MCP-Protocol-Version', 'Mcp-Session-Id', 'Last-Event-ID'] as $header) {
            if ($request->hasHeader($header)) {
                $headers[$header] = $request->header($header);
            }
        }

        try {
            $options = $request->isMethod('post') ? ['body' => $rawBody] : [];

            $upstream = Http::withHeaders($headers)->withOptions([
                'stream' => true,
                'allow_redirects' => false,
                'connect_timeout' => 10,
                'timeout' => 0,
            ])->send($request->method(), (string) config('gitverse.endpoint'), $options);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json(['error' => 'GitVerse MCP unavailable'], 502);
        }

        if (in_array($upstream->status(), [401, 403], true)) {
            $upstream->toPsrResponse()->getBody()->close();

            return response()->json(['error' => 'GitVerse credential rejected'], 502);
        }

        $responseHeaders = [
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Content-Type' => $upstream->header('Content-Type') ?: 'application/json',
        ];

        foreach (['Mcp-Session-Id', 'MCP-Protocol-Version', 'Retry-After'] as $header) {
            if ($upstream->header($header)) {
                $responseHeaders[$header] = $upstream->header($header);
            }
        }

        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(static function () use ($body): void {
            try {
                while (! $body->eof() && connection_aborted() === 0) {
                    echo $body->read(8192);

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            } finally {
                $body->close();
            }
        }, $upstream->status(), $responseHeaders);
    }
}
