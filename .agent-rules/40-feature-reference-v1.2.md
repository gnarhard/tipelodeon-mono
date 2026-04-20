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

## To-learn songs (merged into repertoire as of v1.4)

- To-learn is now a boolean flag on `project_songs` — see `_shared/contracts/repertoire.md`.
- Set `learned: false` via `PUT /repertoire/{projectSongId}` or in bulk via
  `POST /repertoire/bulk-update`.
- Filter with `GET /repertoire?learned=0`.
- Reference links (`youtube_video_url`, `ultimate_guitar_url`) live on the
  global `songs` table and are returned in the nested `song` object.

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

- Public audience profiles, leaderboards, and achievement notifications were retired on March 6, 2026.
- Audience identity remains internal-only through the `songtipper_audience_token` cookie for linking repeat requests in the same browser.
- See `_shared/audience-achievements.md` for the retired audience-gamification note.

## Request creation

- `requests.song_id` is always populated
- Tip-only requests map to the placeholder song `"Tip Jar Support"` by `"Audience"`
- Original requests map to the placeholder song `"Original Request"` by `"Audience"`
- Clients may send `tip_only` or `is_original`; the backend maps them to the placeholder songs

## Audio files

- Per-song CRUD: `GET|POST|PUT|DELETE /repertoire/{projectSong}/audio-files`
- Signed playback URL: `GET /repertoire/{projectSong}/audio-files/{audioFile}/signed-url`
- Replace: `POST /repertoire/{projectSong}/audio-files/{audioFile}/replace`
- **Batch fetch**: `POST /repertoire/audio-files/batch` — accepts `{ "project_song_ids": [1, 2, 3] }` (max 50), returns `{ "data": { "1": [...], "2": [...] } }`. Use this instead of N individual index calls when loading audio for multiple songs (e.g. setlist playlists).
- **Cache manifest**: `GET /repertoire/audio-files/manifest` — returns all audio files for the project with signed download URLs (`cache_key`, `url`, `project_song_id`, `audio_file_id`, `file_size_bytes`). Used by the settings "Refresh" button to pre-download all audio files.

## Bulk import

- Mobile sends at most `20` files per request
- Each PDF is limited to `2MB`
- Filename metadata supports `key`, `capo`, `tuning`, `energy`, `era`, `genre`, and `mood`
- Mood token example: `Songname - Artist -- mood=party.pdf`

## Locations

- Routes: `GET|POST /locations`, `PATCH|DELETE /locations/{locationId}`, `POST /locations/suggest`, `POST /locations/merge`
- Locations are project-scoped named locations where performers play
- Linked to performance sessions via `performance_sessions.location_id`
- Location suggestion uses GPS (500ft/152m radius match against existing locations) → Google Places Nearby Search fallback
- **Crowd-sourced nearby layer**: `/locations/suggest` also returns `crowd_sourced_nearby` — cross-project, places-linked locations within 15m (~50ft), capped at 5, excludes caller's own project. Rendered at the top of the location picker. See `_shared/contracts/locations.md`.
- Google Places responses are cached server-side (30-day TTL, rounded-coord key)
- Rate limit on `/locations/suggest`: 10/min per project
