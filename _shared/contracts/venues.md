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

### Query parameters

| Param | Type | Description |
|-------|------|-------------|
| `per_page` | int | Items per page (default: 50) |
| `page` | int | Page number |

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

---

## Personal Venue Analytics

- **Method**: `GET`
- **Path**: `/venues/{venueId}/analytics`
- **Full path**: `/api/v1/me/projects/{project_id}/venues/{venueId}/analytics`

Returns analytics for a specific venue scoped to the authenticated project. Only completed sessions with at least $0.01 in total tips, a minimum duration of 45 minutes, and a non-private-event gig type are included.

### Success response (`200`)

```json
{
  "data": {
    "session_count": 12,
    "total_tips_cents": 285000,
    "most_requested_songs": [
      { "song_id": 1, "title": "Fly Me to the Moon", "artist": "Frank Sinatra", "request_count": 8 }
    ],
    "most_lucrative_songs": [
      { "song_id": 1, "title": "Fly Me to the Moon", "artist": "Frank Sinatra", "total_tip_cents": 12000 }
    ],
    "tip_range_by_day_of_week": {
      "friday": { "min_cents": 8000, "median_cents": 16000, "max_cents": 24000, "session_count": 5 },
      "saturday": { "min_cents": 12000, "median_cents": 20000, "max_cents": 35000, "session_count": 4 }
    },
    "ai_insights": "Audiences at Mike's Bar prefer upbeat classic rock on Fridays...",
    "ai_insights_generated_at": "2026-04-01T10:00:00+00:00"
  }
}
```

**Session qualification rules:**
- `is_active = false` (session must be completed)
- Duration ≥ 45 minutes (`ended_at - started_at`)
- `gig_type` is not `private_event`
- Total tips (digital + cash) > $0

**DOW keys:** `sunday`, `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`. Only DOWs with at least one qualifying session are included. DOW is computed in the session's snapshotted `timezone`.

**AI insights:** Generated by Haiku 4.5, cached 14 days. `null` when not yet generated or AI is disabled.

### Error responses

**Venue not found or cross-project (`404`):**

```json
{ "message": "Resource not found." }
```

---

## Crowd-Sourced Venue Search

- **Method**: `GET`
- **Path**: `/me/venues/search`
- **Full path**: `/api/v1/me/venues/search`

Searches physical venues (matched by `places_provider_id`) across ALL projects, aggregated by Google Place ID. Requires auth but is NOT project-scoped.

### Query parameters

| Param | Type | Description |
|-------|------|-------------|
| `q` | string | Required search query (venue name). Min 2 chars, max 100 chars. |
| `latitude` | decimal | Optional current GPS latitude (-90 to 90) |
| `longitude` | decimal | Optional current GPS longitude (-180 to 180) |

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
      "performer_count": 7,
      "has_analytics": true
    }
  ]
}
```

**Notes:**
- Only venues with a non-null `places_provider_id` appear in results.
- `performer_count` = count of distinct `project_id`s with qualifying sessions at this Place ID.
- `has_analytics` = true when `performer_count >= 3`.
- Results are ordered by name ascending when no coordinates provided; by proximity when coordinates provided.
- Maximum 20 results returned.

### Error responses

**Validation error (`422`):** `q` missing or too short/long.

---

## Crowd-Sourced Venue Analytics

- **Method**: `GET`
- **Path**: `/me/venues/crowd-sourced/{placesProviderId}/analytics`
- **Full path**: `/api/v1/me/venues/crowd-sourced/{placesProviderId}/analytics`

Returns aggregated analytics from ALL performers who have qualifying sessions at a venue identified by Google Place ID. NOT project-scoped.

### Success response (`200`)

Same shape as personal analytics but aggregated across all projects. Song data uses global `songs.title` / `songs.artist` (not project-level overrides).

```json
{
  "data": {
    "performer_count": 7,
    "session_count": 45,
    "total_tips_cents": 1250000,
    "most_requested_songs": [...],
    "most_lucrative_songs": [...],
    "tip_range_by_day_of_week": {...},
    "ai_insights": "...",
    "ai_insights_generated_at": "2026-04-01T10:00:00+00:00"
  }
}
```

### Error responses

**Insufficient data (`403`):** Fewer than 3 unique performers have qualifying sessions.

```json
{
  "error": {
    "code": "insufficient_data",
    "message": "Not enough performer data. At least 3 performers required.",
    "details": { "performer_count": 2, "required": 3 }
  }
}
```

**Venue not found (`404`):** No venue with this `places_provider_id` exists.

```json
{ "message": "Resource not found." }
```
