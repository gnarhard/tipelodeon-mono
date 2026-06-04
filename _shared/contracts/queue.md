# Queue & Requests API Contracts

## Auth and scope

- All endpoints below require `Authorization: Bearer <token>`.
- All endpoints are performer-scoped and project-scoped via `{project_id}`.
- Route prefix: `/api/v1/me/projects/{project_id}`
- Queue read and history are accessible to every project member. Queue
  mutations (`POST /queue`, `PATCH /queue/{id}`, `DELETE /queue/{id}`,
  `PUT /queue/reorder`, `POST /me/requests/{id}/played`,
  `POST /me/reward-claims/{id}/delivered`) are owner-only; members receive
  `403`. The audience block endpoints
  (`POST`/`DELETE /audience/{audienceProfile}/block`,
  `GET /audience/blocked`) and the content-report endpoint
  (`POST /requests/{request}/report`) are also owner-only; members
  receive `403`.
  The persistent queue strip is read-only for members in clients;
  the
  `entitlements.can_access_queue` / `entitlements.can_access_history` flags
  always resolve to `true` for members and `false` for non-members.

---

## Get Active Queue

- **Method**: `GET`
- **Path**: `/queue`

Get the active request queue for a project. **Supports ETag caching** for
efficient polling.

Queue is ordered by `tip_amount_cents DESC`, then `created_at ASC`.

### Query parameters

- `timezone` (optional): IANA timezone used to compute the queue
  `meta.daily_record_event`. When omitted or invalid, defaults to the
  project's `reporting_timezone`. See
  [`timezone-and-time.md`](timezone-and-time.md) for the full timezone
  contract; UTC is never used as a performer-facing calendar-day boundary.

### Headers

- `If-None-Match`: Previous ETag value (optional)

### Success response (`200`)

```json
{
  "data": [
    {
      "id": 42,
      "audience_profile_id": null,
      "performance_session_id": 7,
      "session_sequence": 12,
      "song": {
        "id": 1,
        "title": "Fly Me to the Moon",
        "artist": "Frank Sinatra"
      },
      "tip_amount_cents": 1500,
      "tip_amount_dollars": "15",
      "status": "active",
      "requester_name": "Jane Smith",
      "note": "Happy birthday!",
      "is_manual": false,
      "request_location": "Denver, Colorado, US",
      "played_at": null,
      "created_at": "2026-02-03T11:55:00+00:00"
    }
  ],
  "meta": {
    "daily_record_event": {
      "event_id": "queue-record:42:2026-03-12:12300",
      "gross_tip_amount_cents": 12300,
      "local_date": "2026-03-12",
      "timezone": "America/Denver"
    },
    "pending_rewards": [
      {
        "id": 17,
        "reward_threshold_id": 4,
        "reward_label": "Free CD",
        "reward_icon": "album",
        "reward_icon_emoji": "💿",
        "reward_description": "Come up to the stage after the song.",
        "audience_display_name": "Jane Smith",
        "earned_at": "2026-04-08T19:32:14+00:00"
      }
    ]
  }
}
```

**Response includes `ETag` header.**

- `session_sequence` is the 1-based ordinal of the song-request within its
  `performance_session_id`. Manual queue adds, audience paid requests,
  audience free requests, original requests, and custom requests all
  increment this counter. Tip-only writes (`song.title="Tip Jar Support"` /
  `song.artist="Audience"`) do **not** consume a sequence number — they ship
  with `session_sequence = null` so the per-session request numbering counts
  only real song requests. Clients display the value as the user-facing
  request number (typically zero-padded, e.g. `#0042`). It is also `null`
  for legacy rows whose `performance_session_id` is `null`.
- `meta.daily_record_event` is `null` unless the current local day sets a new
  project lifetime one-day gross-tip record.
- `requester_name` is the tipper's real name sourced from the linked
  `AudienceProfile`. Only populated from Stripe `billing_details.name` on paid
  requests. It is `null` for free requests and manual queue items.
- `request_location` is the human-readable location of the tipper resolved
  asynchronously from their IP address (e.g. `"Denver, Colorado, US"`). It is
  `null` when the lookup has not completed, failed, or the IP was private/local.
- `meta.pending_rewards` lists physical reward claims (any reward type other
  than `free_request`) that an audience member has earned but the performer has
  not yet handed over. Items are ordered oldest first by `earned_at`. Free
  request rewards never appear here — they are claimed at redemption time. The
  list is `[]` when there is nothing pending.
  - `audience_display_name` is `null` when the audience profile has no name.
  - `reward_icon` is the curated icon code (e.g. `album`, `star`, `gift`) and
    `reward_icon_emoji` is the corresponding emoji glyph for clients that
    can't render the icon set.
  - `reward_description` is the optional handover instructions configured on
    the threshold (e.g. "Come up to the stage after the song.").
- The queue `ETag` must change when the active queue payload, the
  `daily_record_event`, or the `pending_rewards` set changes.

### Not Modified response (`304`)

No changes since last request (when `If-None-Match` matches). No body is
returned. Matching includes both the queue payload and the record-event state.

---

## Session Linking for Requests

Every `requests` row is linked to a `performance_session` — the column is NOT NULL. Two rules:

- **Performer-initiated writes** (`POST /queue`, `POST /me/requests/{id}/played`, repertoire mark-played) require an active performance session for the project. If none is active, the endpoint returns `409 Conflict` with body `{"error": "no_active_session"}`. The client is expected to prompt the performer to start a session and retry.
- **Audience-initiated writes** (public request / Stripe webhook) auto-start a `start_source = "audience_auto"` free-play session on the project if none is active. The audience member is never rejected because the performer hasn't started a session — money is never turned away.

If the performer later starts a real session (setlist or free-play) while an `audience_auto` session is active, the existing session is **adopted**: `start_source` flips to `"performer"`, `setlist_id`/`mode`/`location`/`timezone` are written through to the existing row. There is no duplicate session and no orphan writes.

Audience-auto sessions that see no child writes for 30 minutes are ended by the `performances:end-idle-audience` scheduled command (`ended_reason = "inactivity"`).

When a performance session ends, every still-`"active"` request tied to that session transitions to `"unresolved"` in the same transaction. This applies to all three end paths:

- `POST /performances/stop` (manual stop)
- `PerformanceSessionService::applyAutoEndRules()` (6h max-duration / 4h-inactivity timer)
- `performances:end-idle-audience` artisan command (audience-auto sessions with no child writes for N minutes)

Unresolved requests do not appear in `GET /queue` (which filters on `status = "active"`). Tip-only writes (`song.title="Tip Jar Support"`) are paused the same way — once the performer finishes, the queue strip starts the next session empty. Payment is not refunded; the transition is a queue-state change only, consistent with the credit-at-request-time rule in `.agent-rules/15-patent-constraints.md`.

If the same session is later reopened via `POST /performances/{id}/resume`, every request on that session with `status = "unresolved"` transitions back to `"active"` in the same transaction. The strip refills with those items in their original positions (`session_sequence`, `score_cents`, and `tip_amount_cents` are preserved).

Explicitly-`"cancelled"` requests (e.g., a performer or audience member removed a request mid-show) are **not** affected by the session-end transition and never reappear on resume — only `unresolved` requests are restored.

---

## Add Item to Queue (Manual)

- **Method**: `POST`
- **Path**: `/queue`

Manually add an item to the active queue as the project owner. Members receive `403`.

### Request body (custom song)

```json
{
  "type": "custom",
  "custom_title": "Crowd Favorite Mashup",
  "tip_amount_cents": 750,
  "custom_artist": "Custom Request",
  "note": "Mash two choruses if possible"
}
```

### Request body (song from repertoire)

```json
{
  "type": "repertoire_song",
  "song_id": 123,
  "tip_amount_cents": 500,
  "note": "Acoustic version"
}
```

`song_id` must belong to the selected `{projectId}` repertoire.

### Request body (original)

```json
{
  "type": "original",
  "tip_amount_cents": 0
}
```

**Validation Rules:**
- `type`: required, one of: `custom`, `repertoire_song`, `original`
- Original requests only allowed when project setting `is_accepting_original_requests` is `true`
- `tip_amount_cents`: optional for all types, defaults to `0` when omitted
- `tip_amount_cents` values with cents are rounded up to the next whole-dollar
  amount before persistence and response serialization

**Storage note (custom requests):**
- `type=custom` requests do **not** create a `songs` row. The performer-supplied
  `custom_title` and optional `custom_artist` are stored directly on the
  `requests` row, and `song_id` points at a single shared sentinel song
  (`title="Custom Request"`, `artist="Custom Request"`). The response shape is
  unchanged: `song.title` and `song.artist` echo the request's `custom_title`
  / `custom_artist` (falling back to the sentinel's labels when no
  `custom_artist` was supplied).

### Success response (`201`)

```json
{
  "message": "Queue item added.",
  "request": {
    "id": 42,
    "performance_session_id": 7,
    "session_sequence": 13,
    "song": {
      "id": 1,
      "title": "Crowd Favorite Mashup",
      "artist": "Custom Request"
    },
    "tip_amount_cents": 0,
    "tip_amount_dollars": "0",
    "status": "active",
    "requester_name": null,
    "note": "Mash two choruses if possible",
    "is_manual": true,
    "request_location": null,
    "played_at": null,
    "created_at": "2026-02-14T17:10:00+00:00"
  }
}
```

### Error responses

**User does not have access to project (`404`)**

**Validation failure (`422`):**
- Invalid type, missing `custom_title`, invalid `song_id`, etc.

**Original requests disabled (`422`):**

```json
{
  "message": "This project is not currently accepting original requests."
}
```

**No active performance session (`409`)**

```json
{
  "error": "no_active_session",
  "message": "Start a performance session before adding queue items."
}
```

Clients should prompt the performer to start a session and retry.

---

## Update Manual Queue Item

- **Method**: `PATCH`
- **Path**: `/queue/{requestId}`

Update the tip amount on a manual (performer-added) queue item. Only manual items
(`is_manual=true`) that are still active can be edited.

### Request body

```json
{
  "tip_amount_cents": 1000
}
```

**Validation Rules:**
- `tip_amount_cents`: required, integer, min 0
- `tip_amount_cents` values with cents are rounded up to the next whole-dollar
  amount before persistence and response serialization

### Success response (`200`)

```json
{
  "message": "Queue item updated.",
  "request": {
    "id": 42,
    "performance_session_id": 7,
    "session_sequence": 13,
    "song": {
      "id": 1,
      "title": "Crowd Favorite Mashup",
      "artist": "Custom Request"
    },
    "tip_amount_cents": 1000,
    "tip_amount_dollars": "10",
    "status": "active",
    "requester_name": null,
    "note": null,
    "is_manual": true,
    "request_location": null,
    "played_at": null,
    "created_at": "2026-02-14T17:10:00+00:00"
  }
}
```

### Error responses

**User does not have access to project (`404`)**

**Not a manual queue item (`403`)**

```json
{
  "message": "Only manual queue items can be edited."
}
```

**Item already played (`422`)**

```json
{
  "message": "Only active queue items can be edited."
}
```

---

## Get Request History

- **Method**: `GET`
- **Path**: `/requests/history`

Get the non-active request history for the current project (paginated).

Archive/history in mobile is backed by this endpoint. The API keeps the
existing played-request payload shape and existing active/played status model;
there is no separate archived status.

### Query Parameters

| Param | Type | Description |
|-------|------|-------------|
| `per_page` | int | Items per page (default: 50) |
| `page` | int | Page number |

### Success response (`200`)

Same item shape as the queue response, but paginated and with `status: "played"`
plus `played_at` populated.

History includes custom, original, repertoire, and tip-only requests once they
are no longer active. The mobile archive may also show a local secondary list
of dismissed tip-only items that are restorable back into the live queue strip.

### Error response (`403`)

Returned when the owning project does not expose
`entitlements.can_access_history=true`.

---

## Delete Queue Request

- **Method**: `DELETE`
- **Path**: `/queue/{requestId}`

Permanently remove an active request from the queue. Unlike marking as played,
deleted requests do not appear in history. Owner-only — members receive `403`.

### Success response (`200`)

```json
{
  "message": "Queue item deleted."
}
```

### Error responses

**User does not have access to project (`404`)**

**Item already played (`422`)**

```json
{
  "message": "Only active queue items can be deleted."
}
```

---

## Reorder Queue

- **Method**: `PUT`
- **Path**: `/queue/reorder`

Set a custom display order for the active queue. When a custom order is set, it
takes precedence over the default tip-based sort. New requests that arrive after
reordering are appended to the end of the custom order.

### Request body

```json
{
  "request_ids": [3, 1, 2]
}
```

`request_ids` must contain exactly the set of currently active request IDs. Any
mismatch (missing or extra IDs) returns `422`.

### Success response (`200`)

```json
{
  "message": "Queue reordered.",
  "data": [
    {
      "id": 3,
      "performance_session_id": 7,
      "session_sequence": 4,
      "song": { "id": 10, "title": "Bohemian Rhapsody", "artist": "Queen" },
      "tip_amount_cents": 500,
      "tip_amount_dollars": "5",
      "status": "active",
      "requester_name": null,
      "note": null,
      "is_manual": true,
      "request_location": null,
      "played_at": null,
      "created_at": "2026-02-14T17:10:00+00:00"
    }
  ]
}
```

### Error responses

**User does not have access to project (`404`)**

**ID mismatch (`422`)**

```json
{
  "message": "request_ids must contain exactly the current active request IDs."
}
```

---

## Mark Request as Played

- **Method**: `POST`
- **Path**: `/me/requests/{requestId}/played` (note: not scoped to project in path)

Mark a request as played.

### Success response (`200`)

```json
{
  "message": "Request marked as played.",
  "request": {
    "id": 42,
    "performance_session_id": 7,
    "session_sequence": 12,
    "song": {
      "id": 1,
      "title": "Fly Me to the Moon",
      "artist": "Frank Sinatra"
    },
    "tip_amount_cents": 1500,
    "tip_amount_dollars": "15",
    "status": "played",
    "requester_name": "Jane Smith",
    "note": "Happy birthday!",
    "is_manual": false,
    "request_location": "Denver, Colorado, US",
    "activated_at": "2026-02-03T12:00:00+00:00",
    "played_at": "2026-02-03T12:30:00+00:00",
    "created_at": "2026-02-03T11:55:00+00:00"
  }
}
```

### Error response (`403`)

Not authorized to mark this request.

---

## Mark Reward Claim as Delivered

- **Method**: `POST`
- **Path**: `/me/reward-claims/{rewardClaimId}/delivered` (note: not scoped to project in path)

Mark a pending physical reward claim as physically handed over to the audience
member. The performer triggers this by tapping the reward in their queue.

The endpoint is **idempotent**: calling it on a claim that is already delivered
preserves the original `claimed_at` timestamp and still returns `200`.

After this call, the claim is removed from the next `meta.pending_rewards`
payload and the `delivered_at` timestamp counts toward
`RewardThresholdService::availableClaims` (so it correctly exhausts a
non-repeating reward and progresses a repeating one).

### Success response (`200`)

```json
{
  "message": "Reward marked as delivered.",
  "reward_claim": {
    "id": 17,
    "reward_threshold_id": 4,
    "reward_label": "Free CD",
    "reward_icon": "album",
    "reward_icon_emoji": "💿",
    "reward_description": "Come up to the stage after the song.",
    "audience_display_name": "Jane Smith",
    "earned_at": "2026-04-08T19:32:14+00:00"
  }
}
```

### Error response (`404`)

Returned when the reward claim does not exist or its threshold belongs to a
project the authenticated user does not have access to.

---

## Block an Audience Member

- **Method**: `POST`
- **Path**: `/audience/{audienceProfile}/block`
- **Route prefix**: `/api/v1/me/projects/{project_id}`

Block an audience member for this project. **Owner-only — members receive
`403`.** The block key is the per-project audience identity
`audience_profiles.id` (`audience_profile_id`), never the requester name.

Blocking:

- Hides the profile's existing pending (`active`) requests from
  `GET /queue`.
- Does **not** hide the member's activity from the performance-detail
  timeline. The timeline KEEPS their events, labeled blocked
  (`is_blocked: true`); a reported request's events carry
  `is_reported: true`. Blocking never erases history. A discrete
  `audience_blocked` (and symmetric `audience_unblocked`) timeline event is
  logged on each real transition (see `song-performances.md`).
- Silently refuses the profile's future public submissions with the `422`
  `audience_blocked` error (see `public.md`). That check runs **before** any
  Stripe PaymentIntent, so a blocked member is never charged.
- Does **not** refund any charge already made. Charges stand
  (credit-at-request-time, `.agent-rules/15-patent-constraints.md`). Block
  is never tied to a performance/"played" signal.

The endpoint is **idempotent**: blocking an already-blocked profile is a
no-op and still returns `200`. `Idempotency-Key` is supported for safe
retries.

`audience_profile_id` is nullable on requests (null for cash/manual/
anonymous/legacy). The block affordance is hidden in clients when it is
null; there is no profile to block.

### Success response (`200`)

```json
{
  "data": {
    "audience_profile_id": 88,
    "display_name": "Jane Smith",
    "last_seen_at": "2026-05-30T19:32:14+00:00",
    "blocked_at": "2026-06-01T20:10:00+00:00"
  }
}
```

The `data` payload is the `BlockedAudienceProfile` resource (see below).

### Error responses

- `403` — caller is not the project owner.
- `404` — caller lacks access to the project, or the audience profile does
  not belong to this project.

---

## Unblock an Audience Member

- **Method**: `DELETE`
- **Path**: `/audience/{audienceProfile}/block`
- **Route prefix**: `/api/v1/me/projects/{project_id}`

Unblock an audience member. **Owner-only — members receive `403`.** Clears
`blocked_at` and `blocked_by_user_id`.

Unblock only re-allows **future** requests; it does **not** resurrect
previously-hidden requests. The endpoint is **idempotent**: unblocking a
profile that is not blocked is a no-op and still returns `200`.
`Idempotency-Key` is supported.

### Success response (`200`)

```json
{
  "data": {
    "audience_profile_id": 88,
    "is_blocked": false
  }
}
```

### Error responses

- `403` — caller is not the project owner.
- `404` — caller lacks access to the project, or the audience profile does
  not belong to this project.

---

## List Blocked Audience Members

- **Method**: `GET`
- **Path**: `/audience/blocked`
- **Route prefix**: `/api/v1/me/projects/{project_id}`

List this project's blocked audience profiles, newest blocked first
(`blocked_at DESC`). **Owner-only — members receive `403`.** Backs the
"Blocked audience" list in Settings.

### Success response (`200`)

```json
{
  "data": [
    {
      "audience_profile_id": 88,
      "display_name": "Jane Smith",
      "last_seen_at": "2026-05-30T19:32:14+00:00",
      "blocked_at": "2026-06-01T20:10:00+00:00"
    }
  ]
}
```

### Error responses

- `403` — caller is not the project owner.
- `404` — caller does not have access to the project.

### `BlockedAudienceProfile` resource

| Field | Type | Notes |
|-------|------|-------|
| `audience_profile_id` | int | The blocked profile's `audience_profiles.id`. |
| `display_name` | string \| null | Best available label (profile name, else most recent `requester_name`). `null` when no name is known. |
| `last_seen_at` | string(date-time) \| null | When the profile was last seen, if recorded. |
| `blocked_at` | string(date-time) | When the profile was blocked. Non-null in this list. |

---

## Report Audience Content

- **Method**: `POST`
- **Path**: `/requests/{request}/report`
- **Route prefix**: `/api/v1/me/projects/{project_id}`

Flag an audience request's free text (the `note` and/or the requester
display name) for admin review. **Owner-only — members receive `403`.**
Reporting is a **flag, not a block**: it does **not** remove or hide the
request row. To stop a member's future submissions, block the audience
profile instead (see "Block an Audience Member").

The report targets the request id (`requests.id`), not the
`audience_profile_id`, so the affordance stays available on anonymous rows
that carry no profile to block. **Manually-added requests** (performer-entered
queue items, `payment_provider: "none"`) are the one exception: their text is
authored by the performer, not the audience, so they are **not** reportable —
the affordance is hidden client-side and the endpoint returns `422`. Other
gating mirrors the block endpoint: `404` when the caller lacks access to the
project or the request does not belong to this project; `403` when the caller
is not the owner.

### Request body (optional)

```json
{
  "reason": "Hate speech in the dedication."
}
```

- `reason` — optional, nullable string, max 500 chars.

### Behavior

- **Idempotent**: a report is deduplicated on `(request_id,
  reported_by_user_id)`. A given user can report a given request only once.
- On **first creation**, an admin notification (`ContentReportSubmission`,
  queued to `config('mail.from.address')`) is sent so a human can action it
  within 24 hours. The reported request's note + requester display name, the
  reporting performer, the project, the reason, and a timestamp are included.
- A **duplicate** report by the same user is a silent no-op: no second report
  row is created and **no second admin notification is queued**. Both the
  first creation and the idempotent no-op return `200`.
- On **first creation**, a discrete `content_reported` timeline event is
  logged (tied to the reported request's own session; see
  `song-performances.md`), and that request's other timeline events carry
  `is_reported: true` so the performance detail can label them. The duplicate
  no-op logs no second event.
- The requester is **never** notified. `Idempotency-Key` is supported.

### Success response (`200`)

```json
{
  "data": {
    "id": 42,
    "status": "open",
    "created_at": "2026-06-01T22:30:00+00:00"
  }
}
```

`status` is one of `open`, `reviewed`, `dismissed` (admin-managed; reports
are created `open`).

### Error responses

- `403` — caller is not the project owner.
- `404` — caller lacks access to the project, or the request does not belong
  to this project.
- `422` — the request is a manually-added (performer-entered) queue item
  (`payment_provider: "none"`), which carries no audience content to report.

---

## Delete Performance Session

- **Method**: `DELETE`
- **Path**: `/me/projects/{project_id}/performances/{sessionId}`
- **Route prefix**: `/api/v1`

Permanently deletes a past performance session and recalculates song stats.

**Cascade behavior:**
- All `SongPerformance` records linked to the session are deleted. Because the FK on `song_performances.performance_session_id` is `nullOnDelete`, the app must delete them explicitly before removing the session — the service handles this.
- `performance_session_items` are deleted via database `CASCADE`.
- `requests.performance_session_id` and `tip_bucket_totals.performance_session_id` are set to `NULL` via `nullOnDelete`; the request and tip bucket total records themselves are preserved.

**Stat recalculation:**
For each `ProjectSong` that had records in the deleted session, `performance_count` is recalculated from remaining `SongPerformance` rows and `last_performed_at` is set to the max `performed_at` of remaining rows (or `null` if none remain).

### Headers

- `Idempotency-Key`: UUID v4 (recommended for safe retries)

### Success response (`204`)

Empty body.

### Error responses

**Active session (`422`):**

```json
{
  "message": "Cannot delete an active performance session."
}
```

**Session not found or cross-project (`404`):**

```json
{
  "message": "Not found."
}
```
