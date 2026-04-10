# Song Performance History Contract

These endpoints give the performer visibility into what they have been playing,
independent of the timeline-filtered stats. They power the "Recent Performances"
card on the Home screen and the full "Performance History" screen.

## Authentication

All endpoints require a valid Sanctum bearer token. The authenticated user must
be the project owner or a project member. Non-members receive a `404`.

## Endpoints

### GET `/api/v1/me/projects/{project}/song-performances/recent`

Returns the last 10 song performances for the project, ordered most-recent first.

**Query parameters**

| Parameter | Type | Description |
|---|---|---|
| `start_date` | `YYYY-MM-DD` | Optional. Only include performances on or after this local date. |
| `end_date` | `YYYY-MM-DD` | Optional. Only include performances on or before this local date (inclusive through `23:59:59`). |

**Response `200`**

```json
{
  "data": [
    {
      "id": 42,
      "project_song_id": 15,
      "performance_session_id": 3,
      "title": "Bohemian Rhapsody",
      "artist": "Queen",
      "performed_at": "2026-04-08T22:30:00+00:00",
      "source": "setlist",
      "source_name": "Friday Night Set",
      "is_first_performance": true,
      "was_requested": true,
      "earned_cents": 500
    }
  ]
}
```

**Fields**

| Field | Type | Description |
|---|---|---|
| `id` | integer | `song_performances.id` |
| `project_song_id` | integer | The project-song that was performed |
| `performance_session_id` | integer \| null | The session this performance belongs to, if any |
| `title` | string | Project-specific song title |
| `artist` | string | Project-specific artist name |
| `performed_at` | ISO 8601 UTC string | When the performance occurred |
| `source` | `"repertoire"` \| `"setlist"` | How the performance was logged |
| `source_name` | string \| null | The setlist name when `source` is `"setlist"`, otherwise `null` |
| `is_first_performance` | boolean | True when this is the earliest ever performance of this project-song |
| `was_requested` | boolean | True when a played `requests` row matches this session + song |
| `earned_cents` | integer | Net tip earnings in cents from the matching request (`stripe_net_amount_cents`), or 0 |

---

### GET `/api/v1/me/projects/{project}/song-performances`

Returns paginated performance sessions (most-recent first), each containing the
enriched performances that occurred in that session.

**Query parameters**

| Parameter | Default | Description |
|---|---|---|
| `page` | `1` | Page number |

**Response `200`**

```json
{
  "data": [
    {
      "session_id": 3,
      "started_at": "2026-04-08T20:00:00+00:00",
      "ended_at": "2026-04-08T23:30:00+00:00",
      "venue_name": "The Rusty Nail",
      "gig_type": "public",
      "performances": [
        {
          "id": 42,
          "project_song_id": 15,
          "performance_session_id": 3,
          "title": "Bohemian Rhapsody",
          "artist": "Queen",
          "performed_at": "2026-04-08T22:30:00+00:00",
          "source": "setlist",
          "source_name": "Friday Night Set",
          "is_first_performance": true,
          "was_requested": true,
          "earned_cents": 500
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 57
  },
  "links": {
    "first": "https://example.com/api/v1/me/projects/1/song-performances?page=1",
    "last": "https://example.com/api/v1/me/projects/1/song-performances?page=3",
    "prev": null,
    "next": "https://example.com/api/v1/me/projects/1/song-performances?page=2"
  }
}
```

**Session fields**

| Field | Type | Description |
|---|---|---|
| `session_id` | integer | `performance_sessions.id` |
| `started_at` | ISO 8601 UTC string \| null | When the session started |
| `ended_at` | ISO 8601 UTC string \| null | When the session ended, or null if still active |
| `venue_name` | string \| null | Venue name if a venue was assigned, otherwise null |
| `gig_type` | string \| null | Gig type enum value (e.g. `public`, `private`, `rehearsal`), or null |
| `performances` | array | Enriched performances for this session (same shape as the recent endpoint) |

## Notes

- `is_first_performance` is computed per `project_song_id` across the project's
  entire history. A song performed for the very first time gets `true` only on
  that earliest record.
- `earned_cents` uses `requests.stripe_net_amount_cents` (post-Stripe-fee net),
  not the gross tip amount. It is `0` when there was no matching paid request.
- Sessions with no logged `song_performances` rows are still included in the
  history index but have an empty `performances` array.
- Dates are always returned in UTC with a `+00:00` offset.
