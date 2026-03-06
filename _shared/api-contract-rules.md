# Song Tipper API Contract - Overview

Base URL: `/api/v1`

## Detailed Contract Documentation

This document provides a high-level overview of the Song Tipper API. **Detailed endpoint specifications are organized by resource in the [`contracts/`](./contracts/) directory:**

- **[Authentication](./contracts/auth.md)** - Login, logout, password management
- **[Projects](./contracts/projects.md)** - Project CRUD and settings
- **[Repertoire](./contracts/repertoire.md)** - Song management and bulk import
- **[Queue & Requests](./contracts/queue.md)** - Queue management and request history
- **[Charts](./contracts/charts.md)** - Chart upload, rendering, and annotations
- **[Setlists](./contracts/setlists.md)** - Setlist builder and song organization
- **[Public/Audience](./contracts/public.md)** - Public repertoire and request creation

See the [contracts README](./contracts/README.md) for a complete index and usage guide.

---

## Authentication

All authenticated endpoints require a Bearer token in the `Authorization` header:
```
Authorization: Bearer {token}
```

Tokens are obtained via the login endpoint and are Laravel Sanctum tokens.

**See [contracts/auth.md](./contracts/auth.md) for detailed authentication endpoints.**

---

## Enums

### EnergyLevel
```
low | medium | high
```

### RequestStatus
```
active | played
```

(Internally may use `pending` but public API only exposes `active` and `played`)

### PerformanceSource
```
repertoire | setlist
```

---

## API Design Principles

### 1. Versioning

All endpoints are prefixed with `/api/v1` to allow for future API versions without breaking existing clients.

### 2. Response Format

**Success responses:**
```json
{
  "data": ...,
  "meta": { ... }
}
```

**Error responses:**
```json
{
  "message": "Human-readable error message",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

### 3. Field Naming

- **Backend (JSON):** `snake_case` keys
- **Frontend (Dart):** Mapped to `camelCase` via serializers

### 4. Dates and Times

- All timestamps in ISO 8601 format with timezone
- Example: `2026-02-11T22:20:10+00:00`
- Always UTC on server, client converts to local timezone

### 5. Currency

- All monetary values in integer cents
- Example: `1500` = $15.00
- Display formatting handled by client

### 6. Pagination

Laravel's default paginator structure:
```json
{
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 50,
    "total": 123
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}
```

### 7. Idempotency

Critical operations use client-provided UUIDs to ensure idempotency:
- Chart annotations: `local_version_id`
- Prevents duplicate writes on retry
- Server returns existing record if UUID already exists

### 8. ETag Caching

The queue endpoint supports `If-None-Match` header for efficient polling:
- Client sends last ETag value
- Server returns `304 Not Modified` if unchanged (no body)
- Server returns `200 OK` with new data + new ETag if changed

---

## HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created |
| 304 | Not Modified (ETag match) |
| 401 | Unauthorized (invalid/missing token) |
| 403 | Forbidden (no permission) |
| 404 | Not Found |
| 409 | Conflict (duplicate) |
| 422 | Validation Error |
| 429 | Rate Limited |
| 500 | Server Error |

---

## Error Handling

### Standard Laravel Validation Errors (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

### Authorization Errors (403)

```json
{
  "message": "This action is unauthorized."
}
```

### Not Found Errors (404)

```json
{
  "message": "Resource not found."
}
```

---

## Polling Best Practices

### Queue Polling

Use ETag for efficient polling (see [contracts/queue.md](./contracts/queue.md)):

```dart
final response = await http.get(
  Uri.parse('$baseUrl/v1/me/projects/$projectId/queue'),
  headers: {
    'Authorization': 'Bearer $token',
    'If-None-Match': lastEtag ?? '',
  },
);

if (response.statusCode == 304) {
  // No changes, skip processing
  return;
}

lastEtag = response.headers['etag'];
// Process new data...
```

Recommended poll interval: 5-10 seconds.

---

## Stripe Integration

### Payment Flow

1. Client creates request via `POST /v1/public/projects/{slug}/requests`
2. Backend creates Stripe Payment Intent
3. Backend returns `client_secret`
4. Client confirms payment using Stripe SDK
5. Stripe webhook notifies backend of payment success
6. Backend updates request status to `active`
7. Request appears in performer's queue

### Stripe SDK Usage (Flutter)

```dart
await Stripe.instance.confirmPayment(
  paymentIntentClientSecret: clientSecret,
  data: PaymentMethodParams.card(
    paymentMethodData: PaymentMethodData(),
  ),
);
```

Payment methods supported: Card, Apple Pay, Google Pay.

**See [contracts/public.md](./contracts/public.md) for detailed request creation flow.**

---

## Webhooks

### Stripe Webhook

- **Endpoint:** `POST /v1/webhooks/stripe`
- **Purpose:** Handle Stripe payment events
- **Security:** Signature verification required
- **Not for client use:** Internal backend endpoint

---

## Rate Limiting

Rate limiting is applied to prevent abuse:
- Public endpoints: More restrictive limits
- Authenticated endpoints: Higher limits for logged-in users
- 429 status code returned when limit exceeded

Rate limit headers (when implemented):
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1640995200
```

---

## Implementation Notes (Server-Side)

### Request Status Lifecycle

Internally, requests may use a `pending` status between initial creation and confirmed payment activation. The **public API only exposes `active` and `played` states** to simplify client logic.

### Effective Metadata Values

`energy_level` and `genre` fields in repertoire responses are **effective values**:
1. Use project-level override if set (`project_songs.energy_level`)
2. Fall back to global song metadata (`songs.energy_level`)
3. This allows per-project customization while maintaining global defaults

### Unique Constraints

- **Project Songs:** `(project_id, song_id)` - A song can only appear once per project
- **Charts:** `(owner_user_id, project_id, song_id)` - One chart per song per project per performer
- **Setlist Songs:** Cannot appear twice in the same set (database constraint)

---

## Security Considerations

### Authentication

- **Token Storage:** Clients should store tokens securely (e.g., Flutter's `flutter_secure_storage`)
- **Token Revocation:** Logout endpoint revokes token server-side
- **No Token Expiry:** Tokens don't expire but can be manually revoked

### Authorization

- **Project Scoping:** All data filtered by project membership
- **Owner Checks:** Project settings only modifiable by owner
- **Policy Classes:** Laravel policies enforce fine-grained permissions

### Input Validation

- All inputs validated using Laravel Form Requests
- File uploads limited by size and type
- SQL injection prevented by query builder/ORM
- XSS prevention via proper output encoding

---

## Breaking Changes Policy

When making breaking changes to the API:

1. **Add new endpoints/fields** instead of modifying existing ones when possible
2. **Deprecate old endpoints** with advance notice
3. **Maintain backwards compatibility** for at least one major version
4. **Document migration path** clearly in release notes
5. **Version the API** (`/api/v2`) for major breaking changes

---

## Related Documentation

- [ARCHITECTURE.md](../ARCHITECTURE.md) - Full system architecture
- [audience-achievements.md](./audience-achievements.md) - Retired audience gamification notes
- [CLAUDE.md](../CLAUDE.md) - Development workflow
