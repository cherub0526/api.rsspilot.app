## Context

The existing `GET /v1/users/sessions` endpoint is handled by `App\Http\Controllers\API\V1\Users\SessionsController` (index-only). The `ChatSession` model uses `SoftDeletes`, so deletions set `deleted_at` rather than removing rows. The individual-session delete (`DELETE /v1/media/{mediaId}/chat/sessions/{sessionId}`) follows the same soft-delete pattern and returns `200 OK`.

## Goals / Non-Goals

**Goals:**
- Add `destroy` action to the existing `Users\SessionsController` for `DELETE /v1/users/sessions`
- Soft-delete all `ChatSession` rows scoped to the authenticated user
- Return `204 No Content` (idempotent; no error when zero sessions exist)
- Add OpenAPI annotation and sync `public/openapi.json`
- Cover with a feature test

**Non-Goals:**
- Hard deletion of session or message rows
- Selective deletion (by media, date range, etc.)
- Batch deletion with partial-failure reporting

## Decisions

### 1. Add to existing `Users\SessionsController`, not a new controller

The existing controller lives at the right namespace and already imports `ChatSession`. Adding a `destroy` method keeps the users-sessions surface area cohesive.

*Alternative considered*: a separate `SessionsBulkController` — rejected as over-engineering for a single action.

### 2. Use Eloquent `delete()` on a collection query, not `each()->delete()`

```php
ChatSession::where('user_id', $userId)->delete();
```

This fires a single `DELETE` statement with a `WHERE user_id = ?` condition. Because `SoftDeletes` is active on the model, Eloquent translates this to `UPDATE chat_sessions SET deleted_at = NOW() WHERE user_id = ? AND deleted_at IS NULL`. Efficient, no N+1.

*Alternative considered*: `->get()->each->delete()` — triggers model events per row but is O(N) queries. Acceptable only if cascade model events were needed; they are not here.

### 3. Return `204 No Content`

`200 OK` with a body is used for single-resource deletes in this codebase. For a bulk operation that has no meaningful response body and should be idempotent, `204` is cleaner and aligns with REST conventions. The empty-state case (no sessions) still returns `204`.

## Risks / Trade-offs

- **No undo** — soft-delete means rows are recoverable via admin, but the user has no self-service restore. Acceptable for an explicit "clear all" action.
- **No model events per row** — `delete()` on a Builder does not fire `deleting`/`deleted` Eloquent events per record. If listeners are added to `ChatSession` in future, this method would bypass them. Mitigation: document the trade-off in the PR; revisit if event-driven side effects are introduced.

## Migration Plan

1. Add `destroy` method to `Users\SessionsController`
2. Register `DELETE /users/sessions` route in `routes/v1.php`
3. Add OpenAPI `#[OAT\Delete]` annotation and run `php artisan openapi:generate`
4. Write feature test
5. No database migration needed (uses existing `chat_sessions` table)
