# Photo Journal API Contract

Base URL: `/api/v1`  
Authentication: Bearer token via Laravel Sanctum.  
Response format: JSON.

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

### `GET /auth/me`

Requires Bearer token.

### `POST /auth/logout`

Requires Bearer token. Deletes current access token and returns `204`.

## Bearer usage

Protected routes require:

```http
Authorization: Bearer <token>
```

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
