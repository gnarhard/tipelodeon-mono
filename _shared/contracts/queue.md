# Queue & Requests API Contracts

## Auth and scope

- All endpoints below require `Authorization: Bearer <token>`.
- All endpoints are performer-scoped and project-scoped via `{project_id}`.
- Route prefix: `/api/v1/me/projects/{project_id}`
- Queue and history access require the owning project to expose
  `entitlements.can_access_queue=true` / `entitlements.can_access_history=true`.
- On Pro-owned projects, invited members keep queue/history access even if
  their own account plan is Basic.
- On Basic-owned projects, queue and history endpoints return `403`.

---

## Get Active Queue

- **Method**: `GET`
- **Path**: `/queue`

Get the active request queue for a project. **Supports ETag caching** for
efficient polling.

Queue is ordered by `tip_amount_cents DESC`, then `created_at ASC`.

### Query parameters

- `timezone` (optional): IANA timezone used to compute the queue
  `meta.daily_record_event`. Defaults to `UTC` when omitted or invalid.

### Headers

- `If-None-Match`: Previous ETag value (optional)

### Success response (`200`)

```json
{
  "data": [
    {
      "id": 42,
      "audience_profile_id": null,
      "performance_session_id": null,
      "song": {
        "id": 1,
        "title": "Fly Me to the Moon",
        "artist": "Frank Sinatra"
      },
      "tip_amount_cents": 1500,
      "tip_amount_dollars": "15",
      "status": "active",
      "note": "Happy birthday!",
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
    }
  }
}
```

**Response includes `ETag` header.**

- `meta.daily_record_event` is `null` unless the current local day sets a new
  project lifetime one-day gross-tip record.
- The queue `ETag` must change when either the active queue payload or the
  `daily_record_event` changes.

### Not Modified response (`304`)

No changes since last request (when `If-None-Match` matches). No body is
returned. Matching includes both the queue payload and the record-event state.

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
    "note": "Mash two choruses if possible",
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
    "requester_name": null,
    "note": "Happy birthday!",
    "activated_at": "2026-02-03T12:00:00+00:00",
    "played_at": "2026-02-03T12:30:00+00:00",
    "created_at": "2026-02-03T11:55:00+00:00"
  }
}
```

### Error response (`403`)

Not authorized to mark this request.
