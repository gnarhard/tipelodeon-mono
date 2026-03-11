# Repertoire API Contracts (v1.2)

## Scope and auth

- Protected routes require `Authorization: Bearer <token>`.
- All write endpoints support `Idempotency-Key`.
- Route prefix: `/api/v1/me/projects/{project_id}`.

---

## Theme metadata

- `songs.theme`: nullable global theme.
- `project_songs.theme`: nullable project override.
- Effective theme: `project_songs.theme ?? songs.theme`.
- Canonical enum values:
  `love`, `party`, `worship`, `story`, `st_patricks`, `christmas`, `halloween`, `patriotic`.
- Validation is strict enum for all theme inputs (query/body/import tokens).
- Invalid theme inputs now return `422` (intentional breaking change).
- Metadata enrichment and API responses always emit canonical enum values.
- Backfill migration (`*_backfill_song_themes_to_canonical_song_theme_enum.php`) applies
  deterministic map values, and any unmapped/invalid legacy theme is forced to `story`.

---

## Repertoire

Instrumental flag:
- Field: `instrumental`
- Scope: stored on `project_songs`
- Type: boolean
- Default: `false`
- When `true`, repertoire and audience song lists append ` (instrumental)` to
  the displayed song title

### List
- `GET /repertoire`
- Supports `theme` filter.
- Repertoire list items include:
  - `instrumental`
  - `performance_count`
  - `request_count`
  - `total_tip_amount_cents`
  - `last_performed_at`

### Create
- `POST /repertoire`
- Supports theme, `instrumental`, and project-song `notes` in request and
  response payloads.

### Update
- `PUT /repertoire/{projectSongId}`
- Supports theme, `instrumental`, and project-song `notes` updates at project
  override level.

Project-song notes:
- Field: `notes`
- Scope: stored on `project_songs` and applies across setlists within the
  project
- Type: nullable string
- Validation: max `3000` characters
- Response: included on repertoire list and single-song payloads

### Delete
- `DELETE /repertoire/{projectSongId}`

### Log performance
- `POST /repertoire/{projectSongId}/performances`
- When a performance session is active, session-aware completion should use `/performances/current/complete`.

---

## Bulk Import

- `POST /repertoire/bulk-import`
- Limits:
  - max files per request: `20`
  - max size per PDF: `2MB`
- Supports either:
  - chart-backed imports via `files[]`
  - metadata-only imports via `items[]` rows parsed from CSV
- When importing without charts, each `items[]` row must include:
  - `title`
  - `artist`
- Optional item metadata:
  - `theme`
- Filename metadata supports: `key`, `capo`, `tuning`, `energy`, `era`, `genre`, `theme`.
- Theme filename token example: `-- theme=love`.

---

## Copy Repertoire

- `POST /repertoire/copy-from`
- Body:

```json
{
  "source_project_id": 1,
  "source_project_song_ids": [10, 11, 12],
  "include_charts": true
}
```

Behavior:
- Copies only the selected source `project_song` rows and their overrides.
- `source_project_song_ids` must all belong to `source_project_id`.
- If `include_charts=true`, copies linked chart PDFs and rendered chart images
  for the selected songs into the destination project.

---

## To-Learn list

- `GET /learning-songs`
- `POST /learning-songs`
- `PUT /learning-songs/{learningSongId}`
- `DELETE /learning-songs/{learningSongId}`

Fields:
- `youtube_video_url` (optional)
  - Backend query: `"<title> by <artist> music video"`
  - If omitted or null on create, backend attempts to resolve the top YouTube
    video result (by view count) and stores a direct watch URL
  - If omitted on update and current value is null, backend backfills using the
    same resolver behavior
  - If resolver is unavailable or no video is found, backend stores fallback
    search URL:
    `https://www.youtube.com/results?search_query=<title+by+artist+music+video>`
- `ultimate_guitar_url` (optional, link/search only; no scraping)
- `notes` (optional)
