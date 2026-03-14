# Charts API Contracts (v1.2)

## Scope and auth

- Protected routes require `Authorization: Bearer <token>`.
- Write endpoints support `Idempotency-Key`.
- Canonical viewport storage is per `(user_id, chart_id, page)` in `chart_page_user_prefs`.
- `projects.chart_viewport_prefs` is deprecated for new clients.

---

## Upload Chart

- **Method**: `POST`
- **Path**: `/api/v1/me/charts`
- **Body**: multipart
  - `file`: PDF (required)
  - `song_id`: int (required)
  - `project_id`: int (required)
- **Upload limits**:
  - single PDF max: `2MB`
  - route throttle: `5` requests per minute per authenticated user
- **Semantics**: upsert by `(owner_user_id, project_id, song_id)`
  - First upload creates a new chart and returns `201`.
  - Re-uploading the same PDF for the same song/project returns `200` and leaves the existing chart unchanged.
  - Re-uploading a different PDF for the same song/project returns `200`, keeps the existing chart ID, replaces the source PDF in place, clears prior render rows/files, resets `has_renders=false` and `page_count=0`, and dispatches a fresh render.
- **Response fields**:
  - `chart.id`
  - `chart.project_id`
  - `chart.song`
  - `chart.has_renders`
  - `chart.page_count`
  - `chart.created_at`
  - `chart.updated_at`

---

## Viewport Preferences

### Get viewport
- **Method**: `GET`
- **Path**: `/api/v1/me/charts/{chartId}/pages/{page}/viewport`

### Save viewport
- **Method**: `PUT`
- **Path**: `/api/v1/me/charts/{chartId}/pages/{page}/viewport`
- **Headers**: `Idempotency-Key` (recommended)
- **Body**:

```json
{
  "zoom_scale": 1.2,
  "offset_dx": 0,
  "offset_dy": 0
}
```

---

## Chart Annotations

### Get saved annotation
- **Method**: `GET`
- **Path**: `/api/v1/me/charts/{chartId}/pages/{page}/annotations/latest`
- Semantics: last-write-wins per `(user, chart, page)`.

### Save annotation
- **Method**: `POST`
- **Path**: `/api/v1/me/charts/{chartId}/pages/{page}/annotations`
- **Headers**: `Idempotency-Key`
- **Body**:

```json
{
  "local_version_id": "uuid",
  "created_at": "2026-02-15T18:00:00Z",
  "strokes": []
}
```

Notes:
- Each save replaces the previously saved annotation for that `(user, chart, page)`.
- `local_version_id` remains client-provided so retries stay idempotent.

---

## Chart Render and Download

- `GET /api/v1/me/charts/{chartId}`
- `GET /api/v1/me/charts?project_id={projectId}&song_id={songId}`
- `GET /api/v1/me/charts/{chartId}/render-status`
- `GET /api/v1/me/charts/{chartId}/signed-url`
- `GET /api/v1/me/charts/{chartId}/page?page={page}&theme={light|dark}`
- `GET /api/v1/me/charts/{chartId}/page-urls?theme={light|dark}`
- `POST /api/v1/me/charts/{chartId}/render` (`Idempotency-Key` supported)
- `DELETE /api/v1/me/charts/{chartId}` (`Idempotency-Key` supported)

### Render Status Endpoint

- **Method**: `GET`
- **Path**: `/api/v1/me/charts/{chartId}/render-status`
- **Purpose**: Single-call render verification for mobile bulk import and cache preflight.
- **Response**:
  - `status`: `ready | pending | failed`
  - `ready`: boolean
  - `pending`: boolean
  - `failure_reason`: nullable string machine code
    - `source_pdf_missing`
    - `render_file_missing`
    - `render_metadata_inconsistent`
  - `page_count`: integer
  - `render_count`: integer
  - `expected_render_count`: nullable integer
  - `has_renders`: boolean
  - `missing_render_file_count`: integer

Notes:
- `pending` means the chart exists in DB but image rendering is not complete yet.
- `failed` means artifacts are inconsistent/missing and upload verification should fail.
- `failed` with `render_metadata_inconsistent` means render rows and chart metadata (`page_count`, `has_renders`) disagree.
- Clients should prefer this endpoint over per-page render URL checks to reduce request fan-out.
- Chart resource payloads now include `updated_at` so clients can invalidate cached page images when an in-place chart replacement lands.
- Pass `?verify_files=true` to enable per-file storage existence checks. Omitted by default for performance.

### Batch Page URLs Endpoint

- **Method**: `GET`
- **Path**: `/api/v1/me/charts/{chartId}/page-urls?theme={light|dark}`
- **Purpose**: Fetch signed URLs for all chart pages in a single request, reducing N round trips to 1.
- **Response**:
  - `pages`: array of page objects
    - `page`: integer (1-indexed page number)
    - `url`: string (signed URL, 15-minute TTL)
    - `served_theme`: string (only present when served theme differs from requested theme)
  - `updated_at`: nullable ISO 8601 string

Notes:
- Returns `404` if no renders are available.
- Clients should prefer this endpoint over per-page `page` requests when loading all chart pages.

### Cache Bundle Endpoint

- **Method**: `POST`
- **Path**: `/api/v1/me/charts/cache-bundle`
- **Purpose**: Stream a zip archive of rendered chart page images for the authenticated user.
- **Rate limit**: 2 requests per 5 minutes.
- **Response**: `application/zip` streamed download.

**Request body** (optional, `application/json`):
```json
{
  "known_revisions": {
    "42": "2026-03-10T08:30:00+00:00",
    "43": "2026-03-09T15:00:00+00:00"
  }
}
```

When `known_revisions` is provided, charts whose `updated_at` matches the given value are excluded from the zip. This enables delta/incremental downloads — the client only receives charts that are new or have been updated since its last sync.

The zip contains:
- `manifest.json` at the root with metadata for included charts.
- `{chartId}/{pageNumber}_{theme}.png` for each rendered page image.

Page numbers in the zip are **0-indexed** (matching mobile convention, converted from the 1-indexed API convention).

**manifest.json structure**:
```json
{
  "generated_at": "2026-03-14T12:00:00+00:00",
  "charts": [
    {
      "id": 42,
      "project_id": 1,
      "page_count": 2,
      "updated_at": "2026-03-10T08:30:00+00:00",
      "pages": [
        { "page_number": 0, "theme": "light", "path": "42/0_light.png" },
        { "page_number": 0, "theme": "dark", "path": "42/0_dark.png" }
      ]
    }
  ]
}
```

Notes:
- Returns `404` if the user has no charts with renders.
- `updated_at` per chart serves as the revision token for cache staleness detection.
- Render files that are missing from storage are silently skipped.
- When all charts match `known_revisions`, the zip contains only `manifest.json` with an empty `charts` array.
- Mobile clients use this as the primary refresh strategy, falling back to per-image downloads if the endpoint is unavailable.
