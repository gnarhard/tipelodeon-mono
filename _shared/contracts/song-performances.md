# Song Performance History Contract

These endpoints give the performer visibility into what they have been playing,
independent of the timeline-filtered stats. They power the "Recent Performances"
card on the Home screen and the full "Performance History" screen.

## Authentication

All endpoints require a valid Sanctum bearer token. The authenticated user must
be the project owner or a project member. Non-members receive a `404`.

## Timezone handling

See [`timezone-and-time.md`](timezone-and-time.md) for the full contract.

- All `*_at` fields are ISO 8601 UTC strings with an explicit `+00:00` offset.
- Every history endpoint accepts an optional `timezone` query parameter (IANA
  zone name, e.g. `America/Denver`). When omitted, the server defaults to the
  project's `reporting_timezone`. This timezone is used to bucket date filters
  (`start_date` / `end_date`) via per-row `CONVERT_TZ`, never via a per-request
  fixed offset.
- Responses include `session.timezone` (IANA name) so the client can render
  timestamps in the zone the performance actually occurred in, using the
  precedence: session timezone → project reporting timezone → device timezone.

## Endpoints

### GET `/api/v1/me/projects/{project}/song-performances/recent`

Returns the last 10 song performances for the project, ordered most-recent first.

**Query parameters**

| Parameter | Type | Description |
|---|---|---|
| `start_date` | `YYYY-MM-DD` | Optional. Only include performances on or after this local date. |
| `end_date` | `YYYY-MM-DD` | Optional. Only include performances on or before this local date (inclusive through `23:59:59`). |
| `timezone` | IANA string | Optional. Timezone used to bucket `start_date` / `end_date`. Defaults to the project's `reporting_timezone`. |

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
| `timezone` | project's `reporting_timezone` | Optional IANA zone name used to bucket session `local_date` fields |

**Response `200`**

```json
{
  "data": [
    {
      "session_id": 3,
      "started_at": "2026-04-08T20:00:00+00:00",
      "ended_at": "2026-04-08T23:30:00+00:00",
      "timezone": "America/Denver",
      "location_name": "The Rusty Nail",
      "gig_type": "public",
      "duration_minutes": 210,
      "song_count": 14,
      "total_tips_cents": 4800,
      "events": [
        {
          "event_type": "song",
          "id": 42,
          "project_song_id": 15,
          "performance_session_id": 3,
          "title": "Bohemian Rhapsody",
          "artist": "Queen",
          "occurred_at": "2026-04-08T22:30:00+00:00",
          "source": "setlist",
          "source_name": "Friday Night Set",
          "is_first_performance": true,
          "was_requested": true,
          "tip_amount_cents": 500
        },
        {
          "event_type": "tip_only",
          "id": 7,
          "occurred_at": "2026-04-08T21:45:00+00:00",
          "tip_amount_cents": 300
        },
        {
          "event_type": "tip_bucket_total",
          "id": 3,
          "tip_bucket_total_id": 17,
          "occurred_at": "2026-04-08T21:00:00+00:00",
          "tip_amount_cents": 1000
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
| `timezone` | IANA string \| null | The IANA zone name recorded on the session, used for display precedence (session → reporting → device) |
| `location_id` | integer \| null | Location ID if a location was assigned, otherwise null |
| `location_name` | string \| null | Location name if a location was assigned, otherwise null |
| `gig_type` | string \| null | Gig type enum value (e.g. `public`, `private_event`, `open_mic`, `rehearsal`), or null |
| `mode` | string \| null | Performance mode (e.g. `manual`, `smart`, `free_play`), or null |
| `setlist_id` | integer \| null | ID of the associated setlist, or null for free-play sessions |
| `setlist_name` | string \| null | Name of the associated setlist, or null for free-play sessions |
| `duration_minutes` | integer \| null | Session duration in minutes; null when `started_at` or `ended_at` is unavailable |
| `song_count` | integer | Number of `song_performances` records logged in this session |
| `total_tips_cents` | integer | Combined digital + tip bucket totals earned during this session, in cents |
| `performer_notes` | string \| null | Free-form notes the performer wrote about the session. Editable on past sessions only via the same `PATCH …/performances/{id}` endpoint as other session edits. Max 2000 characters. Fed into the AI synopsis prompt when set. |
| `events` | array | All timeline events for this session, sorted most-recent first (see Event types below) |

**Event types**

Each event object has a discriminator field `event_type`. Shared fields present on every event:

| Field | Type | Notes |
|---|---|---|
| `event_type` | string | See event-type table below |
| `id` | integer | `performance_events.id` |
| `occurred_at` | ISO 8601 UTC string | When the event occurred |
| `tip_amount_cents` | integer | 0 when not applicable |
| `payment_provider` | `"stripe"` \| `"none"` \| `"awarded"` \| null | How the request was paid. `"stripe"` = digital card/wallet; `"none"` = manual/cash; `"awarded"` = free-request reward redemption; null = not a payment event. |
| `payment_method` | `"card"` \| `"apple_pay"` \| `"google_pay"` \| `"link"` \| `"cashapp"` \| `"cash"` \| null | Specific payment instrument. Derived from `requests.stripe_payment_method_type` for Stripe payments; `"cash"` for `payment_provider="none"`; null for `"awarded"` or non-payment events. |
| `request_kind` | `"repertoire"` \| `"custom"` \| null | Populated on `request` events only. `"repertoire"` = song from catalog; `"custom"` = performer-entered free-text title; null for all other event types. |
| `audience_ordinal` | integer \| null | Session-scoped 1..N ordinal for the audience member. Assigned by first-seen event per `audience_profile_id` ordered by `occurred_at` ASC then `id` ASC. All later events for the same profile share the same ordinal. Null when no `audience_profile_id` is present. |
| `note` | string \| null | Audience-provided note on `request` events; null otherwise. |

**Complete event-type reference**

| `event_type` | When emitted | Additional fields |
|---|---|---|
| `session_started` | Session was started | — |
| `session_ended` | Session was stopped | — |
| `song_queued` | Song was added to the queue (before played) | `project_song_id`, `title`, `artist`, `audience_profile_id`, `audience_name` |
| `song` | Song was performed — no matching audience request | `project_song_id`, `performance_session_id`, `title`, `artist`, `source`, `source_name`, `is_first_performance`, `was_requested` |
| `request` | Song was performed and matched a played audience request | Same fields as `song` above, plus non-zero `tip_amount_cents`, `audience_profile_id`, `audience_name` |
| `song_skipped` | Song was skipped in the queue | `project_song_id`, `title`, `artist` |
| `song_reordered` | Queue was reordered | — |
| `tip_only` | Audience tip not attached to a song (Tip Jar Support) | non-zero `tip_amount_cents`, `audience_profile_id`, `audience_name` |
| `original` | Original song request marked as played | non-zero `tip_amount_cents`, `audience_profile_id`, `audience_name` |
| `request_updated` | A manual queue item's tip amount was edited | `tip_amount_cents` (new), `previous_tip_amount_cents`, `audience_profile_id`, `audience_name` |
| `request_voided` | A manual queue item was removed | `tip_amount_cents` (original amount), `audience_profile_id`, `audience_name` |
| `tip_bucket_total` | Cash tip bucket total logged by the performer | `tip_bucket_total_id`, `tip_amount_cents` |
| `tip_bucket_total_updated` | Cash tip bucket total was edited | `tip_bucket_total_id`, `tip_amount_cents` (new value) |
| `tip_bucket_total_voided` | Cash tip bucket total was deleted | `tip_bucket_total_id`, `tip_amount_cents` (deleted amount) |
| `reward_claimed` | Audience crossed a reward threshold during the session window | `reward_label`, `reward_icon`, `reward_type`, `threshold_cents`, `audience_name` |
| `reward_delivered` | Performer confirmed a reward was physically delivered | `reward_label`, `reward_icon`, `reward_type`, `threshold_cents`, `audience_name` |
| `reward_used` | Audience redeemed a free_request reward for a specific song | `reward_label`, `reward_icon`, `reward_type`, `project_song_id`, `title`, `artist`, `audience_profile_id`, `audience_name`, `audience_ordinal` |
| `link_clicked` | Audience member clicked a link on the project page | `link_type`, `audience_profile_id`, `audience_name` |
| `audience_page_viewed` | Audience member opened the project page | `audience_profile_id`, `audience_name` |
| `new_audience_member_viewed` | First time a given visitor opened the project page during an active session (idempotent per visitor token per session) | `visitor_token`, `audience_profile_id`, `audience_name` |

**Field details for song / request events**

| Field | Type | Description |
|---|---|---|
| `project_song_id` | integer | The project-song that was performed or queued |
| `performance_session_id` | integer \| null | The session this performance belongs to |
| `title` | string \| null | Project-specific song title |
| `artist` | string \| null | Project-specific artist name |
| `source` | `"repertoire"` \| `"setlist"` \| null | How the performance was logged |
| `source_name` | string \| null | The setlist name when `source` is `"setlist"`, otherwise null |
| `is_first_performance` | boolean | True when this is the earliest ever performance of this project-song |
| `was_requested` | boolean | True when a played request matched this session and song |

**Field details for reward events (`reward_claimed` and `reward_delivered`)**

| Field | Type | Description |
|---|---|---|
| `reward_label` | string | Performer-defined label (e.g. "Free Song Request") |
| `reward_icon` | string \| null | Curated icon code (e.g. `music_note`, `star`, `album`) |
| `reward_type` | string | One of `free_request`, `free_cd`, `custom` |
| `threshold_cents` | integer | Cumulative-tip threshold in cents at which the reward unlocks |
| `audience_name` | string \| null | Audience member's display name, if known |

**`reward_claimed` vs `reward_delivered`**: `reward_claimed` fires when the audience crosses the threshold (money side). `reward_delivered` fires when the performer taps "Mark as delivered" in the app (physical delivery confirmation). Both events are matched to the session by project + time window, not by `performance_session_id`, so they appear on any session whose time window contains the claim.

**`tip_bucket_total_id`**: Use this FK — not `id` (which is `performance_events.id`) — when calling `/tip-bucket-totals/{id}` endpoints. Null only on legacy rows that pre-date the link.

**`request_updated.previous_tip_amount_cents`**: The tip amount before the edit. Always present; 0 if the original was also 0.

**`link_clicked.link_type`**: One of `venmo`, `paypal`, `cashapp`, `performer_track` (or any custom link type the performer has configured).

**Ordering**: all events within `data.events` are sorted most-recent first by `occurred_at`.

**`request` event lifecycle**: When an audience member places a request, two events appear simultaneously: a raw `request` event (the "placed" moment, carrying `payment_provider`, `payment_method`, `request_kind`, `note`) and a `song_queued` event. When the performer plays the song, a `song` or `request` (promoted) event appears. The placed `request` event is distinguished from the performed one by the presence of `payment_provider` (placed) vs `was_requested: true` (performed).

**`reward_used` vs `reward_claimed`**: `reward_claimed` fires when the audience crosses the tip threshold (money side). `reward_used` fires when the audience redeems that reward for a specific song (request side). Both can appear in the same session for the same audience member.

### GET `/api/v1/me/projects/{project}/performances/{performanceSession}/events`

Returns the full enriched timeline for a single performance session, including
all session metadata and all events.

**Response `200`**

The top-level `data` object includes all fields from the paginated list
endpoint's session object plus the following additional fields:

| Field | Type | Description |
|---|---|---|
| `setlist_id` | integer \| null | ID of the associated setlist, or null for free-play sessions |
| `setlist_set_count` | integer \| null | Number of sets defined in the associated setlist, or null for free-play sessions |
| `latitude` | float \| null | GPS latitude stored on the session, or null |
| `longitude` | float \| null | GPS longitude stored on the session, or null |
| `audience_conversion` | object | Audience page-view to conversion stats: `{total, converted, rate}` |
| `ai_synopsis` | string \| null | AI-generated session summary, or null if not yet generated |
| `ai_synopsis_generated_at` | ISO 8601 UTC string \| null | When the synopsis was generated, or null |
| `performer_notes` | string \| null | Free-form performer notes for the session |
| `events` | array | All timeline events, sorted most-recent first (same shape as the paginated list) |

### POST `/api/v1/me/projects/{project}/performances/{performanceSession}/synopsis`

Queues a background job to (re)generate the session's `ai_synopsis` via the
configured Anthropic model. The server does **not** auto-generate synopses —
the performer must explicitly request one.

The synopsis is returned as lightweight markdown: 3–4 short lines separated by
newlines, each leading with a `**bold label**` followed by a brief fact. No
bullets, no headers, no other markdown. Clients should render `**…**` as bold
and preserve line breaks.

The prompt explicitly warns the model that `tip_bucket_total` events in the
timeline are logged **after** the performance when the performer counts their
tip bucket (see the "Tip bucket total timing" note in
`tip-bucket-totals.md`), so `t_offset_seconds` for a tip-bucket-total event
reflects the tally moment, not when individual cash tips arrived during the
set. The model is told not to narrate tip bucket totals as moment-in-time
events.

- Returns **202 Accepted** with `{"data":{"session_id":…,"status":"queued"}}`.
- Overwrites any existing synopsis on success.
- **422** if the session has not ended (`ended_at` is null).
- **503** if the server has no Anthropic API key configured.
- **404** if the session does not belong to the authenticated user's project.

Clients should poll `GET …/performances/{performanceSession}/events` and
detect completion by observing a new `ai_synopsis_generated_at` timestamp on
the session payload.

## UI label ↔ wire name mapping

| Wire `event_type` | Condition | UI label |
|---|---|---|
| `session_started` | — | "Session Started" |
| `session_ended` | — | "Session Ended" |
| `audience_page_viewed` | — | "View: Project Page" |
| `new_audience_member_viewed` | — | "View: Project Page" |
| `tip_only` | — | "Tip: $X.XX" |
| `request` | `payment_provider=stripe`, `request_kind=repertoire` | "Digital Request: Title — Artist" |
| `request` | `payment_provider=none`, `request_kind=repertoire` | "Manual Request: Title — Artist" |
| `request` | `payment_provider=awarded`, `request_kind=repertoire` | "Manual Request: Title — Artist" |
| `request` | `payment_provider=none`, `request_kind=custom` | "Manual Custom Request: "{note}"" |
| `original` | `payment_provider=stripe` | "Digital Request: Original" |
| `original` | `payment_provider=none` | "Manual Request: Original" |
| `song_queued` | — | "Queued: Title — Artist" |
| `song` | — | "Performed: Title — Artist" |
| `request` | `was_requested=true` (promoted on play) | "Performed: Title — Artist" |
| `link_clicked` | — | "Link Click: {linkType}" |
| `reward_claimed` | — | "Reward Earned: {rewardLabel}" |
| `reward_delivered` | — | "Reward Claimed: {rewardLabel}" |
| `reward_used` | — | "Reward Claimed: Title — Artist" |
| `tip_bucket_total` | — | "Counted Cash Tip Bucket Total" |

**Invariant — audience ordinal**: Within a session, each distinct non-null `audience_profile_id` is assigned ordinal `1..N` based on the earliest `occurred_at` (tie-broken by ascending `performance_events.id`) of any event that references it. All later events referencing the same profile receive the same ordinal.

## Notes

- `is_first_performance` is computed per `project_song_id` across the project's
  entire history. A song performed for the very first time gets `true` only on
  that earliest record.
- `earned_cents` uses `requests.stripe_net_amount_cents` (post-Stripe-fee net),
  not the gross tip amount. It is `0` when there was no matching paid request.
- Sessions with no logged `song_performances` rows are still included in the
  history index but have an empty `performances` array.
- Dates are always returned in UTC with a `+00:00` offset.
