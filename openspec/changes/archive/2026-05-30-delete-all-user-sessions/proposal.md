## Why

Users need a way to clear all their chat history across all videos in a single operation. Currently only individual session deletion (`DELETE /v1/media/{mediaId}/chat/sessions/{sessionId}`) exists, requiring multiple API calls to wipe all sessions — a poor experience for account cleanup or privacy purposes.

## What Changes

- Add `DELETE /v1/users/sessions` endpoint that deletes all chat sessions belonging to the authenticated user, across all media
- Returns `204 No Content` on success (idempotent: safe to call even when no sessions exist)

## Capabilities

### New Capabilities

- `delete-all-user-sessions`: Bulk-delete all chat sessions for the authenticated user across all media

### Modified Capabilities

<!-- No existing specs require behavioral changes -->

## Impact

- **Routes**: `routes/v1.php` — new DELETE route under the `users` group
- **Controller**: New action in `App\Http\Controllers\API\Users\SessionController` (or existing user controller)
- **Model**: `ChatSession` model — query scoped by `user_id`
- **OpenAPI**: New endpoint doc + sync to `public/openapi.json`
- **Tests**: Feature test covering authenticated deletion, empty-state idempotency, and authorization guard
