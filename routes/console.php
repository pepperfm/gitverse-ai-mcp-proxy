<?php

declare(strict_types=1);

use App\Models\GitVerseCredential;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

Artisan::command('gitverse:mcp-prune', function (): void {
    Artisan::call('passport:purge', ['--expired' => true, '--revoked' => true]);
    $this->info('Expired and revoked Passport tokens were pruned.');
})->purpose('Prune expired Passport OAuth grants')->daily();

Artisan::command('gitverse:mcp-revoke', function (): void {
    Passport::token()->newQuery()->update(['revoked' => true]);
    Passport::refreshToken()->newQuery()->update(['revoked' => true]);
    GitVerseCredential::query()->delete();

    $this->info('All gateway OAuth tokens were revoked and GitVerse credentials erased.');
})->purpose('Emergency revoke all MCP authorizations');
