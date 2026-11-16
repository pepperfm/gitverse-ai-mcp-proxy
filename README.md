# GitVerse Remote MCP Gateway

A Laravel 13 / PHP 8.5 gateway for **cloud-hosted AI clients** (ChatGPT, Claude Web, and other remote MCP clients) to connect to the official GitVerse MCP using **each user's own GitVerse Personal Access Token**.

**One public MCP URL:** `https://your-domain.example/mcp`. No client-side app, local agent or Livewire is required.

## How a connection works

1. A person adds the public MCP URL to an AI service that supports remote MCP with OAuth.
2. That service registers an OAuth public client using Dynamic Client Registration (DCR), or uses pre-registered credentials where supported.
3. The gateway shows its sole Blade page: the registered client name, **actual OAuth redirect domain**, and a PAT input.
4. The user enters **their own GitVerse PAT**. The gateway checks it with GitVerse MCP and stores it encrypted under Laravel's APP_KEY.
5. The gateway issues short-lived OAuth tokens **for that specific client and credential**. AI services never receive the GitVerse PAT.
6. MCP requests are authenticated at the gateway and forwarded to GitVerse using the PAT belonging to that connection.

Two people using different AI services are isolated: a ChatGPT session cannot access the GitVerse credential associated with someone else's Claude session. No local computer is involved in the MCP exchange.

The **private ChatGPT plugin** created for the developer is not automatically available to everyone. Other users can add the same published MCP URL as their own custom remote MCP connection in services that allow it.

## Setup

- Install PHP 8.5, Composer, a database driver (SQLite is sufficient to start), and HTTPS with a trusted reverse proxy.
- `composer install --no-dev --optimize-autoloader`
- Copy `.env.example` to `.env`, set `APP_URL=https://your-domain.example` (exact public HTTPS origin, no path or trailing slash), `APP_ENV=production`, and `APP_DEBUG=false`.
- `php artisan key:generate`
- Create `database/database.sqlite` or configure PostgreSQL, with writable database and storage directories.
- `php artisan migrate --force`, then `php artisan optimize`.
- Connect `https://your-domain.example/mcp` in ChatGPT Plugins or another cloud-based remote-MCP client and select OAuth with **automatic client registration**.
- On first connect, enter a GitVerse PAT on the authorization page. The AI client will receive only its gateway-issued access and refresh tokens.

**There is no shared `GITVERSE_TOKEN` or `GITVERSE_ALLOWED_TOKEN_SHA256` environment variable.** This is a multi-user service: each person supplies a different credential during consent.

## Security and limitations

- OAuth authorization code with PKCE S256, single-use 5-minute codes, one-hour access tokens, rotating 30-day refresh tokens, exact registered redirect URI matching and resource binding.
- Registration accepts callback URLs on **HTTPS public domain names** (not localhost, IPs, userinfo or URL fragments); client registration is rate limited. Clients supply their own names: **the display name is not verified**; users must check the actual callback domain shown on the consent page.
- User GitVerse PATs are encrypted at rest. OAuth tokens and authorization codes are only stored as SHA-256 hashes. Tokens are linked to the relevant registered client and credential.
- External clients should provide their own token; the gateway does not maintain GitVerse accounts, perform GitVerse OAuth login or share credentials between users.
- TLS is mandatory. Protect APP_KEY; do not log authorization headers, form token contents or request bodies. Use small-scope PATs. An exposed token can access everything its GitVerse permissions allow.
- `php artisan gitverse:mcp-revoke` revokes **all** connections (a global emergency operation). `php artisan gitverse:mcp-prune` cleans expired rows, or schedule it using Laravel Scheduler. **Per-user account management/revocation UI is not implemented**.
- DCR is provided for compatibility. Newer MCP clients may prefer Client ID Metadata Documents (CIMD); support for that flow is **not yet implemented**. Providers which require CIMD without DCR or manual registration will not connect.
- **Not yet validated end-to-end** against ChatGPT or Claude Web. Before a public multi-user launch, perform an OAuth security review, tests with actual cloud clients, traffic limits, failure recovery and a revocation workflow. The code should currently be regarded as an MVP, not as an audited identity service.

## Routes

- `GET /.well-known/oauth-protected-resource`
- `GET /.well-known/oauth-authorization-server`
- `POST /oauth/register`
- `GET, POST /oauth/authorize` (Blade authorization form)
- `POST /oauth/token`
- `GET, POST, DELETE /mcp` (Streamable HTTP proxy with SSE support)

## Testing

`php artisan test --compact --filter=Mcp`

The repository deliberately has no `.github` CI configuration.

References: [MCP authorization and client registration](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization), [ChatGPT custom MCP](https://developers.openai.com/api/docs/guides/custom-mcp-server), [Claude remote MCP](https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp), [GitVerse MCP](https://gitverse.ru/docs/ai/mcp/).
