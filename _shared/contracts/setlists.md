# Setlists + Performance API Contracts (v1.3)

## Scope and auth

- Protected routes require `Authorization: Bearer <token>`.
- Write endpoints support `Idempotency-Key`.
- Route prefix: `/api/v1/me/projects/{project_id}`.

---

## Member setlist isolation

- Each project member has their own independent setlists within the project.
- `setlists.user_id`: NOT NULL FK to `users`, scopes setlists per member.
- On member join: all owner's active setlists are copied to the member via
  `SyncRepertoireToMember` job. Setlist notes (all levels) are NOT copied —
  they are always personal to each member.
- New setlists created by the owner after a member joins are NOT automatically
  added to member accounts.
- Owner can explicitly share a setlist with all members via
  `POST /setlists/{setlistId}/share-with-members` (dispatches `CopySetlistToMember`
  job per member).
- Set list song references are resolved to the member's copies of the repertoire.

Share setlist with members:
- `POST /setlists/{setlistId}/share-with-members`
- Owner-only. Returns `403` for non-owners.
- Dispatches async copy job for each project member.
- Response: `{ "message": "...", "member_count": 2 }`

---

## Setlists are container-only

Setlists now only represent containers for sets.
Generation method selection happens when creating each set.

Setlist routes:
- `GET /setlists` — query param `?archived=true` returns archived setlists;
  default (no param or `archived=false`) returns active setlists only.
- `POST /setlists`
- `GET /setlists/{setlistId}`
- `PUT /setlists/{setlistId}`
- `POST /setlists/{setlistId}/archive` — sets `archived_at` to now.
  Returns `422` if already archived.
- `POST /setlists/{setlistId}/restore` — clears `archived_at`.
  Returns `422` if not archived.
- `DELETE /setlists/{setlistId}` — permanent delete (prefer archive).

Setlist resource fields:
- `id`, `project_id`, `name`, `notes`, `folder`, `created_at`,
  `archived_at`, `generation_meta`, `sets`.
- `folder`: nullable string (max 255). Setlists with the same `folder`
  value are grouped together in the mobile UI.
- `archived_at`: nullable ISO-8601 timestamp. Non-null means the setlist
  is archived and hidden from the default list view.

Removed (breaking change):
- `POST /setlists/generate-smart`
- `POST /setlists/generate-strategic`

---

## Shared setlist links

Sharers:
- `POST /setlists/{setlistId}/share-link`
- Allowed for project owners and project members.
- Returns one stable share token per source setlist.
- Response includes:
  - `share_url` for sending to another Song Tipper user
  - `deep_link_url` for the app redirect target

Recipients:
- `POST /api/v1/me/shared-setlists/{shareToken}/accept`
- Recipient must already have access to the owning project.
- Accepting the link copies the source setlist into the same owning project.
- If the copied setlist references songs that are not yet in that project's
  repertoire, the backend creates the missing repertoire rows automatically
  before saving the copied setlist.
- The copied setlist is persisted server-side and returned in the response.
- Acceptance is idempotent per `(share link, user)`:
  - first accept creates one copied setlist
  - later accepts reopen the same copied setlist instead of creating duplicates
- The acceptance response includes `shared_assets` — a map keyed by
  `project_song_id` containing the sharer's charts and audio files:
  ```json
  {
    "data": {
      "project": { ... },
      "setlist": { ... },
      "was_already_accepted": false,
      "shared_assets": {
        "42": {
          "sharer_charts": [{ "id": 1, "page_count": 2, "has_renders": true, "page_urls": ["..."] }],
          "audio_files": [{ "id": 5, "label": "Rehearsal", "original_filename": "...", "signed_url": "..." }]
        }
      }
    }
  }
  ```
- Sharer charts include signed page URLs (light theme) for preview.
- Audio files include signed playback URLs (60-min TTL).

Mobile behavior:
- The public `share_url` redirects into the mobile app.
- When opened, the app accepts the share token, switches to the owning
  project, refreshes that project's repertoire and setlist list, and opens the
  copied setlist detail screen.
- The copied setlist name is suffixed with ` (Copy)`.

---

## Set creation methods

### Manual set

- `POST /setlists/{setlistId}/sets`
- Request supports optional `name` and optional `order_index`.
- If `name` is omitted or blank, backend auto-labels as `Set N`.

### AI set generation

- `POST /setlists/{setlistId}/sets/generate-ai`

Request:

```json
{
  "sets": [
    { "name": "Dinner", "prompt": "15 relaxing dinner songs" },
    { "name": "Party", "prompt": "15 high energy rock songs" },
    { "name": "Wind Down", "prompt": "15 mellow country songs to close the night" }
  ]
}
```

Validation:
- `sets`: required array, min 1, max 10
- `sets.*.name`: optional, nullable string, max 255 (auto-labeled `Set N` if omitted)
- `sets.*.prompt`: required string, min 3, max 500 (should include desired song count)

Semantics:
- The backend loads the full project repertoire with effective metadata
  (energy, era, genre, theme) and sends it to the configured AI provider
  along with the per-set prompts.
- The AI selects songs from the repertoire only (no invented songs).
- Songs are not duplicated across sets unless the repertoire is too small.
- If the repertoire is empty, endpoint returns `422`.
- If the AI provider fails, endpoint returns `422`.
- Creates multiple sets in one call, each populated with the AI-selected songs.

Removed (replaced by AI generation):
- `POST /setlists/{setlistId}/sets/generate-smart`
- `POST /setlists/{setlistId}/sets/generate-strategic`

### Extract songs from setlist image

- `POST /setlists/extract-songs-from-image`

Request: multipart/form-data
- `image`: required image file (jpeg, png, webp, heic), max 10MB

Response:

```json
{
  "data": {
    "songs": [
      {"title": "Song Title 1", "set_label": "Set 1"},
      {"title": "Song Title 2", "set_label": "Set 1"},
      {"title": "Song Title 3", "set_label": null}
    ]
  }
}
```

Semantics:
- Sends image to configured AI vision provider for OCR/title extraction.
- Returns extracted songs in the order they appear in the image.
- `set_label` is the AI-detected set grouping (e.g. "Set 1", "Encore"), or null
  if no set groupings are visible.
- Returns `422` if extraction fails or the image cannot be processed.
- An empty `songs` array is valid (image was readable but contained no titles).
- Client feeds extracted titles into the same CSV matching flow
  (see "Manual CSV flow" below).

---

## Generation response format

The AI generation endpoint returns:

```json
{
  "data": [
    { "...set resource..." },
    { "...set resource..." }
  ],
  "meta": {
    "generation": {
      "generation_version": "ai-set-v1",
      "provider": "anthropic",
      "total_sets": 3,
      "total_songs_placed": 40
    }
  }
}
```

Notes:
- Generation metadata is response-only.
- No generation metadata is persisted to `setlists` or `setlist_sets`.

---

## Manual CSV flow (mobile behavior)

Manual CSV matching is client-side:
- User pastes title-only CSV.
- CSV can be used either while creating a manual set or while adding songs to an existing set.
- Client matches titles against repertoire using normalized/fuzzy scoring.
- Auto-match: exactly one displayed score of `1.00`, or top score `>= 0.90`
  and margin vs second `>= 0.08`.
- Needs review: multiple displayed `1.00` scores for the title, or top score
  `>= 0.70` but ambiguous.
- Unresolved: top score `< 0.70`.
- Client shows top 3 candidates for review rows.
- If every row is auto-matched or skipped without ambiguity, client applies the
  CSV immediately without opening the review sheet.
- Each row must be matched or skipped before apply.
- User can manually link any row to any repertoire song.
- Repeated CSV titles are kept.
- Rows that resolve to a repertoire song already selected earlier in the CSV, or
  already present in the target set, are warned as duplicates but still added.
- Applying adds every resolved row in original CSV order, including duplicates,
  auto-matches, reviewed matches, and manual links.

Manual apply sequence:
1. If creating a new manual set, create the set via `POST /setlists/{setlistId}/sets`.
2. Add the resolved song IDs in CSV order via `POST /setlists/{setlistId}/sets/{setId}/songs/bulk`
   for newly created sets, or `POST /setlists/{setlistId}/sets/{setId}/songs` for existing-set append flows.

---

## Existing set/song routes

Sets:
- `POST /setlists/{setlistId}/sets`
- `PUT /setlists/{setlistId}/sets/{setId}`
- `DELETE /setlists/{setlistId}/sets/{setId}`

Set payload rules:
- `POST /setlists/{setlistId}/sets` and `PUT /setlists/{setlistId}/sets/{setId}`
  only manage set metadata (`name`, `order_index`).
- Set-level notes are no longer stored on `setlist_sets`.
- Multiple set notes are modeled as normal ordered entries inside
  `setlist_songs`.

Set songs:
- `POST /setlists/{setlistId}/sets/{setId}/songs`
- `POST /setlists/{setlistId}/sets/{setId}/songs/bulk`
- `PUT /setlists/{setlistId}/sets/{setId}/songs/{songId}`
- `PUT /setlists/{setlistId}/sets/{setId}/songs/reorder`
- `DELETE /setlists/{setlistId}/sets/{setId}/songs/{songId}`
- `POST /setlists/{setlistId}/sets/{setId}/songs/import-text`

Set song payload notes:
- `POST /setlists/{setlistId}/sets/{setId}/songs` accepts either:
  - `project_song_id` for a repertoire song entry, or
  - `notes` for a set note entry.
- Set note entries serialize with `project_song_id = null` and `song = null`.
- `PUT /setlists/{setlistId}/sets/{setId}/songs/{songId}` accepts optional
  `notes` and optional nullable `color_hex`.
- `color_hex` must match `#RRGGBB` when present.
- `color_hex = null` means clients should render the default dark grey song dot.
- `PUT /setlists/{setlistId}/sets/{setId}/songs/reorder` reorders all mixed
  set entries, including note entries.
Set song rules:
- Duplicate `project_song_id` entries are allowed within the same set.
- `POST /setlists/{setlistId}/sets/{setId}/songs/import-text` accepts
  `text` and optional `create_missing_songs`.
- `songs/import-text` returns `meta.added_count`, `meta.duplicate_count`,
  `meta.duplicate_lines`, `meta.unresolved_count`, and `meta.unresolved_lines`.

Delete behavior:
- Remaining sets are always reindexed so `order_index` stays contiguous (`0..N-1`).
- Default-numbered titles (`Set <number>`) are renumbered sequentially.

---

## Performance sessions

Routes:
- `POST /performances/start`
- `POST /performances/stop`
- `GET /performances/current`
- `PATCH /performances/{sessionId}`
- `POST /performances/current/complete`
- `POST /performances/current/skip`
- `POST /performances/current/random`

### PerformanceSessionResponse

```json
{
  "id": 1,
  "project_id": 5,
  "setlist_id": 3,
  "venue_id": 1,
  "venue": { "id": 1, "name": "Mike's Bar" },
  "mode": "manual",
  "is_active": true,
  "is_implicit": false,
  "timezone": "America/Denver",
  "latitude": 39.7392358,
  "longitude": -104.990251,
  "gig_type": "public",
  "ended_reason": null,
  "seed": null,
  "generation_version": null,
  "started_at": "2026-04-01T20:00:00+00:00",
  "ended_at": null,
  "created_at": "...",
  "updated_at": "..."
}
```

**Fields:**
- `venue_id`: nullable integer. FK to `venues`.
- `venue`: nullable embedded object with `id` and `name`. Null when `venue_id` is null.
- `is_implicit`: boolean. `true` for sessions auto-created by incoming public requests; `false` for performer-initiated sessions.
- `timezone`: IANA timezone string. Required on explicit sessions.
- `latitude`, `longitude`: nullable decimals. GPS coordinates of the performance location.
- `gig_type`: string, one of `public`, `private_event`, `open_mic`, `rehearsal`. Defaults to `public`.
- `ended_reason`: nullable string, one of `manual`, `inactivity`, `max_duration`, `superseded`. Set when the session ends.

### Start Performance -- `POST /performances/start`

Start a new explicit performance session.

**Request body:**

```json
{
  "setlist_id": 3,
  "mode": "manual",
  "venue_id": 1,
  "latitude": 39.7392358,
  "longitude": -104.990251,
  "timezone": "America/Denver",
  "gig_type": "public"
}
```

**Validation Rules:**
- `setlist_id`: nullable integer. Not required. Implicit sessions have no setlist.
- `mode`: required, one of `manual`, `smart`.
- `venue_id`: optional, integer, must belong to the project.
- `latitude`: optional, decimal, -90 to 90.
- `longitude`: optional, decimal, -180 to 180.
- `timezone`: required, valid IANA timezone identifier.
- `gig_type`: optional, string, one of `public`, `private_event`, `open_mic`, `rehearsal`. Defaults to `public`.

**Implicit session promotion:** If an implicit session (`is_implicit = true`) already exists for the project, the `start` call promotes it instead of returning `409`. The existing session's `venue_id`, `timezone`, `latitude`, `longitude`, `gig_type`, and `setlist_id` are updated with the provided values, and `is_implicit` is flipped to `false`. No new session is created.

**Explicit session conflict:** If an explicit session (`is_implicit = false`) already exists, returns `409 Conflict` as before.

**Success response:** `200` with `{ "data": { PerformanceSessionResponse } }`.

### Update Past Session -- `PATCH /performances/{sessionId}`

Update fields on a past (inactive) performance session. Use case: post-hoc venue labeling from sessions history.

**Request body:**

```json
{
  "venue_id": 1,
  "gig_type": "private_event",
  "timezone": "America/Denver",
  "latitude": 39.7392358,
  "longitude": -104.990251
}
```

**Validation Rules:**
- Only works on inactive sessions (`is_active = false`). Returns `422` on active sessions.
- `venue_id`: optional, nullable integer, must belong to the project.
- `gig_type`: optional, string, one of `public`, `private_event`, `open_mic`, `rehearsal`.
- `timezone`: optional, valid IANA timezone identifier.
- `latitude`: optional, decimal, -90 to 90.
- `longitude`: optional, decimal, -180 to 180.

**Success response:** `200` with `{ "data": { PerformanceSessionResponse } }`.

**Error responses:**

**Session is still active (`422`):**
```json
{
  "message": "Active sessions cannot be edited. Stop the session first."
}
```

**Session not found or cross-project (`404`):**
```json
{
  "message": "Resource not found."
}
```

### Auto-end rules

Active sessions are automatically ended by a server-side scheduled task running every 5 minutes. A session is ended when any of these conditions is met:

- **Inactivity**: no new request, completed performance item, or cash tip linked to the session in the last 4 hours.
- **Hard cap**: `started_at` is more than 6 hours ago.
- **Superseded**: a new explicit session is started for the same project.

The `ended_reason` field records which rule triggered the end: `manual`, `inactivity`, `max_duration`, or `superseded`.

### General rules

- Exactly one active session per project.
- Session mode: `manual` or `smart`.
- `complete` records sequential `performed_order_index`.
- `complete` for `source=setlist` also sends `setlist_song_id` so duplicate
  songs in one setlist are tracked as distinct performed entries.
- Smart mode can reorder pending items after skip/complete.
