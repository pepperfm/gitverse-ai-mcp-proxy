<?php

declare(strict_types=1);

return [
    'endpoint' => env('GITVERSE_MCP_URL', 'https://mcp.gitverse.ru'),
    'allowed_token_sha256' => env('GITVERSE_ALLOWED_TOKEN_SHA256', ''),
    'scope' => 'gitverse:access',
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('MCP_ALLOWED_ORIGINS', '')))),
];
