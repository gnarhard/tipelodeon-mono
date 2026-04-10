# Trending Songs Contract

Returns crowd-sourced trending songs for a country. Aggregates played requests
across all performers in the country and surfaces the most-requested songs —
subject to a minimum performer threshold that prevents individual data from
being exposed.

## Authentication

Requires a valid Sanctum bearer token. Not project-scoped.

## Endpoint

### GET `/api/v1/me/trending-songs`

**Query parameters**

| Parameter | Type | Description |
|---|---|---|
| `country` | string | Required. ISO 3166-1 alpha-2 country code (case-insensitive, e.g. `US`, `GB`). |

**Response `200`**

```json
{
  "data": [
    {
      "title": "Wagon Wheel",
      "artist": "Old Crow Medicine Show",
      "request_count": 42,
      "total_tip_cents": 18500
    }
  ]
}
```

**Fields**

| Field | Type | Description |
|---|---|---|
| `title` | string | Song title (from `songs.title`) |
| `artist` | string | Artist name (from `songs.artist`) |
| `request_count` | integer | Number of times the song was requested (status = `played`) across all performers in the country |
| `total_tip_cents` | integer | Sum of `requests.stripe_net_amount_cents` across those requests |

**Ordering**: Descending by `request_count`. Up to 5 results.

**Privacy threshold**: A song only appears if it was requested by at least 3
distinct performers (`COUNT(DISTINCT requests.project_id) >= 3`). This prevents
exposing data tied to a single performer.

**Error responses**

**Missing or invalid country (`422`):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "country": ["The country field is required."]
  }
}
```

## Notes

- Only `status = 'played'` requests are counted — pending or declined requests
  are excluded.
- The response is an empty `data` array (not a `404`) when no songs meet the
  privacy threshold for the requested country.
- `total_tip_cents` is the post-Stripe-fee net amount, matching the value used
  in the stats earnings figures.
