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

**Container management:**

```bash
# Start containers
docker compose up -d

# Stop containers
docker compose down

# View logs
docker compose logs -f hypervel

# Access container shell
docker compose exec hypervel bash
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

### Database

```bash
# Run migrations
docker compose exec hypervel php artisan migrate

# Rollback migrations
docker compose exec hypervel php artisan migrate:rollback

# Fresh migration (drop all tables and re-migrate)
docker compose exec hypervel php artisan migrate:fresh

# Seed database
docker compose exec hypervel php artisan db:seed
```

### Queue Workers

```bash
# Start queue worker (processes default queue)
docker compose exec hypervel php artisan queue:work

# Process specific queue
docker compose exec hypervel php artisan queue:work --queue=media.caption
docker compose exec hypervel php artisan queue:work --queue=media.info
```

### Testing

```bash
# Run all tests
docker compose exec hypervel composer test
# or
docker compose exec hypervel vendor/bin/phpunit

# Run specific test file
docker compose exec hypervel vendor/bin/phpunit tests/Feature/ExampleTest.php

# Run specific test method
docker compose exec hypervel vendor/bin/phpunit --filter testExample
```

### Code Quality

```bash
# Fix code style for modified files (vs origin/main)
docker compose exec hypervel composer cs-diff

# Fix code style for specific file
docker compose exec hypervel composer cs-fix app/Models/User.php

# Run static analysis (PHPStan)
docker compose exec hypervel composer analyse
```

### RSS Synchronization

```bash
# Sync all RSS feeds (fetches new videos from subscribed channels)
docker compose exec hypervel php artisan rss:sync

# Sync specific RSS feed by ID
docker compose exec hypervel php artisan rss:sync --id=1
```

## Framework-Specific Notes

Hypervel is Laravel-compatible but uses Swoole coroutines for high concurrency. Key differences from Laravel:

- **HTTP Responses**: Use PSR-7 interfaces (`\Psr\Http\Message\ResponseInterface`) instead of Laravel responses
- **Coroutine Support**: Non-blocking I/O operations via Swoole coroutines
- **Namespace**: Framework components use `Hypervel\*` instead of `Illuminate\*`
- **Server**: Long-running process (not PHP-FPM), restart required for code changes unless using `server:watch`
- **Most patterns identical**: Eloquent, routing, validation, facades, service providers all work like Laravel

## Architecture Overview

### Media Processing Pipeline

The system processes YouTube videos through multiple stages with separate job queues:

1. **Creation** (`STATUS_CREATED`) - Media record created from RSS feed
2. **Info Fetching** (`STATUS_PROGRESS`) - `InfoJob` fetches video details via RapidAPI
3. **Transcription** (`STATUS_TRANSCRIBING` → `STATUS_TRANSCRIBED`) - `CaptionJob` generates captions:
    - Uses existing subtitles if available
    - Falls back to Groq API for audio transcription
    - Supports multiple locales
4. **Summarization** (`STATUS_SUMMARIZING` → `STATUS_SUMMARIZED`) - `SummaryJob` generates AI summaries
5. **Ready** (`STATUS_READY`) - Video ready for chat and consumption

Jobs use specific queues:

- `media.info` - InfoJob
- `media.caption` - CaptionJob
- `media.summary` - SummaryJob (not yet implemented)

### Core Models & Relationships

**User** - Authenticated users

- `hasMany`: Media (polymorphic via `userables`), Oauth
- `belongsTo`: Paddle (customer relationship)

**Media** - YouTube videos (status: created → progress → transcribing → transcribed → summarizing → summarized → ready)

- `hasMany`: Caption, Summary
- `belongsToMany`: User (polymorphic via `userables`)

**Rss** - RSS feed subscriptions

- `belongsToMany`: User (polymorphic via `userables`)
- `hasMany`: RssSyncHistory

**Caption** - Video transcripts/captions (by locale)

- `belongsTo`: Media

**Summary** - AI-generated video summaries

- `belongsTo`: Media

**Paddle, Plan, Price, Subscription, Transaction** - Paddle integration models

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

**Language Files** (`lang/`)

- **Supported locales**: `en` (English), `zh_CN` (Simplified Chinese), `zh_TW` (Traditional Chinese)
- **Translation files per locale**:
    - `validation.php` - Framework validation messages (Laravel/Hypervel standard)
    - `validators.php` - Custom validator messages organized by module:
        - `controllers.*` - Controller-level error messages (auth, media, rss, subscription, webhook)
        - `auth.*` - Authentication field validation messages
        - `chat.*` - Chat message validation
        - `media.*` - Media field validation
        - `oauth.*` - OAuth provider validation
        - `rss.*` - RSS feed validation
        - `subscription.*` - Subscription validation
        - `user.*` - User profile validation
    - `mails.php` - Email template translations (e.g., password reset emails)

**Usage in code**:

```php
// Get translated validation message
__('validators.auth.invalid_credentials')

// Get translated email content
__('mails.reset_password.subject')
```

### API Routes (v1)

All routes in `routes/v1.php`:

**Auth** (JWT-based)

- `POST /auth` - Login
- `POST /auth/register` - Register
- `POST /auth/refresh` - Refresh token
- `POST /auth/logout` - Logout (auth required)
- `POST /auth/forgot-password` - Request password reset
- `PUT /auth/forgot-password` - Reset password with token

**Users** (auth required)

- `GET /users` - Get current user profile
- `PUT /users` - Update current user

**RSS** (auth required)

- `GET /rss` - List user's RSS subscriptions
- `POST /rss` - Subscribe to RSS feed
- `DELETE /rss/{id}` - Unsubscribe

**Media** (auth required)

- `GET /media` - List user's videos
- `GET /media/{id}` - Get video details
- `GET /media/{id}/captions` - List captions
- `GET /media/{id}/captions/{captionId}` - Get specific caption
- `GET /media/{id}/summaries` - List summaries
- `GET /media/{id}/summaries/{id}` - Get specific summary
- `POST /media/{id}/chat` - Chat with video content

**Subscriptions** (auth required)

- `GET /subscriptions` - List user subscriptions
- `POST /subscriptions` - Create subscription
- `PUT /subscriptions/{id}` - Update subscription
- `DELETE /subscriptions/{id}` - Cancel subscription
- `GET /subscriptions/usage` - Get usage stats

**Plans** (public)

- `GET /plans` - List available subscription plans

**Webhooks** (public)

- `POST /webhook/paddle` - Paddle webhook handler

### OpenAPI Documentation Structure

The API documentation uses OpenAPI 3.x specification with PHP 8 Attributes. All OpenAPI definitions are in
`app/OpenApi/`:

**Directory Structure:**

```
app/OpenApi/
├── Info.php             - API metadata (version, title, description)
├── Server.php           - Server configurations (local, production)
├── Parameters/          - Reusable parameter definitions
│   ├── Header/         - HTTP header parameters (e.g., Authorization)
│   ├── Path/           - URL path parameters (e.g., {id})
│   └── Query/          - Query string parameters (e.g., ?page=1)
├── Responses/          - Reusable response definitions
└── Schemas/            - Data model schemas (request/response bodies)
```

**Naming Convention:**

When referencing OpenAPI components, use dot notation based on directory structure:

- Header parameter: `Header.Authorization` (file: `Parameters/Header/Authorization.php`)
- Path parameter: `Path.Id` (file: `Parameters/Path/Id.php`)
- Query parameter: `Query.Page` (file: `Parameters/Query/Page.php`)
- Response: `Response.Success` (file: `Responses/Success.php`)
- Schema: `Schema.User` (file: `Schemas/User.php`)

**Usage in Controllers:**

```php
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Header;

#[OAT\Get(
    path: '/api/v1/users/{id}',
    parameters: [
        new OAT\Parameter(ref: Header\Authorization::class),
        new OAT\Parameter(ref: Path\Id::class),
    ]
)]
public function show(string $id): ResponseInterface
{
    // Implementation
}
```

**Component Files:**

Each component file uses PHP 8 Attributes with OpenAPI annotations:

```php
// app/OpenApi/Parameters/Header/Authorization.php
#[OAT\Parameter(
    name: 'Authorization',
    in: 'header',
    required: true,
    description: 'Bearer token for authentication',
    schema: new OAT\Schema(type: 'string', example: 'Bearer {token}')
)]
class Authorization {}
```

### Validation Pattern

Custom validators extending `App\Validators\BaseValidator`:

```php
class MediaValidator extends BaseValidator
{
    public function setIndexRules(): void { /* ... */ }
    public function setStoreRules(): void { /* ... */ }
    public function setUpdateRules(): void { /* ... */ }
}
```

Throw `InvalidRequestException` with error array on validation failure.

### Authentication

- JWT-based with `auth('jwt')` guard
- Token TTL: configurable via `config('jwt.ttl')` in minutes
- Social OAuth via Hypervel Socialite (Facebook, Google)
- Middleware: `'middleware' => ['auth']` on protected routes

### Queue System

- Default driver: database (`QUEUE_CONNECTION=database`)
- Jobs implement `ShouldQueue` and use `Queueable` trait
- Dispatch: `JobClass::dispatch($params)`
- Supports coroutine-based processing for concurrent I/O

### Paddle Integration

- SDK initialized with API key and sandbox mode from env
- Webhook verification using `Paddle\SDK\Notifications\Verifier` with `PADDLE_WEBHOOK_SECRET_KEY`
- Models sync with Paddle via Observers (`PlanObserver`, `PriceObserver`)
- Store Paddle entities using polymorphic `foreign_type` and `foreign_id` pattern

## Environment Configuration

Required environment variables (see `.env.example`):

**Core**

- `APP_KEY` - Application encryption key
- `APP_ENV` - Environment (local, production)
- `APP_DEBUG` - Debug mode boolean

**Database**

- `DB_CONNECTION` - sqlite or mysql
- `DB_*` - Connection settings for MySQL

**Queue & Cache**

- `QUEUE_CONNECTION` - database (default)
- `CACHE_DRIVER` - redis (default)
- `REDIS_*` - Redis connection settings

**Authentication**

- `JWT_SECRET` - JWT signing secret
- `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `FACEBOOK_REDIRECT_URL`
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URL`

**External APIs**

- `OPENAI_API_KEY` - OpenAI API key
- `GROQ_API_KEY` - Groq API key (for transcription)
- `RAPID_API_KEY` - RapidAPI key (for YouTube data)
- `PADDLE_API_KEY` - Paddle API key
- `PADDLE_CLIENT_TOKEN` - Paddle client token
- `PADDLE_WEBHOOK_SECRET_KEY` - Webhook verification secret
- `PADDLE_SANDBOX` - Boolean for sandbox mode

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
