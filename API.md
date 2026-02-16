# Photo Journal API Contract (v1)

Base URL: `/api/v1`  
Authentication: Bearer token via Laravel Sanctum.  
Response format: JSON.

## Frontend workspace

- SPA project path: `/home/esalnikova/projects/photo_journal_frontend`
- Frontend env var for API: `VITE_API_BASE_URL=http://127.0.0.1:8091/api/v1`
- Local token storage key (current scaffold): `pj_token`

## Contract status

- Version: `v1`
- Status: frozen for frontend integration
- Rule: response payloads and field names in this document must not change without explicit version bump or coordinated frontend update.

## Authentication

### `POST /auth/register`

Fields:
- `name` (required, string, max 255)
- `email` (required, email, unique)
- `password` (required, string, min 8)

Response `201`:

```json
{
  "token": "1|plainTextToken",
  "user": {
    "id": 1,
    "name": "Alice",
    "email": "alice@example.com"
  }
}
```

### `POST /auth/login`

Fields:
- `email` (required, email)
- `password` (required, string)

Response `200`:

```json
{
  "token": "2|plainTextToken",
  "user": {
    "id": 1,
    "name": "Alice",
    "email": "alice@example.com"
  }
}
```

Invalid credentials return `422`.

### `GET /profile`

Requires Bearer token.

Response `200`:

```json
{
  "data": {
    "id": 1,
    "name": "Alice",
    "email": "alice@example.com"
  }
}
```

### `POST /auth/logout`

Requires Bearer token. Deletes current access token and returns `204`.

### `PATCH /profile`

Requires Bearer token.

Fields:
- `name` (optional, string, max 255)
- `journal_title` (optional, nullable string, max 255)
- `email` (optional, email, max 255, unique)

Response `200`: updated current user in `data`.

## Bearer usage

Protected routes require:

```http
Authorization: Bearer <token>
```

## SPA auth flow (recommended)

1. Call `POST /auth/login` (or `POST /auth/register`).
2. Save `token` on frontend and attach header `Authorization: Bearer <token>` to all protected requests.
3. On app start, call `GET /profile` to restore authenticated user state.
4. On `401`, clear token and redirect to login.
5. On explicit logout, call `POST /auth/logout`, then clear token on frontend.

Token storage recommendation:
- Prefer in-memory storage for active session.
- Optionally mirror token in `localStorage` for session restore after refresh.
- Never log tokens to console or analytics.

## Error format

Validation errors use default Laravel shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Error text"]
  }
}
```

Upload partial errors (series/photos upload endpoints) use:

```json
{
  "original_name": "file.jpg",
  "error_code": "PHOTO_SAVE_FAILED",
  "message": "Photo could not be saved."
}
```

Auth/access errors:

```json
{
  "message": "Unauthenticated."
}
```

```json
{
  "message": "This action is unauthorized."
}
```

Status codes:
- `401` unauthenticated (missing/invalid token)
- `403` authenticated but forbidden by policy/ownership
- `422` validation error

## Access rules

- All routes except `POST /auth/register` and `POST /auth/login` require Bearer token.
- `Series` and nested `Photo` operations are restricted to owner (`series.user_id`).
- `Tag` manual create/update requires authenticated user.

## Series

### `GET /series`

Query params:
- `per_page` (optional, int, 1..100)
- `page` (optional, int, >= 1)

Response: Laravel paginator with `data`.

### `POST /series`

Create series and upload photos in one request.

Content-Type:
- `multipart/form-data`

Fields:
- `title` (required, string, max 255)
- `description` (optional, string)
- `photos[]` (required, 1..20 files)
- each photo: image, max 10MB, extensions `jpg|jpeg|png|webp`, mime `image/jpeg|image/png|image/webp`

Success response `201`:

```json
{
  "id": 12,
  "status": "queued",
  "photos_created": [
    {
      "id": 100,
      "series_id": 12,
      "path": "photos/series/12/abc.jpg",
      "original_name": "abc.jpg",
      "size": 12345,
      "mime": "image/jpeg"
    }
  ],
  "photos_failed": []
}
```

If all photos failed, response `422`:

```json
{
  "message": "No photos were saved.",
  "photos_failed": [
    {
      "original_name": "abc.jpg",
      "error_code": "PHOTO_SAVE_FAILED",
      "message": "Photo could not be saved."
    }
  ]
}
```

### `GET /series/{series}`

Query params:
- `include_photos` (optional, boolean; default `false`)
- `photos_limit` (optional, int, 1..100; default `30`, used only when `include_photos=true`)

Response:
- always returns `data` with series fields and `photos_count`
- includes `photos` (with nested `tags`) only when `include_photos=true`
- each photo in `photos` may include `preview_url` (temporary/signed URL for image preview in frontend)

### `PATCH /series/{series}`

Fields:
- `title` (optional, string, max 255)
- `description` (optional, string or null)

Response: updated series in `data`.

### `DELETE /series/{series}`

Response: `204 No Content`.  
Behavior: deletes series DB row, related photos rows (FK cascade), and stored photo files.

## Photos (nested under Series)

### `GET /series/{series}/photos`

Query params:
- `per_page` (optional, int, 1..100, default 15)
- `page` (optional, int, >= 1)
- `sort_by` (optional, one of: `id`, `created_at`, `original_name`, `size`; default `created_at`)
- `sort_dir` (optional, `asc|desc`; default `desc`)

Response: paginator with `data` (photos + `tags`).

### `POST /series/{series}/photos`

Content-Type:
- `multipart/form-data`

Fields:
- `photos[]` (required, 1..20 files)
- each photo validation same as `POST /series`

Success response `201`:

```json
{
  "photos_created": [
    {
      "id": 101,
      "series_id": 12,
      "path": "photos/series/12/x.jpg",
      "original_name": "x.jpg",
      "size": 111,
      "mime": "image/jpeg"
    }
  ],
  "photos_failed": []
}
```

If all photos failed: `422` with same shape as `POST /series` fail case.

### `GET /series/{series}/photos/{photo}`

Response: `data` with photo + `tags`.

### `PATCH /series/{series}/photos/{photo}`

Fields:
- `original_name` (optional, string, max 255)
  - only file base name is changeable; extension is locked to current photo extension
  - if base name contains characters outside `A-Za-z0-9`, it is transliterated to ASCII and normalized to camelCase

Response: updated photo in `data`.

### `DELETE /series/{series}/photos/{photo}`

Response: `204 No Content`.  
Behavior: deletes file from storage and then photo DB row.

## Photo tags

### `PUT /series/{series}/photos/{photo}/tags`

Sync exact tag set for photo (replace existing).

Fields:
- `tags` (required array, 1..50)
- `tags[]` (string, max 50, only latin letters and spaces)

Normalization:
- trim spaces
- collapse duplicates
- lowercase

Response: photo in `data` with updated `tags`.

### `POST /series/{series}/photos/{photo}/tags`

Attach tags without removing existing.

Fields and normalization same as `PUT`.

Response: photo in `data` with updated `tags`.

### `DELETE /series/{series}/photos/{photo}/tags/{tag}`

Detach one tag from photo.

Response: photo in `data` with updated `tags`.

## Tags (manual management)

### `POST /tags`

Create tag manually.

Fields:
- `name` (required, max 50, only latin letters and spaces, unique)

Normalization before validation:
- trim spaces
- collapse multiple spaces into one
- lowercase

Response `201`:

```json
{
  "data": {
    "id": 1,
    "name": "night city"
  }
}
```

### `PATCH /tags/{tag}`

Update tag name manually.

Fields and normalization same as `POST /tags`.

Response: updated tag in `data`.
