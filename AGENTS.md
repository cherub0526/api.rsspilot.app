# CLAUDE.md

This file provides guidance to Claude Code、Gemini when working with code in this repository.

## Project Overview

This is a video assistant API built with **Hypervel** (v0.3), a Laravel-style PHP framework with native coroutine
support built on Swoole. The application provides AI-powered video analysis, summaries, captions, and chat capabilities
for YouTube content, with Paddle subscription management.

**Core functionality:**

- RSS feed subscription and synchronization for YouTube channels
- Video caption extraction and AI-powered transcription (via Groq)
- AI-generated video summaries using OpenAI
- Interactive chat with video content
- Paddle subscription management with webhook handling
- OAuth authentication (local, Facebook, Google) with JWT tokens

## Project Lore

Project lore lives in `docs/lore/` — consult it before planning or bug-fixing, and capture implicit knowledge into it.

## Before Building Something That May Already Exist

Search the codebase before implementing a requested feature. If something similar already exists — a util, a service, a constant table, a template, a job — **stop before writing code** and:

1. Name what already exists and where it lives.
2. List the concrete differences between it and what was asked for.
3. Ask whether to **replace** the existing one, **extend** it, or let the two **coexist**.
4. Only start once the answer comes back.

Building a parallel implementation and asking afterwards costs a review cycle and leaves two things that drift apart. This applies even when the existing code is an imperfect fit — say so and let the decision be made rather than routing around it.

## Development Environment

This project runs in a **Docker Compose** environment. All commands must be executed inside the Docker container.

**Command prefix pattern:**

```bash
docker compose exec hypervel {your-command}
```

**Examples:**

```bash
# Run PHP version commands
docker compose exec hypervel php -v

# Run PHP artisan commands
docker compose exec hypervel php artisan migrate

# Run composer commands
docker compose exec hypervel composer install

# Run PHPUnit tests
docker compose exec hypervel vendor/bin/phpunit
```

## Development Commands

### Server Management

```bash
# Start the development server (blocking, keeps running until stopped)
docker compose exec hypervel composer start
# or
docker compose exec hypervel php artisan start

# Start with file watching (auto-reload on code changes)
docker compose exec hypervel php artisan server:watch

# Server runs on HTTP_SERVER_HOST:HTTP_SERVER_PORT (default: 0.0.0.0:9501)
```

### Queue Workers

```bash
# Start queue worker (processes default queue)
docker compose exec hypervel php artisan queue:work

# Process specific queue
docker compose exec hypervel php artisan queue:work --queue=media.caption
docker compose exec hypervel php artisan queue:work --queue=media.info
```

## Framework-Specific Notes

Hypervel is Laravel-compatible but uses Swoole coroutines for high concurrency. Key differences from Laravel:

- **HTTP Responses**: Use PSR-7 interfaces (`\Psr\Http\Message\ResponseInterface`) instead of Laravel responses
- **Coroutine Support**: Non-blocking I/O operations via Swoole coroutines
- **Namespace**: Framework components use `Hypervel\*` instead of `Illuminate\*`
- **Server**: Long-running process (not PHP-FPM), restart required for code changes unless using `server:watch`
- **Most patterns identical**: Eloquent, routing, validation, facades, service providers all work like Laravel

## Architecture Overview

### Key Services

**PaddleClient** (`App\Services\PaddleClient`)

- Wrapper for Paddle PHP SDK
- Methods: `customers()`, `products()`, `prices()`, `subscriptions()`, `transactions()`
- Respects sandbox mode via `PADDLE_SANDBOX` env var

**RssFeedAsapService** (`App\Services\RssFeedAsapService`)

- Parses YouTube RSS feeds
- Extracts video metadata

**Prompt Templates** (`App\Services\Prompts/*`)

- Template system for AI interactions with OpenAI
- `TemplateCompletionManager` - Orchestrates API calls
- Templates: `AnalysisTemplate`, `SummaryTemplate`, `TranslationTemplate`, `CaptionTemplate`, `AssistantTemplate`
- All extend `BaseTemplate` implementing `TemplateInterface`

**OpenAI Integration** (`App\Utils\OpenAI\Completion`)

- Direct OpenAI API client for completions

**YoutubeMediaDownloader** (`App\Services\RapidApi\YoutubeMediaDownloader`)

- RapidAPI client for fetching YouTube video details and audio info

### Localization & Internationalization

`validators.php` organizes custom validation messages by module using dot notation (e.g. `controllers.*`, `auth.*`, `media.*`, `rss.*`, `subscription.*`, `user.*`) — this nesting convention isn't a Laravel/Hypervel default, so mirror it rather than flattening new messages into `validation.php`.

**Usage in code**:

```php
// Get translated validation message
__('validators.auth.invalid_credentials')

// Get translated email content
__('mails.reset_password.subject')
```

### Authentication

- JWT-based with `auth('jwt')` guard
- Token TTL: configurable via `config('jwt.ttl')` in minutes
- Social OAuth via Hypervel Socialite (Facebook, Google)
- Middleware: `'middleware' => ['auth']` on protected routes

### Paddle Integration

- SDK initialized with API key and sandbox mode from env
- Webhook verification using `Paddle\SDK\Notifications\Verifier` with `PADDLE_WEBHOOK_SECRET_KEY`
- Models sync with Paddle via Observers (`PlanObserver`, `PriceObserver`)
- Store Paddle entities using polymorphic `foreign_type` and `foreign_id` pattern

## Code Conventions

**All PHP files must**:

- Use `declare(strict_types=1);` at top
- Follow PHP CS Fixer rules (`.php-cs-fixer.php`)
- Pass PHPStan analysis (level defined in `phpstan.neon`)

**Migrations**:

- Extend `App\Utils\BaseMigration` (not Illuminate's Migration class)

**Models**:

- Extend `App\Models\Model` (base model with common config)
- User model extends `Hypervel\Foundation\Auth\User`
- Define `$fillable`, `$casts`, relationships explicitly

**Controllers**:

- Extend `App\Http\Controllers\API\AbstractController`
- Type hint: `Hypervel\Http\Request`
- Return type: `\Psr\Http\Message\ResponseInterface`
- Use `response()->json()` for JSON responses

**Responses**:

- Use API Resources for structured output (e.g., `UserResource`, `MediaResource`)
- Follow PSR-7 interface pattern

**Error Handling**:

- Throw `App\Exceptions\InvalidRequestException` for validation errors
- Custom exception handling in `App\Exceptions\Handler`

## Testing

- Test environment: SQLite (`DB_CONNECTION=sqlite_testing`)
- Tests in `tests/Feature/` and `tests/Unit/`
- Use `RefreshDatabase` trait for database tests
- Helper functions in `tests/helpers.php`
- Bootstrap: `tests/bootstrap.php`
