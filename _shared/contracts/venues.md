# Venues API Contracts

## Auth and scope

- All endpoints below require `Authorization: Bearer <token>`.
- All endpoints are performer-scoped and project-scoped via `{project_id}`.
- Route prefix: `/api/v1/me/projects/{project_id}`
- Venue management requires the user to own or have access to the project.

---

## List Venues

- **Method**: `GET`
- **Path**: `/venues`

List all venues for the project, paginated and ordered by `name ASC`.

`session_count` reflects all **completed sessions** (`is_active=false`, `ended_at` not null). Active sessions are excluded.

### Query parameters

| Param | Type | Description |
|-------|------|-------------|
| `per_page` | int | Items per page (default: 50) |
| `page` | int | Page number |
| `start_date` | `YYYY-MM-DD` | Optional. Scopes `session_count` to qualifying sessions starting on or after this date. When provided, venues with no qualifying sessions in the range are excluded. |
| `end_date` | `YYYY-MM-DD` | Optional. Scopes `session_count` to qualifying sessions ending on or before this date (inclusive through `23:59:59`). |

### Success response (`200`)

```json
{
  "data": [
    {
      "id": 1,
      "project_id": 5,
      "name": "Mike's Bar",
      "address": "123 Main St",
      "city": "Denver",
      "region": "Colorado",
      "country": "US",
      "latitude": 39.7392358,
      "longitude": -104.990251,
      "timezone": "America/Denver",
      "places_provider_id": "ChIJ...",
      "session_count": 4,
      "created_at": "2026-04-01T10:00:00+00:00",
      "updated_at": "2026-04-01T10:00:00+00:00"
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

## Suggest Venue

- **Method**: `POST`
- **Path**: `/venues/suggest`

Suggest nearby venues based on the performer's current GPS coordinates. Uses a two-layer lookup: existing project venues first (Layer 1, DB-only), then Google Places Nearby Search as a fallback (Layer 2/3, cached POI or live API call).

Rate limited: **10 calls per minute per project.**

### Request body

```json
{
  "latitude": 39.7392,
  "longitude": -104.9903,
  "accuracy_meters": 15.0
}
```

**Validation Rules:**
- `latitude`: required, decimal, -90 to 90
- `longitude`: required, decimal, -180 to 180
- `accuracy_meters`: required, float, min 0

### Success response (`200`)

```json
{
  "data": {
    "nearby_existing": [
      { "id": 1, "name": "Mike's Bar", "address": "123 Main St", "distance_meters": 42.5 }
    ],
    "places_suggestions": [
      {
        "name": "Gaylord of the Rockies",
        "address": "6700 N Gaylord Rockies Blvd",
        "latitude": 39.8283,
        "longitude": -104.7614,
        "places_provider_id": "ChIJ...",
        "distance_meters": 120.3
      }
    ]
  }
}
```

**Response fields:**

- `nearby_existing`: project venues within 152 meters (500 ft) of the provided coordinates, ordered by distance ASC. These are Layer 1 results (DB-only, no external API cost).
- `places_suggestions`: Google Places Nearby Search results for the coordinates. Only returned when `nearby_existing` is empty OR when the client explicitly requests them. These are Layer 2/3 results (cached POI lookup, falling back to a live Google Places API call). Google Places responses are cached server-side with a 30-day TTL using a rounded-coordinate key.

### Error responses

**Rate limited (`429`):**

```json
{
  "message": "Too many venue suggestion requests. Try again later."
}
```

---

## Create Venue

- **Method**: `POST`
- **Path**: `/venues`

Create a new venue for the project.

### Headers

- `Idempotency-Key`: UUID v4 (required for safe retries)

### Request body

```json
{
  "name": "Mike's Bar",
  "address": "123 Main St",
  "city": "Denver",
  "region": "Colorado",
  "country": "US",
  "latitude": 39.7392,
  "longitude": -104.9903,
  "timezone": "America/Denver",
  "places_provider_id": "ChIJ..."
}
```

**Validation Rules:**
- `name`: required, string, max 120, unique per project
- `address`: optional, string, max 255
- `city`: optional, string, max 255
- `region`: optional, string, max 255
- `country`: optional, string, max 255
- `latitude`: optional, decimal, -90 to 90
- `longitude`: optional, decimal, -180 to 180
- `timezone`: optional, valid IANA timezone identifier
- `places_provider_id`: optional, string, max 255 (stores Google place_id)

### Success response (`201`)

```json
{
  "data": {
    "id": 1,
    "project_id": 5,
    "name": "Mike's Bar",
    "address": "123 Main St",
    "city": "Denver",
    "region": "Colorado",
    "country": "US",
    "latitude": 39.7392,
    "longitude": -104.9903,
    "timezone": "America/Denver",
    "places_provider_id": "ChIJ...",
    "created_at": "2026-04-01T10:00:00+00:00",
    "updated_at": "2026-04-01T10:00:00+00:00"
  }
}
```

### Error responses

**Duplicate venue name (`422`):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["A venue with this name already exists in this project."]
  },
  "existing_venue": {
    "id": 1,
    "name": "Mike's Bar"
  }
}
```

**Validation failure (`422`):**
- Missing `name`, invalid `latitude`/`longitude` range, invalid `timezone`, etc.

---

## Update Venue

- **Method**: `PATCH`
- **Path**: `/venues/{venueId}`

Update an existing venue. All fields are optional. `name` uniqueness is enforced if provided.

### Request body

```json
{
  "name": "Mike's Updated Bar",
  "address": "456 Oak Ave",
  "city": "Boulder",
  "region": "Colorado",
  "country": "US",
  "latitude": 40.0150,
  "longitude": -105.2705,
  "timezone": "America/Denver",
  "places_provider_id": "ChIJ..."
}
```

**Validation Rules:** Same as Create Venue, but all fields are optional.

### Success response (`200`)

```json
{
  "data": {
    "id": 1,
    "project_id": 5,
    "name": "Mike's Updated Bar",
    "address": "456 Oak Ave",
    "city": "Boulder",
    "region": "Colorado",
    "country": "US",
    "latitude": 40.0150,
    "longitude": -105.2705,
    "timezone": "America/Denver",
    "places_provider_id": "ChIJ...",
    "created_at": "2026-04-01T10:00:00+00:00",
    "updated_at": "2026-04-09T14:30:00+00:00"
  }
}
```

### Error responses

**Venue not found or cross-project (`404`):**

```json
{
  "message": "Resource not found."
}
```

**Duplicate venue name (`422`):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["A venue with this name already exists in this project."]
  },
  "existing_venue": {
    "id": 2,
    "name": "Mike's Updated Bar"
  }
}
```

---

## Delete Venue

- **Method**: `DELETE`
- **Path**: `/venues/{venueId}`

Delete a venue. Deleting a venue sets `venue_id = null` on all linked `performance_sessions`. Past session data is preserved; only the venue label is removed.

### Success response (`200`)

```json
{
  "message": "Venue deleted."
}
```

### Error responses

**Venue not found or cross-project (`404`):**

```json
{
  "message": "Resource not found."
}
```

---

## Merge Venues

- **Method**: `POST`
- **Path**: `/venues/merge`

Merge two venues by repointing all `performance_sessions` from the source venue to the target venue, then deleting the source. Idempotent -- calling with an already-deleted source returns `200`.

### Headers

- `Idempotency-Key`: UUID v4 (required for safe retries)

### Request body

```json
{
  "target_id": 1,
  "source_id": 2
}
```

**Validation Rules:**
- `target_id`: required, integer, must belong to the project
- `source_id`: required, integer, must belong to the project

### Success response (`200`)

```json
{
  "message": "Venues merged.",
  "venue": {
    "id": 1,
    "project_id": 5,
    "name": "Mike's Bar",
    "address": "123 Main St",
    "city": "Denver",
    "region": "Colorado",
    "country": "US",
    "latitude": 39.7392,
    "longitude": -104.9903,
    "timezone": "America/Denver",
    "places_provider_id": "ChIJ...",
    "created_at": "2026-04-01T10:00:00+00:00",
    "updated_at": "2026-04-01T10:00:00+00:00"
  }
}
```

### Error responses

**Venue not found or cross-project (`404`):**

```json
{
  "message": "Resource not found."
}
```

---

## Venue Analytics (personal)

- **Method**: `GET`
- **Path**: `/venues/{venueId}/analytics`
- **Route prefix**: `/api/v1/me/projects/{project_id}`

Returns personal analytics for a venue scoped to the authenticated project. All completed sessions (`is_active=false`, `ended_at` not null) are included regardless of duration, tips, or gig type.

### Success response (`200`)

```json
{
  "data": {
    "session_count": 12,
    "total_tips_cents": 48000,
    "most_requested_songs": [
      { "song_id": 5, "title": "Wagon Wheel", "artist": "Old Crow Medicine Show", "request_count": 8 }
    ],
    "most_lucrative_songs": [
      { "song_id": 5, "title": "Wagon Wheel", "artist": "Old Crow Medicine Show", "total_tip_cents": 9500 }
    ],
    "tip_range_by_day_of_week": {
      "friday": { "min_cents": 2000, "median_cents": 4000, "max_cents": 9000, "session_count": 5 },
      "saturday": { "min_cents": 3000, "median_cents": 5000, "max_cents": 12000, "session_count": 7 }
    },
    "ai_insights": "Fridays show strong request volume...",
    "ai_insights_generated_at": "2026-04-01T10:00:00+00:00"
  }
}
```

**Notes:**
- `tip_range_by_day_of_week` keys are lowercase English day names; only days with at least one qualifying session are included.
- `most_requested_songs` and `most_lucrative_songs` are capped at 10 entries each.
- `ai_insights` and `ai_insights_generated_at` are `null` until enough data exists or the AI generation job completes.
- DOW timezone uses the `timezone` stored on the performance session; falls back to UTC.

---

## List Venue Sessions

- **Method**: `GET`
- **Path**: `/venues/{venueId}/sessions`
- **Route prefix**: `/api/v1/me/projects/{project_id}`

Returns all completed (non-active) performance sessions for a specific venue, ordered by `started_at DESC`. Used by the mobile app to render the session list on the venue detail screen.

### Success response (`200`)

```json
{
  "data": [
    {
      "id": 42,
      "started_at": "2026-04-10T20:00:00+00:00",
      "ended_at": "2026-04-10T23:00:00+00:00",
      "gig_type": "public",
      "song_count": 14
    }
  ]
}
```

**Response fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Performance session ID. |
| `started_at` | ISO 8601 or null | Session start timestamp. |
| `ended_at` | ISO 8601 or null | Session end timestamp. |
| `gig_type` | string or null | Gig type enum value (e.g. `public`, `private_event`). |
| `song_count` | int | Number of `SongPerformance` records logged during this session. |

**Notes:**
- Active sessions (`is_active = true`) are excluded.
- Sessions from other venues or projects are excluded.

### Error responses

**Venue not found or cross-project (`404`):** Same as other venue endpoints.

---

## Search Crowd-Sourced Venues

- **Method**: `GET`
- **Path**: `/me/venues/search`
- **Route prefix**: `/api/v1`
- **Auth**: Bearer token required (not project-scoped).

Search venues by name across all projects. Returns unique physical venues grouped by `places_provider_id`.

### Query parameters

| Param | Type | Description |
|-------|------|-------------|
| `q` | string | Required. Min 2, max 100 characters. |
| `latitude` | decimal | Optional. When provided with `longitude`, results are sorted by proximity. |
| `longitude` | decimal | Optional. |

### Success response (`200`)

```json
{
  "data": [
    {
      "places_provider_id": "ChIJ...",
      "name": "Mike's Bar",
      "city": "Denver",
      "region": "Colorado",
      "country": "US",
      "performer_count": 5,
      "has_analytics": true
    }
  ]
}
```

**Notes:**
- `has_analytics` is `true` when `performer_count >= 3`.
- Max 20 results returned.
- Only venues with a `places_provider_id` (i.e. Google Places-linked) appear in results.

---

## Crowd-Sourced Analytics

- **Method**: `GET`
- **Path**: `/me/venues/crowd-sourced/{placesProviderId}/analytics`
- **Route prefix**: `/api/v1`
- **Auth**: Bearer token required (not project-scoped).

Returns aggregated analytics for a physical venue across all performers who have played there.

### Success response (`200`)

Same shape as personal analytics, plus:

```json
{
  "data": {
    "performer_count": 5,
    "session_count": 38,
    "total_tips_cents": 152000,
    "most_requested_songs": [...],
    "most_lucrative_songs": [...],
    "tip_range_by_day_of_week": {...},
    "ai_insights": "...",
    "ai_insights_generated_at": "..."
  }
}
```

### Error response — insufficient data (`403`)

Returned when fewer than 3 unique performers have qualifying sessions at this venue.

```json
{
  "error": {
    "code": "insufficient_data",
    "message": "Not enough performer data. At least 3 performers required.",
    "details": {
      "performer_count": 1,
      "required": 3
    }
  }
}
```

---

## Error responses

All venue endpoints follow the standard error shape from `_shared/api-contract-rules.md`:

**Cross-project access (`404`):**
Returned when the venue belongs to a different project than the one in the route prefix. The response is identical to a missing venue to avoid leaking the existence of venues in other projects.

```json
{
  "message": "Resource not found."
}
```

**Missing venue (`404`):**

```json
{
  "message": "Resource not found."
}
```

**Validation error (`422`):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```
