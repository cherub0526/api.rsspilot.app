# app/OpenApi/ Directory Structure

Generated spec: `public/openapi.json` — do not edit by hand. Regenerate with:
```bash
docker compose exec hypervel composer openapi
```

## Component Map

```
app/OpenApi/
├── Info.php                          # API metadata (title, version, description)
├── Server.php                        # Server URLs (local, production)
├── Parameters/
│   ├── Header/                       # HTTP header params (e.g., Authorization)
│   ├── Path/
│   │   └── MediaId.php               # {mediaId} path param — App\OpenApi\Parameters\Path\MediaId
│   └── Query/
│       ├── Limit.php                 # ?limit= — App\OpenApi\Parameters\Query\Limit
│       ├── Range.php                 # ?range= — App\OpenApi\Parameters\Query\Range
│       └── Type.php                  # ?type= — App\OpenApi\Parameters\Query\Type
├── Responses/
│   └── Http400.php                   # 400 Bad Request — App\OpenApi\Responses\Http400
└── Schemas/
    └── Paginators/
        ├── Links.php                 # Pagination links (first/last/prev/next) — schema: PaginatorLinks
        └── Meta.php                  # Pagination meta (current_page/per_page/total) — schema: PaginatorMeta
```

## PSR-4 Naming Rule

Filename MUST equal class name. `App\OpenApi\Responses\Http400` → `app/OpenApi/Responses/Http400.php`.

Do NOT use numeric-only filenames (e.g., `400.php`) — they break PSR-4 and require a `classmap` entry in composer.json. Use `Http400`, `Http401`, `Http422`, etc.

## Namespace Pattern

| Directory | Namespace |
|-----------|-----------|
| `app/OpenApi/Parameters/Path/` | `App\OpenApi\Parameters\Path` |
| `app/OpenApi/Parameters/Query/` | `App\OpenApi\Parameters\Query` |
| `app/OpenApi/Parameters/Header/` | `App\OpenApi\Parameters\Header` |
| `app/OpenApi/Responses/` | `App\OpenApi\Responses` |
| `app/OpenApi/Schemas/` | `App\OpenApi\Schemas` |
| `app/OpenApi/Schemas/Paginators/` | `App\OpenApi\Schemas\Paginators` |

## Schema Name Convention

When using `#[OAT\Schema(schema: '...')]`, the schema name appears in `public/openapi.json` under `components/schemas`. Use PascalCase with a group prefix:

- Paginators: `PaginatorLinks`, `PaginatorMeta`
- Auth: `AuthToken`, `AuthUser`
- Media: `MediaItem`

## Controller Usage Pattern

```php
use OpenApi\Attributes as OAT;
use App\OpenApi\Parameters\Query;
use App\OpenApi\Responses\Http400;
use App\OpenApi\Responses\Http401;
use App\OpenApi\Schemas\Paginators;

#[OAT\Get(
    path: '/api/v1/media',
    parameters: [
        new OAT\Parameter(ref: Query\Type::class),
    ],
    responses: [
        new OAT\Response(response: 200, ...),
        new OAT\Response(ref: Http400::class, response: 400),
        new OAT\Response(ref: Http401::class, response: 401),
    ]
)]
```
