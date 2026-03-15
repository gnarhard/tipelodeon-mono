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

Record a cash tip received for a specific local date.

### Headers

- `Idempotency-Key`: UUID v4 (required for safe retries)

### Request body

```json
{
  "amount_cents": 2500,
  "local_date": "2026-03-15",
  "timezone": "America/Denver",
  "note": "Wedding gig"
}
```

**Validation Rules:**
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
    "created_at": "2026-03-15T22:30:00+00:00"
  }
}
```

### Error responses

**User does not have access to project (`404`)**

**Validation failure (`422`):**
- Invalid `amount_cents`, missing `local_date`, invalid `timezone`, etc.

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

Cash tips **are** included in the best-day record calculation, which sums both
digital request tips and cash tips per local date.
