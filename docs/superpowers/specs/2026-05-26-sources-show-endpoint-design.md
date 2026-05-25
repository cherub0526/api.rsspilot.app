# Design: Sources Show Endpoint

**Date:** 2026-05-26  
**Status:** Approved  
**Scope:** Add `GET /v1/sources/{sourceId}` to return detailed information for a single subscribed source.

---

## Background

The existing `index()` endpoint returns all subscribed sources with basic fields (`id`, `name`, `url`, `type`, `notify`, `thumbnail`). The frontend needs a single-source detail endpoint that also exposes `description` and `subscriber_count` without fetching the entire list.

---

## API Contract

### Endpoint

```
GET /v1/sources/{sourceId}
Authorization: Bearer {token}
```

### Path Parameter

| Parameter  | Format                           | Description |
|------------|----------------------------------|-------------|
| `sourceId` | ULID `[0-7][0-9a-hjkmnp-tv-z]{25}` | Source ID   |

### Response — 200 OK

```json
{
  "id": "01hwzxxxxxxxxxxxxxxxxxxxxxx",
  "name": "Google Developers",
  "url": "https://www.youtube.com/channel/UC295-Dw4tzbkl8M9I2GFRtg",
  "type": "channel",
  "notify": true,
  "thumbnail": "https://yt3.ggpht.com/...",
  "description": "News and tutorials from the Google Developers team.",
  "subscriber_count": 1230000
}
```

### Response — 401 Unauthorized

Standard `401` response (ref: `Http401`).

### Response — 404 Not Found

Returned when `sourceId` does not exist in the authenticated user's subscribed sources.

---

## Field Specification

| Field             | Type         | Source                              | Notes                                      |
|-------------------|--------------|-------------------------------------|--------------------------------------------|
| `id`              | string       | `Source::id`                        | ULID as string                             |
| `name`            | string       | `Source::title`                     | Empty string fallback                      |
| `url`             | string       | Derived from `type` + `external_id` | YouTube channel or playlist URL            |
| `type`            | string       | `Source::type`                      | `"channel"` or `"playlist"`               |
| `notify`          | bool         | `pivot.notify`                      | User-specific subscription setting        |
| `thumbnail`       | string\|null | `Source::thumbnail`                 | May be null                                |
| `description`     | string\|null | `Source::description`               | May be null                                |
| `subscriber_count`| int\|null    | `Source::metadata['subscriber_count']` | Non-null only for `youtube_channel` type |

---

## Access Control

- Requires JWT authentication (`auth` middleware).
- Returns 404 if the source is not in the authenticated user's subscribed sources, consistent with the `update()` and `destroy()` access pattern.
- Does **not** independently check `Source::status` — subscription membership implicitly validates the source exists and is usable.

---

## Files Changed

| Action | File |
|--------|------|
| **Create** | `app/Http/Resources/SourceDetailResource.php` |
| **Modify** | `app/Http/Controllers/API/V1/SourcesController.php` — add `show()` method with OAT annotations |
| **Modify** | `routes/v1.php` — add `GET /{sourceId}` route inside `sources` group |

---

## Implementation Notes

- `SourceDetailResource` contains its own `toArray()` and does not extend `SourceResource` (avoids tight coupling; the shared logic is minimal).
- `subscriber_count` is cast to `int` if present in `metadata`, otherwise `null`. Playlist sources always return `null`.
- Route regex for `sourceId` is `[0-7][0-9a-hjkmnp-tv-z]{25}` (ULID format), consistent with the existing routes in the `sources` group.
- OpenAPI annotation follows the project's `OAT\*` attribute pattern; `SourceDetailResource` schema is defined inline in the `show()` annotation (no separate schema class needed for this scope).

---

## Out of Scope

- Modifying `index()` to return additional fields.
- Creating a standalone `SourceDetail` OpenAPI schema class (inline schema is sufficient).
- Any change to how `metadata` is populated or synced.
