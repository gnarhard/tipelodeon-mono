# Repertoire API Contracts (v1.5)

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

## Tempo (BPM) metadata

- `songs.tempo_bpm`: nullable canonical tempo (small int, 30–300).
- `project_songs.tempo_bpm`: nullable project/version override.
- Effective tempo: `project_songs.tempo_bpm ?? songs.tempo_bpm`.
- Validation: integer `30 <= n <= 300`. Out-of-range values return `422`.
- AI metadata extraction (chart identification + title/artist enrichment +
  bulk import) returns `tempo_bpm` alongside `original_musical_key` and
  `duration_in_seconds`. Sanitizer drops values outside the 30–300 range.
- API responses include `tempo_bpm` (resolved) and `original_tempo_bpm`
  (the canonical Song's value, for surfacing global vs override in
  the UI).

---

## Repertoire

Plan limits:
- Free-owned projects are hard-capped at `20` repertoire songs.
- Pro-owned projects are hard-capped at `200` repertoire songs.
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
- Body (all optional):
  - `include_charts` (boolean, default `true`) — clone the owner's charts and
    renders onto the new alternate.
  - `include_annotations` (boolean, default `true`) — clone the owner's latest
    chart annotations onto the cloned charts. Only honored when
    `include_charts=true`; silently ignored otherwise.

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
- Accepted on `POST /repertoire` to create a versioned song directly
- When `version_label` is provided on create, the duplicate check compares
  both `song_id` and `version_label` — the same song may have multiple
  versions with different labels
- Clients group songs by `song_id` and display versions indented beneath the
  parent song title. If a song has only one version with no label, no indented
  versions are shown. When multiple versions exist, each shows its own metadata
- When displayed in a setlist, the version label is appended to the song title
  in red text

Instrumental flag:
- Field: `instrumental`
- Scope: stored on `project_songs`
- Type: boolean
- Default: `false`
- When `true`, repertoire and audience song lists append ` (instrumental)` to
  the displayed song title

Original flag:
- Field: `original`
- Scope: stored on `project_songs`
- Type: boolean
- Default: `false`
- When `true`, repertoire and audience song lists append ` (original)` to
  the displayed song title. Indicates the performer wrote the song
  themselves (as opposed to a cover).
- Independent of the audience-side `is_original` field on `POST /requests`
  (see public.md), which flags an audience *request* for an original song.

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

Learned flag:
- Field: `learned`
- Scope: stored on `project_songs`
- Type: boolean
- Default: `true`
- When `false`, the song is still in the performer's repertoire but is
  conceptually "to-learn" — surfaced by filter, sort, and bulk update.
- Filter: `GET /repertoire?learned=1` / `?learned=0`.

Reference links:
- Fields on `songs`: `youtube_video_url`, `ultimate_guitar_url` (nullable).
- Scope: global per canonical song, shared across all projects that use the
  song.
- Auto-resolution: on repertoire song create, and on any update that flips
  `learned` to `false`, the backend resolves missing URLs via
  `YoutubeVideoResolver` (falls back to a YouTube search URL) and builds an
  Ultimate Guitar title search URL. Existing URLs are never overwritten.
- Returned in the nested `song` object on repertoire list/show payloads.

### List
- `GET /repertoire`
- Supports `theme` and `learned` filters.
- Repertoire list items include:
  - `instrumental`
  - `original`
  - `learned`
  - `performance_count`
  - `request_count`
  - `total_tip_amount_cents`
  - `last_performed_at`
  - Nested `song` object: `youtube_video_url`, `ultimate_guitar_url`

### Create
- `POST /repertoire`
- Supports theme, `instrumental`, `original`, `mashup`, `is_public`,
  `learned`, `version_label`, and project-song `notes` in request and
  response payloads.
- `version_label` is optional (nullable string, max 50 chars). When provided,
  the duplicate check compares both `song_id` and `version_label` instead of
  `song_id` alone, allowing multiple versions of the same song.
- On create, the backend auto-resolves the canonical Song's
  `youtube_video_url` and `ultimate_guitar_url` when they are null.

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
- Supports `title`, `artist`, theme, `instrumental`, `original`, `mashup`,
  `is_public`, `learned`, and project-song `notes` updates at project
  override level.
- Title/artist changes are saved to `project_songs` only (the `songs` table
  and `song_id` are not modified).
- When `learned` flips to `false`, the backend backfills the canonical
  Song's reference URLs if they are currently null.

Project-song notes:
- Field: `notes`
- Scope: stored on `project_songs` and applies across setlists within the
  project
- Type: nullable string
- Validation: max `3000` characters
- Response: included on repertoire list and single-song payloads

### Bulk update
- `POST /repertoire/bulk-update`
- Body: `{ project_song_ids: [int], fields: { is_public?, learned?, mashup?, instrumental?, original? } }`
- Limits: 1–500 IDs per request; at least one whitelisted field is required.
- Access: IDs outside the current user/project are silently skipped.
- Response: `{ "message": "Updated N song(s).", "updated_count": N }`.
- Single DB transaction.
- This replaces the old per-row `demote_to_learn` flag — callers now flip
  `learned: false` through either this bulk endpoint or the standard
  update endpoint.

### Delete
- `DELETE /repertoire/{projectSongId}`

### Clone (create alternate version from existing song)
- `POST /repertoire/{projectSongId}/clone`
- Creates a new `project_song` row for the **same** `song_id` and `project_id`
  as the source, owned by the caller, with a distinct `version_label`.
- Scoped to the caller: the source `projectSongId` must belong to the caller
  in this project. Members can clone their own copies; owners can clone
  owner-scoped rows.
- Body:

```json
{
  "version_label": "Acoustic",
  "include_charts": true,
  "include_annotations": true
}
```

- Field rules:
  - `version_label` (required string, 1–50 chars). Must not collide with an
    existing `(project_id, user_id, song_id, version_label)` row for the
    caller. Empty string is rejected.
  - `include_charts` (optional boolean, default `true`). When `true`, each
    chart attached to the source `project_song` is cloned (including
    `ChartRender` rows and storage paths) and linked to the new
    `project_song`.
  - `include_annotations` (optional boolean, default `true`). When `true`,
    the latest `chart_annotation_versions` row for each cloned source page is
    copied onto the corresponding cloned chart, rewritten to the caller as
    `owner_user_id`. Only honored when `include_charts=true`; silently
    ignored otherwise.
- Copied metadata mirrors the source `project_song` (title, artist, energy,
  genre, theme, instrumental, original, mashup, is_public, performed key,
  tuning, capo, notes, needs_improvement). `learned` is preserved.
  Performance counters (`performance_count`, `last_performed_at`) are
  **not** copied.
- Response `201`:

```json
{
  "message": "Alternate version created.",
  "project_song": { /* ProjectSongResource */ },
  "copied_charts": 1,
  "copied_annotations": 3
}
```

- Errors:
  - `403` if the caller does not own/have access to the source `project_song`.
  - `409` if `version_label` already exists for the caller on this song.
  - `422` with `code=repertoire_limit_reached` if the clone would exceed the
    plan cap.

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
data (energy_level, era, genre, theme, original_musical_key,
duration_in_seconds, tempo_bpm).

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
          "duration_in_seconds": 354,
          "tempo_bpm": 72
        },
        "is_duplicate": false,
        "duplicate_of": null
      }
    ],
    "ai_calls_used": 3
  }
}
```

`is_duplicate` is `true` when a non-mashup `ProjectSong` with an empty
`version_label` already exists in the project for this normalized
title+artist; `duplicate_of` is then `"Title - Artist"` of the existing
song. Clients should route these items to the duplicates section instead
of the review queue. Mashups are not flagged here because they bypass
repertoire dedup at confirm time.

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
      "duration_in_seconds": 354,
      "tempo_bpm": 72
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
  "include_charts": true,
  "include_annotations": true
}
```

Behavior:
- Copies only the selected source `project_song` rows and their overrides.
- `source_project_song_ids` must all belong to `source_project_id`.
- If `include_charts=true`, copies linked chart PDFs and rendered chart images
  for the selected songs into the destination project.
- If `include_annotations=true`, copies the latest saved chart annotations for
  each cloned chart page, rewriting `owner_user_id` to the authenticated user.
  Only honored when `include_charts=true`; silently ignored otherwise.
- Default: `include_charts` is `false` and `include_annotations` is `false`
  when omitted, preserving prior behavior. Clients should default both to
  `true` in new UI flows.
- On a capped destination project (Free: 20, Pro: 200), copy is rejected once
  the project has reached its repertoire song limit.
- Response `data` block adds `copied_annotations` alongside `copied_songs` and
  `copied_charts`.

---

## Import from Image

- `POST /repertoire/import-from-image`
- Accepts a single image file containing a list of songs (e.g., a setlist,
  printed song list, handwritten notes, or screenshot).
- Uses AI vision to extract song titles and artists from the image.
- For each extracted song:
  - Uses `Song::findOrCreateByTitleAndArtist` for global dedup.
  - Saves AI-provided metadata (energy_level, era, genre, theme,
    original_musical_key, duration_in_seconds, tempo_bpm) to newly created songs only.
  - Creates `ProjectSong` via `firstOrCreate` — duplicates are skipped.
  - Checks repertoire limit; songs beyond the cap are reported as
    `limit_reached`.
- Tracks AI usage via `AccountUsageService::recordAiOperation()` with
  category `image_import`.
- Request validation:
  - `image`: required, file, mimetypes `image/jpeg,image/png,image/webp,image/heic`, max 10MB.
  - `extract_only`: optional boolean. When truthy the server extracts and
    deduplicates the songs but does not create any `ProjectSong` rows; the
    repertoire-limit check is also skipped (it's enforced at the eventual
    confirm step). Each non-duplicate result is returned with action
    `extracted` plus a `metadata` object so the bulk-import client can
    route it through the standard enrichment + review pipeline. Required
    by the bulk-import flow so no entry point bypasses Review and lands
    directly in Completed.
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
    { "title": "...", "artist": "...", "action": "extracted", "song_id": 123, "metadata": { "energy_level": "high", "genre": "Rock" } },
    { "title": "...", "artist": "...", "action": "duplicate", "duplicate_of": "..." },
    { "title": "...", "artist": "...", "action": "limit_reached" }
  ]
}
```

`imported` results only appear when the request omits `extract_only`. The
bulk-import client always sets `extract_only=1`, so its responses contain
`extracted`, `duplicate`, and `limit_reached` actions only.

---

## Import from Text

- `POST /repertoire/import-from-text`
- Accepts a free-form text blob containing one or more songs (e.g., a
  pasted setlist, list of titles, or messy "Artist - Title" rows). The
  server uses AI to parse the text into clean (title, artist) pairs,
  handling varied separators (` - `, `-`, `:`, `by`), missing punctuation,
  smart quotes, parenthetical version notes, blank lines, and obvious
  artist typos.
- For each parsed song:
  - Uses `Song::findOrCreateByTitleAndArtist` for global dedup.
  - Creates `ProjectSong` via `firstOrCreate` — duplicates are skipped.
  - Checks repertoire limit; songs beyond the cap are reported as
    `limit_reached`.
- Tracks AI usage via `AccountUsageService::recordAiOperation()` with
  category `text_import`.
- Request validation:
  - `text`: required, string, max 20000 characters.
  - `extract_only`: optional boolean. Same semantics as
    `/repertoire/import-from-image` — when truthy the server parses and
    deduplicates without creating `ProjectSong` rows so the bulk-import
    client can route results through review + enrichment. The
    `extracted` action is used in this case (no per-song AI metadata is
    returned; enrichment runs as a separate `bulk-enrich` step).
- Throttle: `throttle:chart-uploads`.

Response (`200`):

```json
{
  "message": "Extracted 12 song(s). Imported 10. Skipped 2 duplicate(s).",
  "extracted": 12,
  "imported": 10,
  "duplicates": 2,
  "limit_reached": 0,
  "songs": [
    { "title": "...", "artist": "...", "action": "imported", "song_id": 123 },
    { "title": "...", "artist": "...", "action": "extracted", "song_id": 123 },
    { "title": "...", "artist": "...", "action": "duplicate", "duplicate_of": "..." },
    { "title": "...", "artist": "...", "action": "limit_reached" }
  ]
}
```

Items returned without an artist (the model could not infer one) are
silently skipped server-side rather than surfaced as failures, since the
downstream review pipeline keys on a (title, artist) pair.

---

## To-Learn songs

As of v1.3, to-learn songs live in the regular repertoire as a
`ProjectSong` with `learned=false`. The legacy `/learning-songs` endpoints
and `project_learning_songs` table have been removed; see the "Learned
flag", "Reference links", and "Bulk update" sections above for the current
contract. Existing learning-song rows were migrated into `project_songs`
on deploy.
