# Setlists + Performance API Contracts (v1.3)

## Scope and auth

- Protected routes require `Authorization: Bearer <token>`.
- Write endpoints support `Idempotency-Key`.
- Route prefix: `/api/v1/me/projects/{project_id}`.

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
- `POST /performances/current/complete`
- `POST /performances/current/skip`
- `POST /performances/current/random`

Rules:
- Exactly one active session per project.
- Starting while active returns `409 Conflict`.
- Session mode: `manual` or `smart`.
- `complete` records sequential `performed_order_index`.
- `complete` for `source=setlist` also sends `setlist_song_id` so duplicate
  songs in one setlist are tracked as distinct performed entries.
- Smart mode can reorder pending items after skip/complete.
