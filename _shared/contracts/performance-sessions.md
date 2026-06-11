# Performance Sessions Contract

A `performance_sessions` row groups every metric (request, song performance, tip-bucket total) produced during a single stretch of the performer playing. Every metric row in `requests`, `song_performances`, and `tip_bucket_totals` is tied to a session via NOT NULL `performance_session_id` — "an orphan metric" is not representable.

## Fields

| Field | Type | Notes |
|---|---|---|
| `id` | integer | |
| `project_id` | integer | |
| `setlist_id` | integer \| null | Populated when the performer starts with a setlist. Null for free play / audience-auto sessions. |
| `location_id` | integer \| null | |
| `mode` | enum | `"manual"` or `"free_play"`. |
| `start_source` | enum | `"performer"`, `"audience_auto"`, or `"backfill"`. See below. |
| `is_synthetic` | boolean | `true` for backfill sessions; these are excluded from stats by default. |
| `is_active` | boolean | A project has at most one active session at a time. |
| `started_at` / `ended_at` | timestamp | UTC. `ended_at` is null while active. |
| `ended_reason` | enum | `"manual"`, `"inactivity"`, `"max_duration"`, `"superseded"`, `"backfill"`. |
| `deleted_at` | timestamp \| null | Soft-delete timestamp. Sessions are never hard-deleted. |
| `timezone` / `latitude` / `longitude` | — | Optional. |
| `ai_synopsis`, `ai_synopsis_generated_at`, `performer_notes` | — | |

## `start_source` semantics

- **`performer`** — performer pressed Start (setlist or free play). This is the default on `POST /api/v1/me/projects/{project}/performances/start`.
- **`audience_auto`** — auto-started by the server on the first audience write (Stripe webhook create, or public request submit) when no session was active and no session ended within the last hour (see "Post-show grace window"). Always `mode = "free_play"`, no setlist. Idle audience-auto sessions end automatically after 30 minutes of no child writes (`ended_reason = "inactivity"`).
- **`backfill`** — one synthetic session per project, created during the initial one-time data migration to absorb rows that existed before session linkage was mandatory. `is_synthetic = true`. Excluded from stats by default.

## Post-show grace window

When an audience write (Stripe webhook tip create, or public request submit) arrives with **no active session** but the project's most recent non-synthetic, non-deleted session has `ended_at` within the last **60 minutes**, the server **resumes that session** instead of starting a new `audience_auto` one — the tip/request is attributed to the just-ended performance. Resume here has the standard semantics (see "Resume restores unresolved queue items" below): `is_active` flips back to `true`, `ended_at` / `ended_reason` clear, `started_at` and `start_source` are preserved, and `unresolved` requests on the session return to `active`. The resumed session is subject to the normal auto-end rules, so it closes again after inactivity. If the last session ended more than 60 minutes ago, a fresh `audience_auto` session starts as before.

**Pending free-request reward claims survive through the grace window.** Session end (manual stop, auto-end, or idle-audience end) does NOT expire them — a resume within the window is the same gig, so an earned-but-unredeemed free request is still claimable after the queue refills. Claims lapse at the first of: (a) a fresh session being created for the project (performer start, free-play start, or post-grace `audience_auto` start — adoption and resume never expire), or (b) the `rewards:expire-stale-free-claims` scheduled sweep (every 5 minutes) observing that the project has no active session and its latest real session ended more than 60 minutes ago. The boundary minute (`ended_at` exactly 60 minutes ago) still belongs to the grace window — the same inclusive comparison the resume check uses. This keeps free-request rewards strictly gig+grace-scoped loyalty milestones, never a carried balance (`.agent-rules/15-patent-constraints.md`, invariant 3). Performer-delivered reward claims are untouched by session boundaries.

## Adoption

If the performer starts a real session (`POST …/performances/start`) while an `audience_auto` session is already active for the project, the existing session is **adopted** — `start_source` is updated to `"performer"`, and the performer's `setlist_id` / `mode` / `location` / `timezone` / `started_at` are written through to the same row. No second session is created, and no metric rows are re-pointed. This is the only way a new performer-initiated start returns the same row as an existing active session instead of failing with 409.

## Soft delete

`DELETE /api/v1/me/projects/{project}/performances/{performanceSession}` soft-deletes the session by stamping `deleted_at`. Child `requests` / `song_performances` / `tip_bucket_totals` rows retain their FK (the FK is `ON DELETE RESTRICT` now, so hard deletes would fail). Stats queries exclude any row whose session has `deleted_at IS NOT NULL`.

## Stats exclusion

`GET /api/v1/me/projects/{project}/stats` accepts an optional `include_synthetic=true` query parameter:

- Default (`false`): responses exclude both soft-deleted sessions and synthetic (`is_synthetic = true`) sessions.
- `include_synthetic=true`: include synthetic sessions. Intended for "All time" views that surface historical / pre-session data.

Soft-deleted sessions are always excluded, regardless of `include_synthetic`.

## 409 `no_active_session`

Performer-initiated write endpoints return `409 Conflict` with body `{"error": "no_active_session", "message": "…"}` when the project has no active performance session. Affected endpoints:

- `POST /api/v1/me/projects/{project}/queue`
- `POST /api/v1/me/repertoire/{projectSong}/performances`

Clients must prompt the performer to start a session and retry. Audience endpoints never return this error — they auto-start sessions instead.

## Resume restores unresolved queue items

When a session ends (manual stop, `applyAutoEndRules`, or the `performances:end-idle-audience` command), every request on that session whose `status` is `"active"` transitions to `"unresolved"` in the same transaction. The `GET /queue` filter ignores `unresolved`, so the queue strip clears immediately.

`POST /api/v1/me/projects/{project}/performances/{session}/resume` reactivates the session row and, in the same transaction, transitions every `unresolved` request on that session back to `"active"`. The strip refills with the same items in their original order (`session_sequence`, `score_cents`, and `tip_amount_cents` are preserved).

Requests with `status = "cancelled"` — explicit performer or audience removals during the show — are not touched by either transition. They do not become `unresolved` on session end and do not reappear on resume. This keeps `queue.md`'s session-linking guarantees and the lifecycle in `ARCHITECTURE.md` in sync.
