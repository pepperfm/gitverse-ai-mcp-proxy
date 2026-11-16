<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

final class McpProxyController
{
    public function handle(Request $request): Response
    {
        if ($request->hasHeader('Origin')
            && ! in_array($request->header('Origin'), config('gitverse.allowed_origins', []), true)) {
            return response('Origin not allowed', 403);
        }

        /** @var User|null $user */
        $user = Auth::guard('api')->user();
        $credential = $user?->gitverseCredential;

        if (! $user || ! $user->tokenCan((string) config('gitverse.scope')) || ! $credential) {
            return response()->json(['error' => 'unauthorized'], 401)->header(
                'WWW-Authenticate',
                'Bearer resource_metadata="'.OAuthMetadataController::base().'/.well-known/oauth-protected-resource", scope="'.config('gitverse.scope').'"',
            );
        }

        $body = $request->getContent();

        if (strlen($body) > 2 * 1024 * 1024) {
            return response('MCP request too large', 413);
        }

        $headers = [
            'Authorization' => 'Bearer '.$credential->encrypted_token,
            'Accept' => $request->header('Accept', 'application/json, text/event-stream'),
        ];

        foreach (['Content-Type', 'MCP-Protocol-Version', 'Mcp-Session-Id', 'Last-Event-ID'] as $header) {
            if ($request->hasHeader($header)) {
                $headers[$header] = $request->header($header);
            }
        }

        try {
            $upstream = Http::withHeaders($headers)->withOptions([
                'stream' => true,
                'allow_redirects' => false,
                'connect_timeout' => 10,
                'timeout' => 0,
            ])->send(
                $request->method(),
                (string) config('gitverse.endpoint'),
                $request->isMethod('post') ? ['body' => $body] : [],
            );
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

        $stream = $upstream->toPsrResponse()->getBody();

        return response()->stream(static function () use ($stream): void {
            try {
                while (! $stream->eof() && connection_aborted() === 0) {
                    echo $stream->read(8192);

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            } finally {
                $stream->close();
            }
        }, $upstream->status(), $responseHeaders);
    }
}
