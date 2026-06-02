# Public Audience API Contracts (v1.2)

## Scope

- No bearer auth.
- Audience identity is cookie-backed (`tipelodeon_audience_token`).
- Audience route prefix: `/api/v1/public/projects/{project_slug}`.
- App bootstrap route exception: `GET /api/v1/app/version-policy?platform={platform}`.

---

## App version policy

- `GET /api/v1/app/version-policy?platform={platform}`
- Public startup endpoint for installed `ios`, `android`, `macos`, `windows`, and `linux` builds.
- Returns `200 { data: ... }` only when an enabled release policy exists for the requested platform.
- Returns `404` when the platform has no enabled release policy.

Comparison rules:
- Compare `latest_version` numerically as `x.y.z`.
- If the semantic version matches the installed app, compare `latest_build_number`.

Platform URL fields:
- `store_url` is used only for mobile platforms (`ios`, `android`) and should be null for desktop policies.
- `archive_url` is used only for desktop platforms (`macos`, `windows`, `linux`) and should be null for mobile policies.

Release pipeline updates:
- `PATCH /api/v1/app/version-policy/{platform}` is an internal endpoint
  used by `app/scripts/release.sh` after each successful upload stage.
- Auth: `Authorization: Bearer <RELEASE_POLICY_TOKEN>`. The endpoint
  returns `401` when the env var is unset on the target environment, so
  non-production deployments stay locked down by default.
- Body is a partial update: any of `latest_version`,
  `latest_build_number`, `store_url`, `archive_url`, `is_enabled`.
  Omitted fields keep their prior values. Mobile platforms reject
  `archive_url`; desktop platforms reject `store_url`.
- When no row exists yet for the platform, both `latest_version` and
  `latest_build_number` are required and the row is created with
  `is_enabled=false` unless explicitly set. Operators flip `is_enabled`
  manually from the admin panel once the new build is live in the
  store / on the download host.

---

## Public repertoire

- `GET /repertoire`
- Supports search/sort and metadata filters including `theme`.
- Songs with `is_public=false` are excluded from the public repertoire (default mode).
- When the project has `public_repertoire_setlist_id` set, the public repertoire shows the union of all songs across every set of that setlist, ignoring `is_public` flags and `version_label` filters. Takes precedence over `public_repertoire_set_id`.
- When the project has `public_repertoire_set_id` set (and no `public_repertoire_setlist_id`), the public repertoire shows only songs from that set, ignoring `is_public` flags and `version_label` filters.
- Each repertoire item includes boolean `instrumental` and boolean `original`.
- Audience UIs append ` (instrumental)` to the displayed title when
  `instrumental=true` and ` (original)` when `original=true`.
- The repertoire-item `original` flag is distinct from the
  `is_original` field on `POST /requests` (which flags an audience request
  for an original song).
- `theme` is strict enum:
  `love`, `party`, `worship`, `story`, `st_patricks`, `christmas`, `halloween`, `patriotic`.
- Invalid `theme` filter values return `422`.
- Repertoire `theme` fields in responses are always canonical enum values.

---

## Create request

- `POST /requests`
- Supports `Idempotency-Key`.
- Public request creation is available on every project that has finished
  payout setup and accepts requests.

Request body fields:
- `song_id` (optional from client when `tip_only` / `is_original` flows are used)
- `tip_only` (bool)
- `is_original` (bool)
- `tip_amount_cents` (int)
- `note` (nullable)

Response fields:
- `request_id` (nullable int)
- `client_secret` (nullable string)
- `payment_intent_id` (nullable string)
- `requires_payment` (bool)
- `stripe_account_id` (nullable string, present when `requires_payment=true`)
- `service_charge_cents` (nullable int, present when `requires_payment=true`)
- `total_charge_cents` (nullable int, present when `requires_payment=true`)

Paid request intent behavior:
- PaymentIntents are created as Connect direct charges on the performer's
  connected account.
- `stripe_account_id` is returned so web Stripe.js can initialize the
  connected-account payment context correctly.

Service charge:
- Audience members pay a flat `$2.00` platform fee on top of every paid
  request. `service_charge_cents` is always `200` for paid requests and
  `total_charge_cents` is `tip_amount_cents + 200`.
- The performer always receives the full `tip_amount_cents`. The platform
  collects the platform fee net of Stripe's processing fee (passed as
  `application_fee_amount` on the Connect direct charge), and absorbs the
  Stripe processing fee shortfall on tips large enough that 2.9% + 30¢
  exceeds $2.

Canonical persistence rule:
- `requests.song_id` is always populated.
- Tip-only requests map to placeholder song:
  - `title = "Tip Jar Support"`
  - `artist = "Audience"`
- Original requests map to placeholder song:
  - `title = "Original Request"`
  - `artist = "Audience"`

Tips-disabled behavior:
- When project setting `is_accepting_tips=false`, client must send
  `tip_amount_cents=0`.
- Tip-only submissions are rejected while tips are disabled.
- `min_tip_cents` is ignored while tips are disabled.
- Tip and minimum-tip values with cents are rounded up to the next whole dollar
  before validation and persistence.

Payout setup gate:
- If owner payout setup is incomplete, request creation returns `422`:

```json
{
  "code": "payout_setup_incomplete",
  "message": "This project is not currently accepting requests."
}
```

- Payout setup is only required when project `is_accepting_tips=true`.
- If project requests are disabled independently, API returns `422` with
  message only (no `code` field).
- If project tips are disabled independently, positive-tip and tip-only
  submissions return `422` with message only (no `code` field).

Blocked-audience gate:
- If the resolved audience profile has been blocked by the project owner
  (see `queue.md` → "Block an Audience Member"), request creation returns
  `422`:

  ```json
  {
    "code": "audience_blocked",
    "message": "This project isn't accepting requests right now."
  }
  ```

- This check runs **before** any Stripe PaymentIntent is created, so a
  blocked member is never charged. The message is intentionally soft and
  does not reveal the block.
- The block key is the per-project `audience_profile_id`, never the
  requester name. Requests with a null `audience_profile_id`
  (cash/manual/anonymous/legacy) are never blocked.
- Charges already made before a block stand — block never refunds
  (credit-at-request-time, `.agent-rules/15-patent-constraints.md`). The
  backstop path: if a charge already succeeded, the request is still
  recorded but is hidden by the read-side queue/timeline filter and the
  performer is not notified.

Cooldown and repeat-lock gates:
- These checks fire only for song requests; tip-only submissions bypass them.
- When `repeats_enabled=false` and the requested song already has a
  `SongPerformance` row in the project's currently active session, the API
  returns `422`:

  ```json
  {
    "code": "song_already_performed_this_session",
    "message": "This song has already been performed in the current set."
  }
  ```

- When `cooldowns_enabled=true` and the song has a `SongPerformance` row in
  the project's currently active `PerformanceSession` whose `performed_at +
  cooldown_minutes > now()`, the audience must tip at least
  `cooldown_bust_amount_cents` to bypass the cooldown. If the tip is below
  that floor the API returns `422`:

  ```json
  {
    "code": "song_on_cooldown",
    "message": "This song is on cooldown. Tip $30 or more to bust the cooldown.",
    "cooldown_bust_amount_cents": 3000,
    "cooldown_ends_at": "2026-05-10T22:30:00+00:00"
  }
  ```

  Cooldowns are session-scoped: only performances in the currently active
  `PerformanceSession` count. When a new session is started (performer or
  `audience_auto`), all songs are off cooldown regardless of when they were
  played in any prior session. If no session is active at request time the
  audience-initiated flow auto-starts an `audience_auto` session, which is
  empty by definition, so no song can be on cooldown in it.
  `cooldown_ends_at` is the `performed_at + cooldown_minutes` of the
  most recent `SongPerformance` row for the song in the active session.

  The bust amount **replaces** `min_tip_cents` for that request; audience may
  tip more. Payment fires at request time exactly like a normal request — the
  cooldown is request-time pricing keyed off past performance state, never a
  performance-conditioned payout. See `.agent-rules/15-patent-constraints.md`.

Audience reward thresholds:
- Projects can define multiple reward thresholds via `reward_thresholds`
  (see projects.md). Each threshold has a `threshold_cents`, `reward_type`,
  `reward_label`, `reward_icon` (nullable curated code), `reward_icon_emoji`
  (read-only emoji mapped from `reward_icon`), `reward_description`
  (nullable, max 500 chars), and `is_repeating` flag.
- `reward_icon` must be one of the curated codes: `music_note`,
  `card_giftcard`, `star`, `favorite`, `local_bar`, `local_cafe`, `mic`,
  `celebration`, `emoji_events`, `album`, `checkroom`, `headphones`. Mobile
  and web audience UIs render the same curated set.
- Every newly created project automatically receives a default repeating
  `free_request` threshold at $30 (`threshold_cents=3000`,
  `reward_label="Free Song"`, `reward_icon="music_note"`). Owners
  can edit or delete it.
- Each paid tip increments the audience member's `cumulative_tip_cents` on
  their `audience_profile`. This value only grows; it never resets.
- Claims are tracked in `audience_reward_claims` (one row per claim).
- **Repeating thresholds**: the audience earns the reward at every multiple
  of `threshold_cents`. Available claims = `floor(cumulative / threshold) -
  claims_made`.
- **Non-repeating thresholds**: earned once when cumulative >= threshold.
- **`free_request` type**: auto-claimable. The request page shows progress
  ("You're $X away from: Free Song!") and a claim button when earned.
  Free requests are submitted with `payment_provider=awarded` and
  `tip_amount_cents=0`, bypassing tip and minimum-tip requirements.
  Tip-only submissions cannot use a free request credit.
- **Other reward types** (e.g. `free_cd`, `custom`): informational only.
  The project page shows "You've earned: {reward_label}! Approach the
  musician to receive your reward." The performer fulfills manually.
- When a tip crosses a threshold, the performer receives an email
  notification identifying the reward and the audience member.
- When a project has no reward thresholds, no reward features are shown.
- Backward compat: `free_request_threshold_cents` on the project payload
  is deprecated but still present. A single repeating `free_request`
  threshold is equivalent to the old behavior.

---

## Performer link tracking

- `GET /project/{projectSlug}/website` — redirects to `performer_info_url`
  and records a `website` click in `performer_link_clicks`.
- `GET /project/{projectSlug}/track-performer` — redirects to
  `performer_track_url` and records a `follow` click in
  `performance_events`.
- Both routes fall back to the project page if the corresponding URL is unset.
- Clicks are recorded with `occurred_at` in UTC and the audience
  `visitor_token` (cookie-based).
- Click counts are surfaced in the owner-facing stats report under
  `link_clicks.website` and `link_clicks.follow`, scoped to
  the selected timeline window.
