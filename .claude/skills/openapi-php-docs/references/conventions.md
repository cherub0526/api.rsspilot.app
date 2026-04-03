# OAT Attribute Conventions

## Required fields on every endpoint attribute

| Field | Rule |
|-------|------|
| `path` | Exact route path, e.g., `/api/v1/media/{mediaId}` |
| `summary` | Short action description, e.g., `'List user media'` |
| `tags` | One tag matching the controller domain: `['Media']`, `['Auth']`, `['RSS']`, etc. |
| `security` | `[['bearerAuth' => []]]` for auth-protected endpoints; omit for public |
| `responses` | At minimum: 200 success + `ref: Http400::class` for endpoints with validation |

## requestBody

Use when the HTTP method is POST/PUT/PATCH and the endpoint reads from request body:

```php
requestBody: new OAT\RequestBody(
    required: true,
    content: new OAT\JsonContent(
        required: ['field1', 'field2'],   // from validator $rules array
        properties: [
            new OAT\Property(property: 'field1', type: 'string', ...),
        ]
    )
)
```

Derive `required: [...]` from the validator's `required` rule. Derive `type:`, `minLength:`, `maxLength:`, `enum:` from the other rules.

## parameters (query/path)

Use `ref:` for any parameter that already exists as a component:
```php
new OAT\Parameter(ref: Query\Type::class)
```

Write inline only if the parameter is unique to this endpoint and unlikely to be reused.

## responses: success (200)

```php
new OAT\Response(
    response: 200,
    description: 'Successful operation',
    content: new OAT\JsonContent(ref: SomeResource::class)
    // or inline properties for simple responses
)
```

For paginated collections, include `data`, `links` (ref: Paginators\Links), `meta` (ref: Paginators\Meta).

## responses: errors

| Code | When | Component |
|------|------|-----------|
| 400 | Validation failure or resource not found | `ref: Http400::class` |
| 401 | Unauthenticated | inline `new OAT\Response(response: 401, description: 'Unauthenticated')` |

## Component file skeleton

```php
<?php

declare(strict_types=1);

namespace App\OpenApi\{Sub};

use OpenApi\Attributes as OAT;

#[OAT\{ComponentType}(
    // ... fields
)]
class {ClassName}
{
}
```

Always: `declare(strict_types=1)`, correct namespace, single OAT attribute, empty class body.
