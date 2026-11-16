# GitVerse AI MCP Proxy

A small Laravel 13 / PHP 8.5 service connecting ChatGPT to the official GitVerse remote MCP server through OAuth authorization-code + PKCE.

## What it does

- Exposes an HTTPS MCP Streamable HTTP endpoint at /mcp.
- Advertises OAuth protected-resource and authorization-server metadata.
- Supports restricted ChatGPT Dynamic Client Registration (DCR), PKCE S256, short-lived access tokens and rotating refresh tokens.
- Shows **one Blade form** during the ChatGPT authorization flow to paste a GitVerse Personal Access Token (PAT).
- Verifies the PAT against the official GitVerse MCP, encrypts it at rest with Laravel APP_KEY, and proxies MCP traffic using that PAT. ChatGPT never receives the GitVerse PAT.
- Refuses other users' PATs unless their SHA-256 fingerprint matches the configured value. No registration system, Livewire, Nuxt, separate Redis service or GitHub Actions is required.

**Important:** No end-to-end ChatGPT integration has been verified yet. The custom OAuth server is security-sensitive; review and test it on an HTTPS staging domain before exposing production repositories. Configure the GitVerse PAT with the smallest possible permissions.

## Setup

Install PHP 8.5, Composer and a database driver (SQLite is enough for one user). Use an HTTPS reverse proxy and point the document root to Laravel's public directory.

1. Run composer install --no-dev --optimize-autoloader.
2. Copy .env.example to .env and configure APP_URL to the exact public https:// hostname (no trailing slash). Set APP_ENV=production and APP_DEBUG=false.
3. Run php artisan key:generate.
4. Create database/database.sqlite (or configure PostgreSQL). Make the database and Laravel storage writable by the application.
5. Generate the SHA-256 fingerprint of your existing GitVerse token **locally**. In a POSIX shell:
   - read -rs GITVERSE_TOKEN; export GITVERSE_TOKEN
   - php -r 'echo hash("sha256", getenv("GITVERSE_TOKEN")).PHP_EOL;'
   - unset GITVERSE_TOKEN
   Put only the printed 64-character hash in GITVERSE_ALLOWED_TOKEN_SHA256. Do **not** put the raw PAT in .env, GitHub or ChatGPT.
6. Run php artisan migrate --force, then php artisan optimize.
7. In ChatGPT → Plugins → Add custom MCP server, enter https://your-host/mcp, choose OAuth, and create the private plugin. The server advertises the DCR registration URL and authorization endpoints automatically.
8. On first connection, ChatGPT opens the Blade authorization screen. Paste your existing PAT, then approve. After redirecting, test a read-only GitVerse tool such as listing repositories.

For local tests use an in-memory SQLite test database and execute php artisan test --compact --filter=Mcp.

## Security model

DCR accepts **only** the official ChatGPT stable callback or callback-ID-specific URLs on chatgpt.com. Redirect URIs are checked again during authorization. The OAuth code expires after 5 minutes, is single-use and PKCE-bound; access tokens expire after 1 hour and refresh tokens after 30 days. OAuth token values are stored only as SHA-256 hashes. PATs are encrypted at rest with APP_KEY, and only PATs matching GITVERSE_ALLOWED_TOKEN_SHA256 can authorize or make MCP calls.

Changing GITVERSE_ALLOWED_TOKEN_SHA256 invalidates existing MCP access for the old PAT. To revoke ChatGPT access immediately run php artisan gitverse:mcp-revoke (keeps the registered OAuth clients) or revoke the PAT in GitVerse. Run php artisan schedule:run every minute via cron to prune expired codes, tokens and orphaned encrypted credentials daily, or run php artisan gitverse:mcp-prune manually. Rotate APP_KEY only with a migration plan: encrypted PATs would become unreadable.

Do not log request bodies, OAuth credentials, or Authorization headers. Terminate TLS at a trusted reverse proxy, limit ingress, keep the APP_KEY safe, and consider additional firewall/IP restrictions. Configure PHP-FPM and nginx to allow long-running streaming requests; ensure nginx does not buffer SSE (the app sets X-Accel-Buffering: no).

## Protocol

- GET /.well-known/oauth-protected-resource
- GET /.well-known/oauth-authorization-server
- POST /oauth/register
- GET + POST /oauth/authorize (session + CSRF-protected Blade form)
- POST /oauth/token
- GET + POST + DELETE /mcp

The proxy passes upstream MCP session/version/event headers and streams responses without reading SSE responses into memory. It does not implement the GitVerse tools itself.

Based on [OpenAI MCP OAuth guidance](https://developers.openai.com/plugins/build/auth), [MCP authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization), and [GitVerse MCP docs](https://gitverse.ru/docs/ai/mcp/).

No .github/CI directory is introduced.
