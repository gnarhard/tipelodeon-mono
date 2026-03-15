# Repertoire API Contracts (v1.3)

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

Plan limits:
- Basic-owned projects are hard-capped at `100` repertoire songs.
- Pro-owned projects do not have a repertoire-song cap.
- Direct add and copy flows return `422` with `code=repertoire_limit_reached`
  when the Basic cap would be exceeded.
- Bulk import stays `200 OK` and reports skipped items via `limit_reached`
  counters and per-song `action=limit_reached`.

Song versions:
- Field: `version_label`
- Scope: stored on `project_songs`
- Type: nullable string (max 50 chars) in API; empty string `''` in DB for primary
- API representation: `null` for the primary/default version, non-empty string
  for alternate versions (e.g., `"Acoustic"`, `"Solo"`, `"Electric"`)
- Unique constraint: `(project_id, song_id, version_label)`
- Each version has independent metadata (key, capo, tuning, notes, etc.) and charts
- Alternate versions count against the repertoire limit
- Public repertoire only shows primary versions

Instrumental flag:
- Field: `instrumental`
- Scope: stored on `project_songs`
- Type: boolean
- Default: `false`
- When `true`, repertoire and audience song lists append ` (instrumental)` to
  the displayed song title

Mashup flag:
- Field: `mashup`
- Scope: stored on `project_songs`
- Type: boolean
- Default: `false`
- When `true`:
  - Song does not participate in global dedup (skips `normalized_key` lookup)
  - Global metadata is not written to the `songs` table
  - Metadata enrichment will most likely be inaccurate
  - Repertoire list shows a red "Mashup" pill
  - Display title appends ` (mashup)`

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
- Supports theme, `instrumental`, `mashup`, and project-song `notes` in request
  and response payloads.

Limit error:

```json
{
  "code": "repertoire_limit_reached",
  "message": "This project has reached its repertoire limit for the current plan.",
  "project_id": 1,
  "repertoire_song_limit": 100
}
```

### Update
- `PUT /repertoire/{projectSongId}`
- Supports theme, `instrumental`, `mashup`, and project-song `notes` updates at
  project override level.

Project-song notes:
- Field: `notes`
- Scope: stored on `project_songs` and applies across setlists within the
  project
- Type: nullable string
- Validation: max `3000` characters
- Response: included on repertoire list and single-song payloads

Demote to learn:
- Field: `demote_to_learn`
- Type: boolean (optional)
- When `true`: creates a `ProjectLearningSong` for the underlying song (with
  auto-resolved YouTube and Ultimate Guitar URLs), then deletes the
  `ProjectSong` from the repertoire.
- Response: `{ "message": "Song demoted to learning list.", "demoted": true }`
- If the song already exists in the learning list, the existing record is
  updated (no duplicate).

### Create version
- `POST /repertoire/{projectSongId}/versions`
- Creates an alternate version of an existing repertoire song.
- Body:

```json
{
  "version_label": "Acoustic",
  "performed_musical_key": "G",
  "tuning": "Open G",
  "capo": 0,
  "copy_charts": true
}
```

- `version_label` is required (string, max 50 chars).
- All metadata fields from Create are accepted (except `song_id`/`title`/`artist`).
- Optional `copy_charts` (boolean, default `false`): clones charts from the
  source version to the new version.
- Returns `409` if a version with the same label already exists for the song.
- Returns `422` with `code=repertoire_limit_reached` if plan limit exceeded.
- Update via `PUT /repertoire/{projectSongId}` supports `version_label` to rename.

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
- Large chart batches may queue AI enrichment separately from the tighter
  interactive metadata throttle. When the bulk fair-use burst bucket is full,
  uploads still succeed but affected entries are reported as `deferred`.
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
- On a Basic-owned destination project, copy is rejected once the project has
  reached `100` repertoire songs.

---

## Import from Image

- `POST /repertoire/import-from-image`
- Accepts a single image file containing a list of songs (e.g., a setlist,
  printed song list, handwritten notes, or screenshot).
- Uses AI vision to extract song titles and artists from the image.
- For each extracted song:
  - Uses `Song::findOrCreateByTitleAndArtist` for global dedup.
  - Saves AI-provided metadata (energy_level, era, genre, theme,
    original_musical_key, duration_in_seconds) to newly created songs only.
  - Creates `ProjectSong` via `firstOrCreate` — duplicates are skipped.
  - Checks repertoire limit; songs beyond the cap are reported as
    `limit_reached`.
- Tracks AI usage via `AccountUsageService::recordAiOperation()` with
  category `image_import`.
- Request validation:
  - `image`: required, file, mimetypes `image/jpeg,image/png,image/webp,image/heic`, max 10MB.
- Throttle: `throttle:chart-uploads`.

Response (`200`):

```json
{
  "message": "Extracted 12 songs. Imported 10, skipped 2 duplicates.",
  "extracted": 12,
  "imported": 10,
  "duplicates": 2,
  "limit_reached": 0,
  "songs": [
    { "title": "...", "artist": "...", "action": "imported", "song_id": 123 },
    { "title": "...", "artist": "...", "action": "duplicate", "duplicate_of": "..." },
    { "title": "...", "artist": "...", "action": "limit_reached" }
  ]
}
```

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
