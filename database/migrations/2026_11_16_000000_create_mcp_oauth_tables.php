<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_oauth_clients', function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->string('name', 150);
            $table->jsonb('redirect_uris');
            $table->timestamps();
        });

        Schema::create('mcp_gitverse_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('fingerprint', 64)->unique();
            $table->text('encrypted_token');
            $table->timestamps();
        });

        Schema::create('mcp_oauth_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('code_hash', 64)->unique();
            $table->string('client_id', 80);
            $table->foreign('client_id')->references('id')->on('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreignUuid('credential_id')->constrained('mcp_gitverse_credentials')->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->string('resource');
            $table->string('scope');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_oauth_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('token_hash', 64)->unique();
            $table->string('client_id', 80);
            $table->foreign('client_id')->references('id')->on('mcp_oauth_clients')->cascadeOnDelete();
            $table->foreignUuid('credential_id')->constrained('mcp_gitverse_credentials')->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('resource');
            $table->string('scope');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'kind', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_tokens');
        Schema::dropIfExists('mcp_oauth_codes');
        Schema::dropIfExists('mcp_gitverse_credentials');
        Schema::dropIfExists('mcp_oauth_clients');
    }
};
