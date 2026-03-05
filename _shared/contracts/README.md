# API Contracts Directory

This directory contains the detailed API contract specifications for Song Tipper, organized by resource/domain.

## Overview

`_shared/api/openapi.yaml` is the canonical machine-readable source of truth.

Contract markdown files in this directory provide domain-oriented explanations and implementation notes that align with the OpenAPI document.

## Contract Files

### Source of truth

- **[../api/openapi.yaml](../api/openapi.yaml)** - Canonical API contract (v1.2)

### Core Resources

- **[auth.md](./auth.md)** - Authentication & password management
  - Login/logout
  - Password reset flow
  - Update password
  - Web dashboard billing gate and performer subscription setup

- **[projects.md](./projects.md)** - Project management
  - CRUD operations for projects (bands/solo acts)
  - Project settings and configuration
  - Performer profile image upload

- **[payouts.md](./payouts.md)** - Stripe Connect payout account management
  - Payout account readiness status
  - Onboarding link generation
  - Express dashboard login links

- **[repertoire.md](./repertoire.md)** - Song repertoire management
  - Add/edit/delete songs in repertoire
  - Song metadata enrichment (Gemini)
  - Performance tracking
  - Bulk PDF import

- **[queue.md](./queue.md)** - Request queue management
  - View active queue (with ETag caching)
  - Manually add items to queue
  - Mark requests as played
  - Request history

- **[charts.md](./charts.md)** - Chart (sheet music) management
  - Upload PDF charts
  - Chart rendering (PDF → PNG)
  - Chart annotations with offline support
  - Signed URLs for downloads

- **[setlists.md](./setlists.md)** - Setlist builder
  - Create/edit/delete setlists
  - Manage sets within setlists
  - Add/remove/reorder songs
  - Bulk operations

### Public/Audience Resources

- **[public.md](./public.md)** - Public audience-facing endpoints
  - Browse public repertoire
  - Create song requests with payment

## Common Patterns

### Authentication

- **Public endpoints** (auth.md, public.md) - No auth required
- **Authenticated endpoints** - Require `Authorization: Bearer {token}` header
  - Token obtained via `/api/v1/auth/login`
  - Laravel Sanctum tokens

### Response Format

**Success:**
```json
{
  "data": ...,
  "meta": { ... }
}
```

**Error:**
```json
{
  "message": "Human-readable error message",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

### Idempotency

- Retryable writes support `Idempotency-Key`.
- Mobile outbox writes must send stable keys per logical operation.

### HTTP Status Codes

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

### Data Conventions

- **Field naming:** `snake_case` in JSON (mapped to `camelCase` in Dart)
- **Dates:** ISO 8601 format with timezone (e.g., `2026-02-11T22:20:10+00:00`)
- **Currency:** Integer cents (e.g., `1500` = $15.00)
- **Pagination:** Laravel paginator format with `data`, `meta`, `links`

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

## Using These Contracts

### For Backend Developers (Laravel)

1. Implement endpoints matching the documented request/response shapes
2. Use Laravel form requests for validation rules
3. Return consistent JSON using API resources
4. Update contracts when changing API behavior

### For Frontend Developers (Flutter)

1. Generate Dart models from contract specifications
2. Map `snake_case` JSON to `camelCase` Dart fields
3. Handle all documented error responses
4. Update contracts when new features need API changes

### For QA/Testing

1. Use contracts to write API test cases
2. Verify request/response formats match documentation
3. Test all error scenarios documented
4. Validate enum values and field constraints

## Updating Contracts

When modifying the API:

1. **Update the contract file first** before implementing
2. Ensure backwards compatibility or document breaking changes
3. Keep field descriptions and validation rules current
4. Add examples for complex request/response shapes
5. Document any behavioral notes or edge cases

## Related Documentation

- [../api-contract-rules.md](../api-contract-rules.md) - Main API overview and general rules
- [../audience-achievements.md](../audience-achievements.md) - Audience gamification features
- [../../ARCHITECTURE.md](../../ARCHITECTURE.md) - Full system architecture documentation
- [../../CLAUDE.md](../../CLAUDE.md) - Development workflow and agent instructions
