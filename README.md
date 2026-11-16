# GitVerse Remote MCP Gateway

A Laravel 13 / PHP 8.5 gateway that lets **cloud AI clients** connect to GitVerse's official remote MCP with each user's own GitVerse Personal Access Token (PAT).

The gateway uses **Laravel Passport 13** for OAuth2 (authorization codes, PKCE, tokens, refresh, client storage) and **Eloquent** for users and encrypted GitVerse credentials. Only the small MCP-specific discovery/DCR adapter and HTTP/SSE forwarding are project code.

## Connection flow

1. A person adds `https://your-domain.example/mcp` to ChatGPT or another remote MCP client that supports OAuth and Dynamic Client Registration.
2. The client registers a public OAuth client and starts Authorization Code + PKCE.
3. Laravel shows the **single Blade template** asking for the user's GitVerse PAT and shows the real OAuth callback domain.
4. The PAT is checked against the official GitVerse MCP, stored using Eloquent's `encrypted` cast, and linked to a local Laravel user. No email/password registration is required.
5. Passport displays a consent step using **the same Blade template**, then issues a scoped access token and a rotating refresh token to the AI client.
6. The gateway verifies the Passport token, identifies the Laravel user, loads **that user's credential**, and streams the MCP request/response to GitVerse.

The AI client never receives the actual GitVerse PAT. Each user's Passport tokens are scoped to their Laravel user and personal GitVerse credentials.

## Setup

Requirements: PHP 8.5, Composer, PostgreSQL 17, HTTPS reverse proxy.

1. Update dependencies and **regenerate composer.lock**: `composer update laravel/passport --with-all-dependencies` (or a full `composer update`, since Passport is new and Sanctum was removed). **The lockfile included in this commit was generated before Passport was added and must be regenerated before `composer install`.** Commit the new lockfile to master.
2. `cp .env.example .env`; set `APP_URL=https://your-domain.example`, PostgreSQL credentials, `APP_ENV=production`, `APP_DEBUG=false`.
3. `php artisan key:generate`.
4. `php artisan passport:keys`. Keep `storage/oauth-private.key` and `storage/oauth-public.key` private; do not commit them.
5. For a disposable development database, run `php artisan migrate:fresh` and `php artisan optimize`. This resets all data.
6. In ChatGPT Plugins, create a custom MCP connection pointing to `https://your-domain.example/mcp` and select OAuth.
7. Paste your personal GitVerse PAT on the connection page, then approve on the following consent screen. Test a read-only GitVerse tool first.

The project provides its own UUID-compatible Passport migrations. **Do not run `passport:install`, `install:api --passport`, or publish Passport migrations**, as that could introduce duplicate tables or migrations.

## Fresh development schema

There is intentionally **no historical conversion migration** while the project is in development. The retained migrations create users, jobs, GitVerse credentials and the Passport OAuth tables directly. Legacy `mcp_oauth_*` migrations and the unused Sanctum `personal_access_tokens` migration have been removed.

Reset **only your development/test database**:

```bash
php artisan migrate:fresh
php artisan test --compact --filter=Mcp
```

This destroys all data, including connected accounts and GitVerse PATs. Any cloud AI service must reconnect afterward. **Do not run migrate:fresh against a production database with actual users.**

## Security and compatibility

- Passport uses signed access tokens, refresh token rotation, OAuth client records, PKCE and consent. The MCP adapter enforces a single resource URI (`APP_URL/mcp`) for authorization and token requests. The gateway accepts only tokens issued by its Passport server and scoped to `gitverse:access`.
- DCR accepts public HTTPS callback domains and HTTP loopback callbacks (`localhost`, `127.0.0.1`, `[::1]`) for local clients such as MCP Inspector. Other HTTP callbacks, remote IP addresses, URL fragments and userinfo are rejected; Passport validates the exact registered callback. The client name is **unverified**, so the form displays the actual callback host.
- The login is one-time and tied to the pending OAuth authorization URL. OAuth approval logs out the browser session, preventing an unrelated next authorization from inheriting the previous user.
- GitVerse PATs are encrypted at rest with the Laravel application key. Use least-privilege PATs; guard the app key and OAuth signing keys.
- `php artisan gitverse:mcp-revoke` invalidates all Passport tokens and deletes the stored PATs. `php artisan gitverse:mcp-prune` uses Passport's native purge operation.
- The only web interface is `resources/views/gitverse-connect.blade.php`, reused for PAT entry and consent. No Livewire or Nuxt UI bundle is necessary.
- **CIMD (Client ID Metadata Documents) is not implemented**; DCR is supported. Clients requiring CIMD-only registration won't connect without additional work.
- **Production warning:** This is an unverified security-sensitive migration. Test the full OAuth browser round trip, token exchange, token refresh and SSE with a real cloud client on HTTPS before exposing it to the public.

## Endpoints

- `GET /.well-known/oauth-protected-resource`
- `GET /.well-known/oauth-protected-resource/mcp`
- `GET /.well-known/oauth-authorization-server`
- `POST /oauth/register` (DCR)
- `GET, POST /oauth/authorize` (Passport with gateway verification/consent)
- `POST /oauth/token` (Passport)
- `GET, POST /connect` (PAT login/verification)
- `GET, POST, DELETE /mcp` (Streamable HTTP proxy)

## Tests

Use a **separate PostgreSQL test database**, then run:

`php artisan test --compact --filter=Mcp`

No `.github` CI is introduced.

Relevant documentation: [Laravel Passport 13](https://laravel.com/docs/13.x/passport), [MCP OAuth authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization), [GitVerse MCP](https://gitverse.ru/docs/ai/mcp/).
