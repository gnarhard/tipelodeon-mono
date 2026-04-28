# Queue & Requests API Contracts

## Auth and scope

- All endpoints below require `Authorization: Bearer <token>`.
- All endpoints are performer-scoped and project-scoped via `{project_id}`.
- Route prefix: `/api/v1/me/projects/{project_id}`
- Queue and history access require the owning project to expose
  `entitlements.can_access_queue=true` / `entitlements.can_access_history=true`.
- These entitlements are Veteran-only. On Veteran-owned projects, invited members keep
  queue/history access even if their own account plan is Free or Pro.
- On Free-owned or Pro-owned projects, queue and history endpoints return `403`.

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

---

## Add Item to Queue (Manual)

- **Method**: `POST`
- **Path**: `/queue`

Manually add an item to the active queue as an authenticated performer/project member.

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

**Feature locked to Pro (`403`)**

```json
{
  "code": "feature_requires_pro",
  "message": "Queue access requires a Pro-owned project."
}
```

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

**Feature locked to Pro (`403`)**

```json
{
  "code": "feature_requires_pro",
  "message": "Queue access requires a Pro-owned project."
}
```

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
deleted requests do not appear in history. Both manual and non-manual requests
can be deleted by the project owner or members with queue access.

### Success response (`200`)

```json
{
  "message": "Queue item deleted."
}
```

### Error responses

**User does not have access to project (`404`)**

**Feature locked to Pro (`403`)**

```json
{
  "code": "feature_requires_pro",
  "message": "Queue access requires a Pro-owned project."
}
```

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

**Feature locked to Pro (`403`)**

```json
{
  "code": "feature_requires_pro",
  "message": "Queue access requires a Pro-owned project."
}
```

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
