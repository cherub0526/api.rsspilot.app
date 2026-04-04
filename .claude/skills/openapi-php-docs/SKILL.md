---
name: openapi-php-docs
description: Use when user asks to add or write OpenAPI docs for a PHP controller, extract inline OAT definitions into reusable component classes, or create new files in app/OpenApi/. For swagger-php Attributes in this Hypervel project. Not for REST design decisions or editing public/openapi.json.
version: 1.0.0
metadata: {"author": "Ethan"}
---

# OpenAPI PHP Docs

This skill governs how OpenAPI documentation is written and maintained in this project. All API docs are expressed as PHP 8 Attributes (`#[OAT\...]`) using `zircote/swagger-php`. Reusable components (parameters, schemas, responses) live as dedicated empty PHP classes in `app/OpenApi/`. The final spec is generated into `public/openapi.json` via `composer openapi`.

This skill does not cover REST API design decisions, route naming, or editing `public/openapi.json` by hand.

## Resource Schema Convention

When a controller action returns a `*Resource` object (e.g. `CaptionResource`, `MediaResource`), the OpenAPI schema for that resource **must** live in `app/OpenApi/Schemas/` in a file named exactly after the Resource class.

- File: `app/OpenApi/Schemas/CaptionResource.php`, class: `CaptionResource`, schema name: `'CaptionResource'`
- Do **not** put `#[OAT\Schema]` on the `app/Http/Resources/*Resource.php` class itself
- In controllers, import the schema class with an alias to avoid conflict with the HTTP Resource: `use App\OpenApi\Schemas\CaptionResource as CaptionSchema;` then use `ref: CaptionSchema::class`
- Schema classes in the same `App\OpenApi\Schemas\` namespace can reference each other by short name (e.g. `ref: PaddleResource::class`) without an explicit `use` statement

## Single responsibility

- Primary job: Produce correct, reusable OpenAPI Attribute code that integrates with the existing `app/OpenApi/` component library
- Not this skill's job: deciding what endpoints to build, writing business logic, or designing the REST contract
- Handoff rule: if the request is about API design choices (status codes, naming, versioning strategy), defer to the developer; if it is about PHP Attribute implementation, this skill owns it

<role>
Act as a PHP API documentation engineer who knows zircote/swagger-php, this project's component directory structure, and PSR-4 naming rules.
</role>

<decision_boundary>
Use when:
- Adding `#[OAT\Get/Post/Put/Delete/Patch]` to a controller method
- Creating a new file in `app/OpenApi/Parameters/`, `app/OpenApi/Responses/`, or `app/OpenApi/Schemas/`
- Extracting an inline OAT definition from a controller into a reusable component class
- Asking which existing component to `ref:` for a given parameter or response

Do not use when:
- The task is REST API design (what routes or status codes to use)
- The target file is not a PHP controller or `app/OpenApi/` component
- The request is to edit `public/openapi.json` directly (it is generated, not hand-edited)

Inputs:
- Controller file path + method signature
- Validator rules (to derive required fields and types)
- HTTP method and route path (from `routes/v1.php`)

Successful output:
- A `#[OAT\...]` attribute placed above the correct method, using `ref:` for all reusable components
- Or a new component PHP file in the correct `app/OpenApi/` subdirectory
- `composer openapi` runs without errors after the change
</decision_boundary>

## Primary use cases

1) **Document a controller action**
   - Trigger examples: "幫 `store()` 寫 OpenAPI 文件", "add OpenAPI docs to this endpoint", "針對這個 function 撰寫 OpenAPI"
   - Required inputs: controller file, validator rules, route path + HTTP method
   - Expected result: `#[OAT\{Method}(...)]` attribute with requestBody/parameters, 200 response, and standard error responses via `ref:`

2) **Extract inline definition to a component file**
   - Trigger examples: "把這個 response 提取到獨立檔案", "將 links/meta 提取到 Schemas", "extract this schema"
   - Required inputs: existing inline OAT definition, target directory + desired class name
   - Expected result: new PHP file in correct `app/OpenApi/` subdirectory + controller updated to use `ref: ClassName::class`

3) **Create a new reusable component**
   - Trigger examples: "建立一個 401 response 的元件", "新增 Query\Page 參數", "create a reusable schema for User"
   - Required inputs: component type (Parameter/Response/Schema), field/value description
   - Expected result: PHP class file with correct namespace, OAT attribute, PSR-4 compliant filename

## Routing boundaries

- Neighboring skills: `api-design` (REST conventions), `backend-patterns` (controller structure)
- Negative triggers: "should this return 200 or 201?", "design the API for...", "edit openapi.json"
- Handoff rule: if the user asks *what* to document (not *how*), answer briefly and hand back

<workflow>
Step 0: Orient
- Action: Read the referenced controller and validator file(s). Identify HTTP method, route path, required/optional params, and response shape.
- Input: controller file path, validator class
- Output: confirmed method signature, param list, response fields
- Validation: route path matches an entry in `routes/v1.php`; validator rules match expected fields

Step 1: Check existing components
- Action: Scan `app/OpenApi/` (see `references/project-structure.md`) for components to `ref:` instead of writing inline.
- Input: param names, response codes needed, return type of the controller action
- Output: list of `ref:` candidates vs. fields that need inline definitions
- If the action returns a `*Resource`: check whether `app/OpenApi/Schemas/{ResourceName}.php` exists; if not, create it in Step 3
- Validation: referenced class exists; namespace resolves via PSR-4

Step 2: Write or update the OAT attribute
- Action: Write `#[OAT\{Method}(path:, summary:, tags:, ...)]` above the method. Follow the conventions in `references/conventions.md`.
- Input: confirmed params, responses, ref list
- Output: complete attribute block
- Validation: all required OAT fields present; `ref:` used for every reusable component; no inline duplication of an existing component

Step 3: Create component file (if needed)
- Action: For each new reusable component, create a PHP file in the correct `app/OpenApi/` subdirectory. PSR-4 rule: filename must equal class name exactly.
- Input: component type, class name, field definitions
- Output: new `.php` file with `declare(strict_types=1)`, correct namespace, OAT attribute, empty class body
- Validation: `App\OpenApi\{Sub}\{ClassName}` resolves via PSR-4 (file = `ClassName.php`); no classmap entry needed

Step 4: Update controller imports
- Action: Add `use App\OpenApi\...` import for any new component class used via `ref:`.
- Input: new component namespaces
- Output: updated `use` block in controller
- Validation: all `ref:` targets are imported; no stale old class names remain

Step 5: Suggest regeneration
- Action: Tell the user to run `docker compose exec hypervel composer openapi` to regenerate `public/openapi.json`.
- Do NOT run this automatically — it modifies a committed file and should be a deliberate step.
</workflow>

<output_contract>
When documenting a controller method, output in this order:
1. Complete `#[OAT\...]` attribute block (fenced PHP)
2. Any new component file(s) content (separate fenced PHP block per file)
3. Updated `use` imports for the controller (if changed)
4. One-line note on which components were created vs. reused

No prose explanation unless something non-obvious requires justification.
Do not output or modify `public/openapi.json`.
</output_contract>

<tool_rules>
- Use Read to inspect controller and validator files before writing any attribute
- Use Glob to scan `app/OpenApi/` for existing components before creating new ones
- Use Write only for new component files; use Edit for modifying existing controllers
- Do not run `composer openapi` — suggest the command for the user to run
</tool_rules>

<default_follow_through_policy>
- Directly do: reading files, writing new component files, editing controller OAT attributes and imports
- Ask first: if the route path is ambiguous or not present in `routes/v1.php`
- Stop and report: if a PSR-4 filename conflict exists, or if required validator rules are missing from the file
</default_follow_through_policy>

<examples>
Example 1 — Document a POST endpoint

Input:
- Controller: `ForgotPasswordController::store()`
- Validator: `account` required|string|min:6|max:255
- Route: `POST /api/v1/auth/forgot-password`

Output:
```php
#[OAT\Post(
    path: '/v1/auth/forgot-password',
    operationId: 'api.v1.auth.forgot-password.store',
    summary: 'Send password reset email',
    tags: ['Auth'],
    requestBody: new OAT\RequestBody(
        required: true,
        content: new OAT\JsonContent(
            required: ['account'],
            properties: [
                new OAT\Property(
                    property: 'account',
                    type: 'string',
                    minLength: 6,
                    maxLength: 255,
                    example: 'user@example.com'
                ),
            ]
        )
    ),
    responses: [
        new OAT\Response(
            response: 200,
            description: 'Reset email sent',
            content: new OAT\JsonContent(
                properties: [
                    new OAT\Property(property: 'message', type: 'string', example: 'We have emailed your password reset link.'),
                ]
            )
        ),
        new OAT\Response(ref: Http400::class, response: 400),
    ]
)]
```

Example 2 — Extract inline response to a component

Input: Inline `response: 400` block repeated across multiple controllers

New file `app/OpenApi/Responses/Http400.php`:
```php
<?php

declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Attributes as OAT;

#[OAT\Response(
    response: 400,
    description: 'Invalid request parameters',
    content: new OAT\JsonContent(
        properties: [
            new OAT\Property(
                property: 'errors',
                type: 'object',
                example: ['field' => ['The field is required.']]
            ),
        ]
    )
)]
class Http400
{
}
```

Controller change: replace inline block with `new OAT\Response(ref: Http400::class)`

Example 3 — Create a Query parameter component

New file `app/OpenApi/Parameters/Query/Page.php`:
```php
<?php

declare(strict_types=1);

namespace App\OpenApi\Parameters\Query;

use OpenApi\Attributes as OAT;

#[OAT\Parameter(
    name: 'page',
    description: 'Page number',
    in: 'query',
    required: false,
    schema: new OAT\Schema(type: 'integer', example: 1)
)]
class Page
{
}
```
</examples>
