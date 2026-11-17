<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class McpInitializeDiagnostics
{
    private function __construct(
        private readonly string $traceId,
        private readonly float $startedAt,
        private readonly mixed $requestId,
    ) {}

    public static function start(Request $request): ?self
    {
        if (!config('gitverse.initialize_diagnostics') || !$request->isMethod('post')) {
            return null;
        }

        $body = $request->getContent();
        if (strlen($body) > 2 * 1024 * 1024) {
            return null;
        }

        $message = json_decode($body);

        if (!$message instanceof stdClass || ($message->method ?? null) !== 'initialize') {
            return null;
        }

        $diagnostics = new self((string) Str::uuid(), microtime(true), $message->id ?? null);
        $diagnostics->write('request', [
            'protocol_version' => self::protocolVersion($message->params->protocolVersion ?? null),
            'capabilities_type' => get_debug_type($message->params->capabilities ?? null),
            'client_info_type' => get_debug_type($message->params->clientInfo ?? null),
            'request_id_type' => get_debug_type($message->id ?? null),
            'content_type' => self::mediaType((string) $request->header('Content-Type')),
            'has_authorization' => $request->hasHeader('Authorization'),
            'has_session_id' => $request->hasHeader('Mcp-Session-Id'),
            'has_origin' => $request->hasHeader('Origin'),
        ]);

        return $diagnostics;
    }

    public function upstream(int $status, string $contentType, bool $hasSessionId): void
    {
        $this->write('upstream', [
            'status' => $status,
            'content_type' => self::mediaType($contentType),
            'has_session_id' => $hasSessionId,
        ]);
    }

    public function upstreamBody(?string $body): void
    {
        $this->write('upstream_body', $this->describeBody($body));
    }

    public function finished(Response $response): void
    {
        $body = $response->getContent();

        $this->write('response', [
            'status' => $response->getStatusCode(),
            'content_type' => self::mediaType((string) $response->headers->get('Content-Type')),
            'has_session_id' => $response->headers->has('Mcp-Session-Id'),
            ...$this->describeBody(is_string($body) ? $body : null),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->write('exception', [
            'exception' => get_class($exception),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeBody(?string $body): array
    {
        $message = $body === null ? null : json_decode($body);
        $result = $message->result ?? null;
        $capabilities = $result->capabilities ?? null;
        $serverInfo = $result->serverInfo ?? null;
        $error = $message->error ?? null;

        return [
            'body_bytes' => $body === null ? null : strlen($body),
            'json_object' => $message instanceof stdClass,
            'jsonrpc_2_0' => ($message->jsonrpc ?? null) === '2.0',
            'id_matches' => $message instanceof stdClass && property_exists($message, 'id') && $message->id === $this->requestId,
            'result_type' => get_debug_type($result),
            'error_type' => get_debug_type($error),
            'error_code' => is_int($error->code ?? null) ? $error->code : null,
            'protocol_version' => self::protocolVersion($result->protocolVersion ?? null),
            'capabilities_type' => get_debug_type($capabilities),
            'logging_type' => get_debug_type($capabilities->logging ?? null),
            'tools_type' => get_debug_type($capabilities->tools ?? null),
            'server_info_type' => get_debug_type($serverInfo),
            'server_name_present' => is_string($serverInfo->name ?? null),
            'server_version_present' => is_string($serverInfo->version ?? null),
            'website_url_present' => is_string($serverInfo->websiteUrl ?? null),
        ];
    }

    private static function protocolVersion(mixed $version): ?string
    {
        return is_string($version) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $version) === 1 ? $version : null;
    }

    private static function mediaType(string $value): string
    {
        return match (strtolower(trim(explode(';', $value, 2)[0]))) {
            'application/json' => 'application/json',
            'text/event-stream' => 'text/event-stream',
            'text/html' => 'text/html',
            '' => 'missing',
            default => 'other',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $stage, array $context): void
    {
        Log::warning('MCP initialize '.$stage, [
            'trace_id' => $this->traceId,
            'elapsed_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
            ...$context,
        ]);
    }
}
