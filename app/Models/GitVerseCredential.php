<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GitVerseCredential extends Model
{
    use HasUuids;

    protected $table = 'mcp_gitverse_credentials';

    protected $guarded = [];

    protected $hidden = ['encrypted_token', 'fingerprint'];

    protected function casts(): array
    {
        return ['encrypted_token' => 'encrypted'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
