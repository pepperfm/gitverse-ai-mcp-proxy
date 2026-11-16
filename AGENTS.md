# AGENTS.md

> Карта проекта для AI agents и разработчиков. Обновляйте файл при значимых изменениях структуры.

## Project Overview

Laravel starter kit для PepperFM с интерактивной setup-командой, Sail окружением и AI/dev quality tooling. Filament/Moonshine не входят в setup flow. Для новой админки используется optional custom frontend foundation на Inertia + Vue + Nuxt UI.

## Tech Stack

- **Programming language:** PHP 8.5+
- **Framework:** Laravel 13
- **Database:** PostgreSQL 17 через Sail
- **Cache/queue:** Redis через Sail, стандартные Laravel config files
- **Frontend:** Blade + Vite assets by default; optional Inertia 3 + Vue 3 + TypeScript + Nuxt UI 4 admin preset
- **Tests:** Pest 4, Pest Laravel 4, Larastan, Pint, Laravel PAO
- **AI/dev tooling:** Laravel Boost, Laravel Brain, Laravel PAO, PepperFM AI Guidelines

## Project Structure

```text
app/
  Bootstrap/          # Laravel bootstrap customizers for exceptions and middleware
  Console/Commands/   # Starter kit interactive setup command
  Setup/              # Setup installers for optional starter-kit presets
  Exceptions/         # Typed application/http exceptions
  Http/Controllers/   # Standard Laravel controllers
  Models/             # Eloquent models
  Providers/          # Laravel service providers
bootstrap/            # Laravel bootstrap files
config/               # Laravel configuration
database/             # Migrations, factories, seeders
docker/               # Sail Docker image and PostgreSQL test database setup
resources/            # Blade views, CSS, JS
routes/               # Web and console routes
stubs/                # Publishable starter-kit presets and Laravel stubs
tests/                # Pest feature and architecture tests
.ai-factory/          # AI Factory project context
.codex/               # Project-local Codex skills and config
```

## Key Entry Points

| File | Purpose |
| --- | --- |
| `artisan` | Laravel CLI entry point |
| `app/Console/Commands/SetupCommand.php` | Интерактивная настройка `.env`, host/Sail runtime и установка опциональных пакетов |
| `app/Setup/AdminPanelFrontendInstaller.php` | Публикация optional Inertia/Vue/Nuxt UI admin frontend preset |
| `app/Setup/StarterKitPreset.php` | Preset-наборы `api`, `admin`, `observability`, `full` для setup-команды |
| `app/Setup/StarterKitPackageRegistry.php` | Метаданные optional packages, dev/runtime grouping и post-install команды |
| `app/Setup/StarterKitInstallationSummary.php` | Console summary перед запуском Composer/frontend/post-install шагов |
| `routes/web.php` | Web route definitions |
| `app/Bootstrap/WithExceptions.php` | JSON error rendering для API-запросов |
| `app/Bootstrap/WithMiddleware.php` | Guest redirect на `/panel` для новой admin area |
| `app/Providers/AppServiceProvider.php` | Общие Laravel boot rules |
| `docker-compose.yml` | Sail services: app, PostgreSQL, Redis |
| `vite.config.js` | Vite/Laravel asset pipeline |
| `tests/Feature/ArchTest.php` | Architecture expectations для PHP-кода |
| `tests/Feature/SetupCommandTest.php` | Проверка setup env normalization |

## Documentation

| Document | Path | Description |
| --- | --- | --- |
| README | `README.md` | Публичное описание starter kit и установки |
| AI Factory description | `.ai-factory/DESCRIPTION.md` | Текущий AI context проекта |
| Architecture | `.ai-factory/ARCHITECTURE.md` | Архитектурные правила для будущих изменений |
| Base rules | `.ai-factory/rules/base.md` | Автоопределенные conventions и ограничения |

## AI Context Files

| File | Purpose |
| --- | --- |
| `AGENTS.md` | Быстрая карта проекта для AI agents |
| `.ai-factory/config.yaml` | Настройки языка, путей, workflow и git для AI Factory |
| `.ai-factory/DESCRIPTION.md` | Описание проекта, stack и исключения |
| `.ai-factory/ARCHITECTURE.md` | Архитектурный ориентир для планов и реализации |
| `.ai-factory/rules/base.md` | Базовые project rules |

## Agent Rules

- Команды с shell control operators разбивать на отдельные шаги.
  - Неверно: `git checkout master && git pull`
  - Верно: сначала `git checkout master`, затем `git pull origin master`
- Не считать Filament/Moonshine целевой архитектурой или обязательной dependency.
- Панель в starter-stub использует UDashboardGroup/Sidebar/Navbar из Nuxt UI и Inertia/Vue. Не переносить Nuxt routing, Nitro и demo-страницы из внешнего шаблона.
- Маршрут /panel доступен только через Laravel session auth, гости перенаправляются на /panel/login. Первого пользователя создают через `php artisan panel:user`; регистрации нет. Роли/политики необходимы отдельно, если доступ должен быть только у администраторов.
- При переустановке admin preset не перезаписывать файлы пользователя. Для обновления зависимостей собирать lock-файлы заново через package manager.
- Не писать бизнес-код во время setup-контекста; для изменений использовать `$aif-plan`, затем `$aif-implement`.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `bun run build` or ask the user to run `bun run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
