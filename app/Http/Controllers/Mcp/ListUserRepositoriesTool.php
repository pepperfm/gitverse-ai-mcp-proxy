<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ListUserRepositoriesTool
{
    public const NAME = 'list_user_repositories';

    private const API_URL = 'https://api.gitverse.ru/user/repos';

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'List repositories owned by the authenticated GitVerse user, including private and archived repositories. Use page and per_page to paginate.',
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'page' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'default' => 1,
                        'description' => 'Page number.',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 30,
                        'description' => 'Number of repositories per page (maximum 50).',
                    ],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    public static function appendToToolsList(string $json): string
    {
        $response = json_decode($json, true);

        if (!is_array($response) || !is_array($response['result']['tools'] ?? null)) {
            return $json;
        }

        foreach ($response['result']['tools'] as $tool) {
            if (is_array($tool) && ($tool['name'] ?? null) === self::NAME) {
                return $json;
            }
        }

        $response['result']['tools'][] = self::definition();

        return json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(mixed $arguments, string $personalToken): array
    {
        if (!is_array($arguments) || array_diff(array_keys($arguments), ['page', 'per_page'])) {
            return $this->error('Invalid repository list arguments.');
        }

        $page = $arguments['page'] ?? 1;
        $perPage = $arguments['per_page'] ?? 30;

        if (!is_int($page) || $page < 1 || !is_int($perPage) || $perPage < 1 || $perPage > 50) {
            return $this->error('page must be a positive integer and per_page must be an integer between 1 and 50.');
        }

        try {
            $response = Http::withToken($personalToken)
                ->accept('application/vnd.gitverse.object+json;version=1')
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(10)
                ->timeout(20)
                ->get(self::API_URL, [
                    'page' => $page,
                    'per_page' => $perPage,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return $this->error('GitVerse API is unavailable.');
        }

        if (!$response->successful()) {
            return $this->error('GitVerse API returned HTTP ' . $response->status() . '. Check your token permissions or try again.');
        }

        $repositories = $response->json();

        if (!is_array($repositories) || !array_is_list($repositories)) {
            return $this->error('GitVerse API returned an invalid repository list.');
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode([
                        'page' => $page,
                        'per_page' => $perPage,
                        'repositories' => $repositories,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(string $message): array
    {
        return [
            'isError' => true,
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
        ];
    }
}
