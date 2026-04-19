# Cash Tips API Contracts

## Auth and scope

- All endpoints below require `Authorization: Bearer <token>`.
- All endpoints are performer-scoped and project-scoped via `{project_id}`.
- Route prefix: `/api/v1/me/projects/{project_id}`
- Cash tip management requires the user to own or have access to the project.

---

## Record Cash Tip

- **Method**: `POST`
- **Path**: `/cash-tips`

Record a cash tip received for a specific performance session.

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
- `performance_session_id`: integer. The performance session this cash tip is explicitly linked to. Selected by the client; required on every request.

### Error responses

**User does not have access to project (`404`)**

**Validation failure (`422`):**
- Missing or invalid `performance_session_id`, invalid `amount_cents`, missing `local_date`, invalid `timezone`, etc.
- Session does not belong to the project → `422`.

---

## Update Cash Tip

- **Method**: `PATCH`
- **Path**: `/cash-tips/{cashTipId}`

Update a previously recorded cash tip.

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

**Cash tip not found or does not belong to project (`404`)**

**Validation failure (`422`):**
- Missing or invalid `performance_session_id`, invalid `amount_cents`, missing `local_date`, invalid `timezone`, etc.
- Session does not belong to the project → `422`.

---

## List Cash Tips

- **Method**: `GET`
- **Path**: `/cash-tips`

List cash tips for the project, optionally filtered by local date.

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

## Delete Cash Tip

- **Method**: `DELETE`
- **Path**: `/cash-tips/{cashTipId}`

Remove a previously recorded cash tip.

### Success response (`200`)

```json
{
  "message": "Cash tip deleted."
}
```

### Error responses

**User does not have access to project (`404`)**

**Cash tip not found or does not belong to project (`404`)**

---

## Stats Integration

Cash tips are included in the project stats `money` section as a separate
`cash_tip_amount_cents` field. This amount is **not** included in
`gross_tip_amount_cents`, `fee_amount_cents`, or `net_tip_amount_cents` — those
fields reflect only SongTipper (digital/Stripe) request tips.

Tips on manual queue items (`payment_provider = 'none'`) are also included in
`cash_tip_amount_cents` alongside manually-recorded cash tips. They are excluded
from the digital tip fields (`gross_tip_amount_cents`, `net_tip_amount_cents`,
`fee_amount_cents`) and from the tip-amount distribution and fee breakdown.

Cash tips and manual queue item tips **are** included in the best-day record
calculation, which sums digital request tips, cash tips, and manual queue item
tips per local date.

Cash tips inherit location attribution through the linked performance session.
