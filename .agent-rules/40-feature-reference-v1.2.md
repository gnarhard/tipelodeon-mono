# Feature Reference (v1.2)

## Performance sessions

- Routes: `POST /performances/start`, `POST /performances/stop`, `GET /performances/current`
- Session modes: `manual` or `smart`
- Completion tracking: `POST /performances/current/complete` with sequential `performed_order_index`
- Skip and random: `POST /performances/current/skip`, `POST /performances/current/random`
- In smart mode, pending items may be reordered after skip or completion
- Exactly one active session per project; starting another returns `409 Conflict`

## Smart setlist generation

- Route: `POST /setlists/generate-smart`
- Created setlists store generation metadata such as `seed`, version, and constraints
- Regeneration with different parameters is supported

## To-learn list

- Routes: `GET|POST|PUT|DELETE /learning-songs`
- Fields: `youtube_video_url`, `ultimate_guitar_url`, `notes`
- This list is separate from repertoire

## Copy repertoire

- Route: `POST /repertoire/copy-from`
- Request body: `{ "source_project_id": 1, "include_charts": true }`
- Copies repertoire rows, overrides, and optional chart linkage

## Chart viewport preferences

- New in v1.2: per `(user, chart, page)` storage in `chart_page_user_prefs`
- Routes: `GET|PUT /charts/{chartId}/pages/{page}/viewport`
- Deprecated: `projects.chart_viewport_prefs`
- Clients should migrate to the per-page endpoint

## Setlist notes

- Notes are supported on `setlists`, `setlist_sets`, and `setlist_songs`
- Create and update payloads accept `notes` as nullable text
- `setlist_sets` also expose nullable `notes_order_index` to persist where the
  set note should appear inside the set's mixed song list

## Text import for setlists

- Route: `POST /setlists/{setlistId}/sets/{setId}/songs/import-text`
- Accepts newline-separated `Title` or `Title - Artist` lines
- Auto-matches songs to repertoire

## Audience features

### Profile and achievements

- Route: `GET /public/projects/{slug}/audience/me`
- Uses the `songtipper_audience_token` cookie-backed identity
- Display names are deterministic `Adjective + Animal`
- Returns profile, totals, and achievements

### Who's here leaderboard

- Route: `GET /public/projects/{slug}/audience/leaderboard`
- Uses the active performance session
- Ranks by `SUM(tip_amount_cents)` during the session
- Stable tie-breaker: `joined_at ASC`

## Request creation

- `requests.song_id` is always populated
- Tip-only requests map to the placeholder song `"Tip Jar Support"` by `"Audience"`
- Original requests map to the placeholder song `"Original Request"` by `"Audience"`
- Clients may send `tip_only` or `is_original`; the backend maps them to the placeholder songs

## Bulk import

- Mobile sends at most `20` files per request
- Each PDF is limited to `2MB`
- Filename metadata supports `key`, `capo`, `tuning`, `energy`, `era`, `genre`, and `mood`
- Mood token example: `Songname - Artist -- mood=party.pdf`
