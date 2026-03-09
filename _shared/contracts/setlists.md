# Setlists + Performance API Contracts (v1.2)

## Scope and auth

- Protected routes require `Authorization: Bearer <token>`.
- Write endpoints support `Idempotency-Key`.
- Route prefix: `/api/v1/me/projects/{project_id}`.

---

## Setlists are container-only

Setlists now only represent containers for sets.
Generation method selection happens when creating each set.

Setlist routes:
- `GET /setlists`
- `POST /setlists`
- `GET /setlists/{setlistId}`
- `PUT /setlists/{setlistId}`
- `DELETE /setlists/{setlistId}`

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

### Smart set

- `POST /setlists/{setlistId}/sets/generate-smart`

Request:

```json
{
  "name": "Set 1",
  "allow_existing_set_duplicates": false
}
```

Semantics:
- Candidate pool is full project repertoire.
- By default (`allow_existing_set_duplicates=false`), songs already present in other sets of the same setlist are excluded.
- If no candidates remain, endpoint returns `422`.
- Creates one new set containing all ranked candidates.
- After candidate selection, song order is adjusted using recorded setlist
  performance history when available so openers, transitions, slot patterns,
  and closers mimic real performances.

Candidate selection order:
1. Revenue: `SUM(requests.tip_amount_cents)` per song (all statuses)
2. Popularity: `COUNT(requests.id)` per song
3. Play count: `project_songs.performance_count`
4. Tie-breakers: `title ASC`, `artist ASC`, `project_song_id ASC`

Ordering fallback:
- If there is no recorded setlist performance history with
  `performed_order_index`, backend keeps the candidate-selection order above.

### Strategic set

- `POST /setlists/{setlistId}/sets/generate-strategic`

Request:

```json
{
  "name": "Set 2",
  "seed": 12345,
  "song_count": 15,
  "randomize": false,
  "allow_existing_set_duplicates": false,
  "criteria": {
    "energy_levels": ["low", "medium"],
    "eras": ["90s"],
    "genres": ["Rock"],
    "themes": ["love"]
  }
}
```

Validation/defaults:
- `song_count`: optional, `1..50`, default `15`
- `randomize`: optional, default `false`
- `allow_existing_set_duplicates`: optional, default `false`
- Enum validation for energy/era/genre/theme uses canonical values.

Semantics:
- Effective metadata:
  - Energy/genre/theme: `project_songs` override first, fallback to `songs`
  - Era: `songs.era`
- Matching is AND across fields, OR within each field.
- Genre/theme matching is case-insensitive.
- If duplicates are not allowed, songs already present in other sets are excluded.
- If filtered pool is empty, endpoint returns `422`.
- If filtered pool has fewer than `song_count`, backend creates a partial set.
- Ordering:
  - `randomize=false`: ranked by same smart ranking rules
  - `randomize=true`: deterministic seed-based shuffle, then truncate

---

## Generation response format

Both generation endpoints return:

```json
{
  "data": { "...set resource..." },
  "meta": {
    "generation": {
      "...generation summary..."
    }
  }
}
```

Notes:
- Generation metadata is response-only for set-level generation.
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
