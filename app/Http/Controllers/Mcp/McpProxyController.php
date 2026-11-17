<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class McpProxyController
{
    private const MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    public function handle(Request $request): Response
    {
        if (
            $request->hasHeader('Origin')
            && !in_array($request->header('Origin'), config('gitverse.allowed_origins', []), true)
        ) {
            return response('Origin not allowed', 403);
        }

        $user = auth('api')->user();
        $credential = $user?->gitverseCredential;

        if (!$user || !$credential || !$user->tokenCan((string) config('gitverse.scope'))) {
            return response()->json(['error' => 'unauthorized'], 401)->header(
                'WWW-Authenticate',
                'Bearer resource_metadata="' . OAuthMetadataController::base() . '/.well-known/oauth-protected-resource", scope="' . config('gitverse.scope') . '"',
            );
        }

        // Standalone GET/SSE subscriptions are optional in MCP Streamable HTTP.
        // Forwarding one to GitVerse can occupy the only PHP development-server
        // worker indefinitely, preventing subsequent tools/list POST requests.
        // Ordinary JSON-RPC POST requests (including tools/list and tools/call)
        // remain available and carry their own responses.
        if ($request->isMethod('get')) {
            return response('', 405)->header('Allow', 'POST, DELETE');
        }

        $body = $request->getContent();
        if (strlen($body) > 2 * 1024 * 1024) {
            return response('MCP request too large', 413);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $credential->encrypted_token,
            'Accept' => $request->header('Accept', 'application/json, text/event-stream'),
        ];

        foreach (['Content-Type', 'MCP-Protocol-Version', 'Mcp-Session-Id', 'Last-Event-ID'] as $header) {
            if ($request->hasHeader($header)) {
                $headers[$header] = $request->header($header);
            }
        }

        try {
            $options = [
                'stream' => true,
                'allow_redirects' => false,
                'connect_timeout' => 10,
                // GET is a long-lived MCP event stream; POST must finish promptly.
                'timeout' => $request->isMethod('get') ? 0 : 30,
            ];

            if (!$request->isMethod('get')) {
                $options['read_timeout'] = 15;
            }

            $upstream = Http::withHeaders($headers)->withOptions($options)->send(
                $request->method(),
                (string) config('gitverse.endpoint'),
                $request->isMethod('post') ? ['body' => $body] : [],
            );
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json(['error' => 'GitVerse MCP unavailable'], 502);
        }

        $stream = $upstream->toPsrResponse()->getBody();

        if (in_array($upstream->status(), [401, 403], true)) {
            $stream->close();

            return response()->json(['error' => 'GitVerse credential rejected'], 502);
        }

        $responseHeaders = [
            'Cache-Control' => 'no-cache, no-store',
        ];

        foreach (['Mcp-Session-Id', 'MCP-Protocol-Version', 'Retry-After'] as $header) {
            if ($upstream->header($header)) {
                $responseHeaders[$header] = $upstream->header($header);
            }
        }

        // A GET subscription is intentionally long-lived. POST responses are not:
        // the upstream may keep an SSE connection alive even after sending the
        // JSON-RPC result, which prevents ChatGPT from finishing tool discovery.
        if ($request->isMethod('get')) {
            return $this->streamResponse($stream, $upstream->status(), [
                ...$responseHeaders,
                'Content-Type' => $upstream->header('Content-Type') ?: 'text/event-stream',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $message = $request->isMethod('post') ? json_decode($body, true) : null;
        $isNotification = is_array($message) && !array_key_exists('id', $message);

        if ($request->isMethod('delete') || $upstream->status() === 202 || ($isNotification && $upstream->successful())) {
            $stream->close();

            return response('', $isNotification && $upstream->successful() ? 202 : $upstream->status(), $responseHeaders);
        }

        // Preserve non-successful HTTP responses, including 404 for an expired
        // MCP session (the client must initialize a new one).
        if (!$upstream->successful()) {
            return $this->streamResponse($stream, $upstream->status(), [
                ...$responseHeaders,
                'Content-Type' => $upstream->header('Content-Type') ?: 'application/json',
            ]);
        }

        $requestId = is_array($message) ? ($message['id'] ?? null) : null;

        try {
            $json = $this->readResponse(
                $stream,
                (string) $upstream->header('Content-Type'),
                $requestId,
            );
        } catch (Throwable $exception) {
            report($exception);
            $json = null;
        } finally {
            $stream->close();
        }

        if ($json === null) {
            return response()->json(['error' => 'GitVerse returned an incomplete MCP response'], 502);
        }

        return response($json, $upstream->status(), [
            ...$responseHeaders,
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Buffer one JSON-RPC response, not the entire lifetime of an SSE connection.
     * Tool discovery and ordinary tool calls can then complete as regular JSON.
     */
    private function readResponse(StreamInterface $stream, string $contentType, mixed $requestId): ?string
    {
        $sse = str_contains(strtolower($contentType), 'text/event-stream');
        $buffer = '';
        $deadline = microtime(true) + 25;

        while (!$stream->eof() && microtime(true) < $deadline) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                usleep(10000);

                continue;
            }

            $buffer .= $chunk;

            if (strlen($buffer) > self::MAX_RESPONSE_BYTES) {
                return null;
            }

            if (!$sse) {
                if (json_validate($buffer)) {
                    return $buffer;
                }

                continue;
            }

            while (preg_match('/\r?\n\r?\n/', $buffer, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $offset = $matches[0][1];
                $separator = $matches[0][0];
                $event = substr($buffer, 0, $offset);
                $buffer = substr($buffer, $offset + strlen($separator));

                $result = $this->jsonRpcEvent($event, $requestId);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Some servers omit the last blank line before closing an SSE response.
        return $sse ? $this->jsonRpcEvent($buffer, $requestId) : (json_validate($buffer) ? $buffer : null);
    }

    private function jsonRpcEvent(string $event, mixed $requestId): ?string
    {
        $lines = preg_split('/\r?\n/', $event) ?: [];
        $data = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = ltrim(substr($line, 5), ' ');
            }
        }

        $json = implode("\n", $data);
        if (!json_validate($json)) {
            return null;
        }

        $message = json_decode($json, true);

        return is_array($message) && array_key_exists('id', $message) && $message['id'] === $requestId
            ? $json
            : null;
    }

    /**
     * GET SSE subscriptions remain transparent; only POST results are normalized.
     */
    private function streamResponse(StreamInterface $stream, int $status, array $headers): Response
    {
        return response()->stream(static function () use ($stream): void {
            try {
                while (!$stream->eof() && connection_aborted() === 0) {
                    echo $stream->read(8192);

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            } finally {
                $stream->close();
            }
        }, $status, $headers);
    }
}
