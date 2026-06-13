# Repertoire API Contracts (v1.7)

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

Repertoire size:
- Repertoire is uncapped by default. The 422 `repertoire_limit_reached`
  response and the bulk-import `limit_reached` counters are preserved for
  forward compatibility but are not currently emitted.

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

Original-artist-only catalog (covers) (v1.7):
- The global `songs` **catalog** table only ever holds **ORIGINAL-ARTIST**
  metadata. A **COVER** — a recording/performance whose performing artist
  differs from the original recording artist — must NEVER create or populate a
  global `songs` catalog row with metadata under the performing artist.
- A cover still gets a `songs` row for **identity/dedup** (so multiple
  performers of the same song share a `song_id`), but that row stays a **bare
  identity shell**: `title` + `artist` + `normalized_key` only, with every
  global metadata column (`album`, `energy`, `danceability`, `era`, `genre`,
  `theme`, `original_musical_key`, `time_signature`, `duration_in_seconds`,
  `tempo_bpm`) left NULL.
- A cover's enriched metadata is written to the **`project_songs`** copy
  instead — exactly like an already-existing catalog song. The musician owns
  their per-project copy; the shared catalog is never polluted with a cover's
  performed-artist metadata.
- The signal originates in the AI identity step (see "Cover detection" below)
  and is carried through `import_metadata`. At confirm it is read from the
  per-item `is_cover` flag, falling back to the chart's `import_metadata` when
  the client omits it.
- A cover catalog row carries an internal marker (in `songs.field_sources`, not
  a new column) so that **metadata enrichment** (`EnrichImportedSongs`,
  `EnrichImportedChart`/lookup) and the **`songs:backfill-metadata`** command
  both skip it permanently — they never re-populate a cover row.
- Legacy cleanup: `songs:flag-cover-catalog-rows` re-classifies existing
  non-verified catalog rows and (with `--apply`) demotes detected covers to
  bare identity shells — marking them and clearing their global metadata. It
  **never deletes** a catalog row (rows are referenced by `project_songs`,
  `charts`, `requests`, and the dedup key); the default mode is a dry-run
  report. Admin-verified rows are never touched.

Cover detection (`is_cover` / `original_artist`) (v1.7):
- The AI identity step (chart-image identify, chart-text extract, and the
  filename/paste canonicalize hint) now also returns:
  - `is_cover` (boolean) — `true` only when the model is confident the
    performing artist differs from the original recording artist. Defaults to
    `false` (treated as an original) when the model is unsure or omits it.
  - `original_artist` (string|null) — the full registered name of the artist
    who originally recorded the song (equals the performing `artist` when it is
    not a cover); `null` when the model cannot name one. An unknown original
    artist does **not** downgrade a cover into a catalog write — the cover is
    still kept out of the catalog.
- Both fields are stored in the chart's `import_metadata` and surface in the
  `render-status` / bulk-enrich item payloads alongside the other identity
  fields, so the client can show a cover badge in review.

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
- Stored in the `song_reference_links` table and serialized as a
  `reference_links` array on the nested `song` object. Each entry is
  `{ id, kind, label, url, position }`, ordered by `position`.
- `kind` is one of the system kinds `youtube`, `ultimate_guitar`, `lyrics`
  (auto-created on every Song, undeletable, `label` null → a default display
  label) or `custom` (user-owned, full CRUD via the reference-link endpoints).
- Scope: global per canonical song, shared across all projects that use the
  song.
- Auto-resolution: on Song create the backend seeds the three system links
  with free search URLs — a YouTube results search (upgradable on-demand to a
  direct video URL), an Ultimate Guitar title search, and a Google
  `<title> <artist> lyrics` search. Existing rows are never overwritten;
  `songs:backfill-reference-links` reconciles songs created before a kind
  existed.
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
  - Nested `song` object: `reference_links` (see Reference links above)

### Create
- `POST /repertoire`
- Supports theme, `instrumental`, `original`, `mashup`, `is_public`,
  `learned`, `version_label`, and project-song `notes` in request and
  response payloads.
- `version_label` is optional (nullable string, max 50 chars). When provided,
  the duplicate check compares both `song_id` and `version_label` instead of
  `song_id` alone, allowing multiple versions of the same song.
- On create, the backend seeds the canonical Song's system reference links
  (`youtube`, `ultimate_guitar`, `lyrics`) when they are missing.

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
- `version_label` rename returns `409` when the caller already has another
  row for the same song with the target label in this project. The check is
  scoped to the unique `(project_id, user_id, song_id, version_label)`
  index — labels held only by other members never block a rename.
- When `learned` flips to `false`, the backend ensures the canonical Song's
  system reference links exist (idempotent; existing rows are untouched).

Project-song notes:
- Field: `notes`
- Scope: stored on `project_songs` and applies across setlists within the
  project
- Type: nullable string
- Validation: max `3000` characters
- Response: included on repertoire list and single-song payloads

### Bulk update
- `POST /repertoire/bulk-update`
- Body: `{ project_song_ids: [int], fields: { ... } }` — `fields` accepts
  every editable song field, all optional:
  - `title`, `artist` — string, max 255. NOT nullable: a title/artist can be
    overwritten but never cleared.
  - `version_label` — nullable string, max 50; `null` is normalized to `''`
    (primary version). Collision-skip rule below.
  - `original_musical_key`, `performed_musical_key` — nullable `MusicalKey`
    enum value (`C` … `Bm`, sharp/flat spellings distinct).
  - `tuning` — nullable string, max 50.
  - `capo` — nullable integer, 0–12.
  - `tempo_bpm` — nullable integer, 30–300.
  - `duration_in_seconds` — nullable integer, 0–86400.
  - `notes` — nullable string, max 3000.
  - `min_tip_cents` — nullable integer, 0–50000.
  - `energy`, `danceability` — nullable integer, 0–100.
  - `time_signature` — nullable string matching `^\d{1,2}/\d{1,2}$`.
  - `era` — nullable canonical era label (`_shared/constants/eras.json`);
    free-text input is normalized first, as on the single update.
  - `genre` — nullable canonical genre label (`_shared/constants/genres.json`).
  - `theme` — nullable canonical theme enum value.
  - `is_public`, `learned`, `mashup`, `instrumental`, `original` — boolean.
- Omitted keys are left untouched; for nullable fields an explicit `null`
  clears the value.
- Limits: 1–500 IDs per request; at least one whitelisted field is required.
- Access: IDs outside the current user/project are silently skipped.
- Every write lands on `project_songs` only — the shared `songs` table is
  never modified.
- Version-label collision skip: when `fields.version_label` is present, rows
  that would violate the unique
  `(project_id, user_id, song_id, version_label)` index are excluded from
  the update entirely — rows whose `song_id` appears more than once in the
  selection, and rows whose target label already exists on a row outside the
  selection. Excluded rows receive no field changes (not even other fields)
  and do not count in `updated_count`.
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
  - max size per PDF: `10MB` (server compresses with Ghostscript `/ebook` if input is > 1 MB and savings >= 100 KB; original is kept otherwise)
- Uploads PDF chart files and triggers AI identification.
- Does **not** create Song or ProjectSong records.
- Identification runs **synchronously per-chart** (one job per chart — there is
  no batch API path; `batch_pending` is never emitted). When the bulk fair-use
  burst bucket is full, uploads still succeed but affected entries are reported
  as `deferred` and re-queued by `charts:reap-stale-imports` once allowance frees.
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
`import_metadata` and `enrichment_state` in the response. When `import_status`
is `identified`, `import_metadata` contains the AI-identified title, artist, and
enrichment data (energy_level, era, genre, theme, original_musical_key,
duration_in_seconds, tempo_bpm). It also carries the cover signal `is_cover`
(boolean) and `original_artist` (string|null) so the client can badge a cover in
review; see "Cover detection" above.

`enrichment_state` is the **server-authoritative outcome of the grounded
metadata lookup**, and the single signal clients render the enrichment status
from — they do NOT infer it client-side:

- `enriched` — the lookup ran and returned at least one field.
- `empty` — the lookup ran (cache / provider / songs table) and genuinely
  found nothing. Clients show the "No details · open to retry" pill.
- `failed` — the lookup was attempted but timed out / hard-failed before a
  verdict. Transient and retryable: clients show a distinct "Couldn't finish ·
  retry" affordance and may auto-retry once on resume. A `failed` row must
  never be rendered as a settled `empty`.
- `null` — no grounded lookup ran (the content-hash reuse fast path lands a row
  at `identified` with identity-only metadata). Clients show a plain "Review".

The same `enrichment_state` is emitted per item by the bulk-enrich job snapshot
(`GET /repertoire/bulk-enrich-jobs/{jobId}`, derived from each item's source +
payload) so the bulk/retry path and the chart path agree.

`import_metadata` may also carry a `_provenance` map: per-field source labels
keyed by the snake_case field name (e.g. `{ "genre": "guessed",
"original_musical_key": "verified" }`). `verified` means the value came from an
admin-certified catalog Song; `guessed` means a grounded AI lookup that a human
has not certified. `_provenance` now drives ONLY the per-field guessed/verified
display (e.g. "2 guessed") — it no longer gates the "No details" pill, which is
driven by `enrichment_state`. Re-imported metadata stays `guessed` until a human
marks the Song verified (`confirmed ≠ verified`).

### Phase 2: Enrich — `POST /repertoire/bulk-enrich`

- Fetches enriched metadata for a batch of songs by title+artist.
- **Asynchronous (queue + poll).** Submit creates a `BulkEnrichJob` with
  one `BulkEnrichItem` per song, audience members out one queue job per item, and
  returns `202` with the job snapshot. Clients poll
  `GET /repertoire/bulk-enrich-jobs/{jobId}` until `status` is
  `completed`. Per-item rows pick up `SongMetadataLookupService` results
  (songs table → cache → configured AI provider) and tolerate transient
  Gemini quota backpressure inside the queue.
- Respects interactive AI quota limits at submit time. If the monthly
  AI cap is reached, returns `429` with `code: ai_limit_exceeded` and
  does not create the job.
- Supports `Idempotency-Key` — repeat submissions with the same key
  return the existing job snapshot with status `200`.

Submit request:

```json
{
  "songs": [
    { "title": "Bohemian Rhapsody", "artist": "Queen" }
  ]
}
```

Submit response (`202`, or `200` on an idempotent replay):

```json
{
  "data": {
    "job_id": 42,
    "project_id": 7,
    "status": "pending",
    "total_items": 1,
    "completed_items": 0,
    "failed_items": 0,
    "ai_calls_used": 0,
    "started_at": null,
    "finished_at": null,
    "created_at": "2026-05-12T00:30:00+00:00",
    "items": [
      {
        "position": 0,
        "title": "Bohemian Rhapsody",
        "artist": "Queen",
        "status": "pending",
        "source": null,
        "metadata": null,
        "is_duplicate": false,
        "duplicate_of": null,
        "error": null
      }
    ]
  }
}
```

### Phase 2 (poll): `GET /repertoire/bulk-enrich-jobs/{jobId}`

- Returns the current job snapshot.
- Job-level `status` transitions `pending → running → completed`.
- Item-level `status` transitions `pending → processing → completed` or
  `pending → processing → failed`. Items that hit transient Gemini
  quota backpressure flip back to `pending` while the queue waits out
  the backoff window, then resume.
- `is_duplicate` is `true` when a non-mashup `ProjectSong` with an
  empty `version_label` already exists in the project for this
  normalized title+artist; `duplicate_of` is then
  `"Title - Artist"` of the existing song. Clients should route these
  items to the duplicates section instead of the review queue.
  Mashups bypass repertoire dedup at confirm time and so are never
  flagged here.
- `ai_calls_used` counts items whose result came from an AI provider
  (i.e. not `songs_table`, `cache`, or `none`). Each AI call is
  recorded against `AccountUsageService::recordAiOperation` with
  category `bulk_enrich` and an idempotent operation key, so item
  retries do not double-bill the account.

Poll response (`200`):

```json
{
  "data": {
    "job_id": 42,
    "project_id": 7,
    "status": "completed",
    "total_items": 1,
    "completed_items": 1,
    "failed_items": 0,
    "ai_calls_used": 1,
    "started_at": "2026-05-12T00:30:05+00:00",
    "finished_at": "2026-05-12T00:30:18+00:00",
    "created_at": "2026-05-12T00:30:00+00:00",
    "items": [
      {
        "position": 0,
        "title": "Bohemian Rhapsody",
        "artist": "Queen",
        "status": "completed",
        "source": "ensemble",
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
        "duplicate_of": null,
        "error": null
      }
    ]
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
      "instrumental": false,
      "original": false,
      "is_cover": false,
      "original_artist": "Queen",
      "is_public": true,
      "learned": true,
      "theme": "story",
      "energy_level": "high",
      "era": "1970s",
      "genre": "Rock",
      "original_musical_key": "Bb",
      "duration_in_seconds": 354,
      "tempo_bpm": 72,
      "tuning": "EADGBE",
      "capo": 0
    }
  ],
  "existing_songs_only": false
}
```

#### Per-item field persistence

Each `songs[]` entry carries the user's review-stage edits. Confirm routes
each field to one of three targets — getting this wrong silently drops the
edit, since absent keys fall back to the column default:

- **Per-ProjectSong** (always written to the new `ProjectSong` row, read
  back by `ProjectSongResource`): `mashup`, `instrumental`, `original`,
  `is_public`, `learned`, `tuning`, `capo`. These are the review-stage
  flags/performance attributes and must be persisted at confirm even when
  the catalog `Song` already exists. The four flags default to their column
  default when omitted (`instrumental`/`original`/`mashup` → `false`,
  `is_public`/`learned` → `true`).
- **Catalog Song, new ORIGINAL-ARTIST songs only** (`applySongMetadata`,
  written only when the `Song` is freshly created AND the row is not a cover,
  so an import never mutates a shared catalog row other projects rely on and
  never populates the catalog for a cover): `energy_level`, `genre`, `theme`,
  `tempo_bpm`, `era`, `original_musical_key`, `duration_in_seconds`, `album`,
  `danceability`, `time_signature`. For an existing Song **or a cover** these
  are instead stored as overrides on the `ProjectSong` row.
- **Cover signal** (`is_cover`, `original_artist`): `is_cover` gates the
  catalog write — when `true` (or derived true from the chart's
  `import_metadata`), the catalog Song stays a bare identity shell and the
  metadata above lands on the `ProjectSong` instead; the new catalog row is
  marked internally so enrichment/backfill skip it. `original_artist` is
  informational. See "Original-artist-only catalog (covers)" above.

`chart_id` is consumed for chart linking (not stored on the song row).
`version_label` is reserved at confirm (`ProjectSong` rows are created with
an empty `version_label`); bulk import does not create alternate versions.

The Flutter client sends `mashup`/`instrumental`/`original` only when `true`
(an "off" choice is transmitted as key-omission), while `is_public`/`learned`
are sent unconditionally. Confirm only ever INSERTs (existing repertoire rows
are caught by dedup before creation), so an omitted flag correctly resolves to
its column default.

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
- Charts the performer claimed from the community library (referenced through a
  `ChartClaim` rather than an owned `Chart` row) are carried by re-claiming the
  same shared chart for the destination song — the PDF is not duplicated. These
  re-claims also count toward `copied_charts` and bump the chart's
  `claim_count`. Only honored when `include_charts=true`.
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

## Parse Text (no DB writes)

- `POST /repertoire/parse-text`
- Companion to `import-from-text` for callers that want only AI parsing,
  not import. The request body and AI parsing logic match
  `import-from-text`, but the server creates **no Song or ProjectSong
  rows**, performs no repertoire-limit gating, and does not deduplicate
  against the project repertoire. Used by the setlist set builder's
  paste flow, where parsed entries are matched against existing
  repertoire client-side rather than imported as new songs.
- Tracks AI usage via `AccountUsageService::recordAiOperation()` with
  category `text_parse`.
- Request validation:
  - `text`: required, string, max 20000 characters.
- Throttle: `throttle:chart-uploads`.

Response (`200`):

```json
{
  "songs": [
    { "title": "Hotel California", "artist": "Eagles", "set_label": null },
    { "title": "Night Moves", "artist": "Bob Seger", "set_label": null },
    { "title": "Friends in Low Places", "artist": "Garth Brooks", "set_label": "Encore" }
  ]
}
```

Entries with an empty/whitespace-only title are dropped server-side. The
artist field is `null` when the AI could not determine one — title-only
matching against the repertoire is still possible.

---

## To-Learn songs

As of v1.3, to-learn songs live in the regular repertoire as a
`ProjectSong` with `learned=false`. The legacy `/learning-songs` endpoints
and `project_learning_songs` table have been removed; see the "Learned
flag", "Reference links", and "Bulk update" sections above for the current
contract. Existing learning-song rows were migrated into `project_songs`
on deploy.
