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

## Title & artist metadata

- `songs.title` / `songs.artist`: canonical global values used for deduplication.
- `project_songs.title` / `project_songs.artist`: project-specific display values (NOT NULL).
- On create, title/artist are always saved to `project_songs`.
- On update, title/artist changes are saved to `project_songs` only; the `songs`
  table and `song_id` link are not modified.
- API responses include flat `title`/`artist` keys (project-specific) and a nested
  `song` object with canonical `title`/`artist`.

---

## Repertoire

Plan limits:
- Free-owned projects are hard-capped at `20` repertoire songs.
- Basic-owned projects are hard-capped at `200` repertoire songs.
- Pro-owned projects do not have a repertoire-song cap.
- Direct add and copy flows return `422` with `code=repertoire_limit_reached`
  when the plan cap would be exceeded.
- Bulk import stays `200 OK` and reports skipped items via `limit_reached`
  counters and per-song `action=limit_reached`.
- On downgrade, existing songs above the new limit remain accessible (read-only
  grace) but no new songs can be added until the user is within limits.

Member repertoire isolation:
- Each project member has their own independent copy of songs within the project.
- `project_songs.user_id`: NOT NULL FK to `users`, scopes songs per member.
- `project_songs.source_project_song_id`: nullable FK to `project_songs` (self-ref),
  links a member's copy back to the owner's canonical version.
- Unique constraint: `(project_id, user_id, song_id, version_label)`.
- On member join: all owner songs (with charts) are copied to the member via
  `SyncRepertoireToMember` job.
- On owner song creation: `FanOutSongToMembers` job copies the new song to all
  current members. Does not overwrite if the member already has the song.
- Owner edits do NOT auto-propagate to members.
- API responses include `source_project_song_id` (nullable int) and `is_owner_copy`
  (boolean; true when `source_project_song_id` is null).

Pull Owner Copy:
- `POST /repertoire/{projectSongId}/pull-owner-copy`
- Fetches the owner's current version via `source_project_song_id` and adds it
  as a new alternate version on the member's song.
- Label: `"Owner's Version (synced Mar 23, 2026)"`.
- Member's existing versions are untouched.
- Returns `422` if the song has no linked owner version.
- Returns `404` if the owner's version no longer exists.

Song versions:
- Field: `version_label`
- Scope: stored on `project_songs`
- Type: nullable string (max 50 chars) in API; empty string `''` in DB for primary
- API representation: `null` for the primary/default version, non-empty string
  for alternate versions (e.g., `"Acoustic"`, `"Solo"`, `"Electric"`)
- Unique constraint: `(project_id, user_id, song_id, version_label)`
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

Public visibility:
- Field: `is_public`
- Scope: stored on `project_songs`
- Type: boolean
- Default: `true`
- When `false`, the song remains in the performer's repertoire but is hidden
  from the audience on the public request page
- Public repertoire API and Livewire page filter out songs where
  `is_public=false`

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
- Supports theme, `instrumental`, `mashup`, `is_public`, and project-song `notes`
  in request and response payloads.

Limit error:

```json
{
  "code": "repertoire_limit_reached",
  "message": "This project has reached its repertoire limit for the current plan.",
  "project_id": 1,
  "repertoire_song_limit": 20
}
```

### Update
- `PUT /repertoire/{projectSongId}`
- Supports `title`, `artist`, theme, `instrumental`, `mashup`, `is_public`, and
  project-song `notes` updates at project override level.
- Title/artist changes are saved to `project_songs` only (the `songs` table
  and `song_id` are not modified).

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

## Bulk Import (Multi-Phase Flow)

The bulk import is a three-phase process:
1. **Upload** — Upload PDFs, trigger AI identification
2. **Enrich** — Fetch enriched metadata for all identified songs
3. **Confirm** — User reviews metadata, then finalizes import

### Phase 1: Upload — `POST /repertoire/bulk-upload`

- Limits:
  - max files per request: `20`
  - max size per PDF: `2MB`
- Uploads PDF chart files and triggers AI identification.
- Does **not** create Song or ProjectSong records.
- Large chart batches may queue AI enrichment via the batch API. When the
  bulk fair-use burst bucket is full, uploads still succeed but affected
  entries are reported as `deferred`.
- Filename metadata supports: `key`, `capo`, `tuning`, `energy`, `era`, `genre`, `theme`.
- Theme filename token example: `-- theme=love`.

Request (multipart):
- `files[]` — PDF files
- `existing_songs_only` — boolean

Response (`200`):

```json
{
  "data": {
    "charts": [
      {
        "chart_id": 123,
        "filename": "Song - Artist.pdf",
        "import_status": "queued",
        "import_metadata": { "title": "Song", "artist": "Artist" }
      }
    ],
    "message": "Uploaded 5 chart(s). 3 queued for identification."
  }
}
```

Chart render-status (`GET /me/charts/{chartId}/render-status`) now includes
`import_metadata` in the response. When `import_status` is `identified`,
`import_metadata` contains the AI-identified title, artist, and enrichment
data (energy_level, era, genre, theme, original_musical_key, duration_in_seconds).

### Phase 2: Enrich — `POST /repertoire/bulk-enrich`

- Fetches enriched metadata for a batch of songs by title+artist.
- Uses `SongMetadataLookupService`: checks songs table, cache, then AI.
- Respects interactive AI quota limits.

Request:

```json
{
  "songs": [
    { "title": "Bohemian Rhapsody", "artist": "Queen" }
  ]
}
```

Response (`200`):

```json
{
  "data": {
    "songs": [
      {
        "title": "Bohemian Rhapsody",
        "artist": "Queen",
        "source": "songs_table",
        "metadata": {
          "energy_level": "high",
          "era": "1970s",
          "genre": "Rock",
          "theme": "story",
          "original_musical_key": "Bb",
          "duration_in_seconds": 354
        }
      }
    ],
    "ai_calls_used": 3
  }
}
```

### Phase 3: Confirm — `POST /repertoire/bulk-import/confirm`

- Finalizes the import with user-confirmed metadata.
- Creates Song records (findOrCreate), ProjectSong records, and links charts.
- Applies user-confirmed metadata to Song records (fills null fields only).
- Throttle: `throttle:chart-uploads`.

Request:

```json
{
  "songs": [
    {
      "title": "Bohemian Rhapsody",
      "artist": "Queen",
      "chart_id": 123,
      "mashup": false,
      "theme": "story",
      "energy_level": "high",
      "era": "1970s",
      "genre": "Rock",
      "original_musical_key": "Bb",
      "duration_in_seconds": 354
    }
  ],
  "existing_songs_only": false
}
```

Response (`200`):

```json
{
  "data": {
    "message": "Imported 10 song(s), skipped 2 duplicate(s).",
    "imported": 10,
    "duplicates": 2,
    "limit_reached": 0,
    "no_match": 0,
    "songs": [
      { "title": "...", "artist": "...", "action": "imported", "song_id": 123, "chart_id": 456 },
      { "title": "...", "artist": "...", "action": "duplicate", "duplicate_of": "Song - Artist" },
      { "title": "...", "artist": "...", "action": "limit_reached" },
      { "title": "...", "artist": "...", "action": "no_match" }
    ]
  }
}
```

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
- On a capped destination project (Free: 20, Basic: 200), copy is rejected once
  the project has reached its repertoire song limit.

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
- `promote_to_repertoire` (optional, boolean, PUT only) — creates a
  `ProjectSong`, deletes the learning song, and returns
  `{ "message": "Song promoted to repertoire.", "promoted": true }`
