# Cash Tip Bucket Total API Contracts

## Auth and scope

- All endpoints below require `Authorization: Bearer <token>`.
- All endpoints are performer-scoped and project-scoped via `{project_id}`.
- Route prefix: `/api/v1/me/projects/{project_id}`
- Cash cash tip bucket total management requires the user to own or have access to the project.

---

## Record Cash Tip Bucket Total

- **Method**: `POST`
- **Path**: `/tip-bucket-totals`

Record the total cash a performer accumulated in their tip bucket over a
performance session.

### Cash cash tip bucket total timing

A cash tip bucket total is typically entered **after** the performance ends, when
the performer counts the cash in their tip bucket. A single
`tip_bucket_totals` row therefore represents the bulk cash total collected
over the course of the set, not a specific moment during it. The API places
no time restriction on entry: a performer can record (or edit/delete) a tip
bucket total for any past session of their project. The derived
`tip_bucket_total` entry in `performance_events.occurred_at` is set to
`tip_bucket_totals.created_at` (i.e. when the performer logged it), which is
almost always after `performance_sessions.ended_at`. Consumers that plot
events on a session timeline — and any AI summaries — must treat the
`occurred_at` of a `tip_bucket_total` event as a bucket-tally timestamp, not
as the moment an individual cash tip was received.

### Headers

- `Idempotency-Key`: UUID v4 (required for safe retries)

### Request body

```json
{
  "performance_session_id": 42,
  "amount_cents": 2500,
  "local_date": "2026-03-15",
  "timezone": "America/Denver",
  "note": "Wedding gig"
}
```

**Validation Rules:**
- `performance_session_id`: required, integer, must belong to the project (422 otherwise)
- `amount_cents`: required, integer, min 1
- `local_date`: required, date format `Y-m-d`
- `timezone`: required, valid IANA timezone identifier
- `note`: optional, string, max 255 characters

### Success response (`201`)

```json
{
  "data": {
    "id": 1,
    "project_id": 5,
    "amount_cents": 2500,
    "local_date": "2026-03-15",
    "timezone": "America/Denver",
    "note": "Wedding gig",
    "performance_session_id": 42,
    "created_at": "2026-03-15T22:30:00+00:00"
  }
}
```

**Fields:**
- `performance_session_id`: integer. The performance session this cash tip bucket total is explicitly linked to. Selected by the client; required on every request.

### Error responses

**User does not have access to project (`404`)**

**Validation failure (`422`):**
- Missing or invalid `performance_session_id`, invalid `amount_cents`, missing `local_date`, invalid `timezone`, etc.
- Session does not belong to the project → `422`.

---

## Update Cash Tip Bucket Total

- **Method**: `PATCH`
- **Path**: `/tip-bucket-totals/{tipBucketTotalId}`

Update a previously recorded cash tip bucket total.

### Request body

```json
{
  "performance_session_id": 43,
  "amount_cents": 3000,
  "local_date": "2026-03-16",
  "timezone": "America/Denver",
  "note": "Updated note"
}
```

**Validation Rules:**
- `performance_session_id`: required, integer, must belong to the project (422 otherwise)
- `amount_cents`: required, integer, min 1
- `local_date`: required, date format `Y-m-d`
- `timezone`: required, valid IANA timezone identifier
- `note`: optional, string, max 255 characters

### Success response (`200`)

```json
{
  "data": {
    "id": 1,
    "project_id": 5,
    "amount_cents": 3000,
    "local_date": "2026-03-16",
    "timezone": "America/Denver",
    "note": "Updated note",
    "performance_session_id": 43,
    "created_at": "2026-03-15T22:30:00+00:00"
  }
}
```

### Error responses

**User does not have access to project (`404`)**

**Cash cash tip bucket total not found or does not belong to project (`404`)**

**Validation failure (`422`):**
- Missing or invalid `performance_session_id`, invalid `amount_cents`, missing `local_date`, invalid `timezone`, etc.
- Session does not belong to the project → `422`.

---

## List Cash Tip Bucket Totals

- **Method**: `GET`
- **Path**: `/tip-bucket-totals`

List cash tip bucket totals for the project, optionally filtered by local date.

### Query parameters

| Param | Type | Description |
|-------|------|-------------|
| `local_date` | string | Filter to a single date (`YYYY-MM-DD`). Optional. |
| `per_page` | int | Items per page (default: 50) |
| `page` | int | Page number |

### Success response (`200`)

Standard paginated shape:

```json
{
  "data": [
    {
      "id": 1,
      "project_id": 5,
      "amount_cents": 2500,
      "local_date": "2026-03-15",
      "timezone": "America/Denver",
      "note": "Wedding gig",
      "performance_session_id": 42,
      "created_at": "2026-03-15T22:30:00+00:00"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 50,
    "total": 1
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": null
  }
}
```

---

## Delete Cash Tip Bucket Total

- **Method**: `DELETE`
- **Path**: `/tip-bucket-totals/{tipBucketTotalId}`

Remove a previously recorded cash tip bucket total.

### Success response (`200`)

```json
{
  "message": "Cash cash tip bucket total deleted."
}
```

### Error responses

**User does not have access to project (`404`)**

**Cash cash tip bucket total not found or does not belong to project (`404`)**

---

## Stats Integration

Cash cash tip bucket totals are included in the project stats `money` section as a
separate `tip_bucket_total_amount_cents` field. This amount is **not**
included in `gross_tip_amount_cents`, `fee_amount_cents`, or
`net_tip_amount_cents` — those fields reflect only SongTipper
(digital/Stripe) request tips.

Tips on manual queue items (`payment_provider = 'none'`) are also included
in `tip_bucket_total_amount_cents` alongside manually-recorded tip bucket
totals. They are excluded from the digital tip fields
(`gross_tip_amount_cents`, `net_tip_amount_cents`, `fee_amount_cents`) and
from the tip-amount distribution and fee breakdown.

Cash cash tip bucket totals and manual queue item tips **are** included in the
best-day record calculation, which sums digital request tips, tip bucket
totals, and manual queue item tips per local date.

Cash cash tip bucket totals inherit location attribution through the linked
performance session.
