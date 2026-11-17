<?php

declare(strict_types=1);

return [
    'endpoint' => env('GITVERSE_MCP_URL', 'https://mcp.gitverse.ru'),
    'scope' => 'gitverse:access',
    'initialize_diagnostics' => (bool) env('MCP_INITIALIZE_DIAGNOSTICS', false),
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('MCP_ALLOWED_ORIGINS', '')))),
];
