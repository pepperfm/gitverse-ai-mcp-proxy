<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('gitverse:mcp-prune', function (): void {
    DB::table('mcp_oauth_codes')->where('expires_at', '<', now())->delete();
    DB::table('mcp_oauth_tokens')->where('expires_at', '<', now())->delete();

    DB::table('mcp_gitverse_credentials')
        ->whereNotIn('id', DB::table('mcp_oauth_codes')->select('credential_id'))
        ->whereNotIn('id', DB::table('mcp_oauth_tokens')->select('credential_id'))
        ->delete();

    $this->info('Expired OAuth records were pruned.');
})->purpose('Prune expired GitVerse MCP OAuth codes, tokens and credentials')->daily();

Artisan::command('gitverse:mcp-revoke', function (): void {
    DB::transaction(function (): void {
        DB::table('mcp_oauth_codes')->delete();
        DB::table('mcp_oauth_tokens')->delete();
        DB::table('mcp_gitverse_credentials')->delete();
    });

    $this->info('All active GitVerse MCP authorizations have been revoked.');
})->purpose('Immediately revoke all ChatGPT connections without deleting registered OAuth clients');
