# Public Audience API Contracts (v1.2)

## Scope

- No bearer auth.
- Audience identity is cookie-backed (`songtipper_audience_token`).
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

---

## Public repertoire

- `GET /repertoire`
- Supports search/sort and metadata filters including `theme`.
- Songs with `is_public=false` are excluded from the public repertoire (default mode).
- When the project has `public_repertoire_set_id` set, the public repertoire shows only songs from that set, ignoring `is_public` flags and `version_label` filters.
- Each repertoire item includes boolean `instrumental`.
- Audience UIs append ` (instrumental)` to the displayed title when
  `instrumental=true`.
- `theme` is strict enum:
  `love`, `party`, `worship`, `story`, `st_patricks`, `christmas`, `halloween`, `patriotic`.
- Invalid `theme` filter values return `422`.
- Repertoire `theme` fields in responses are always canonical enum values.

---

## Create request

- `POST /requests`
- Supports `Idempotency-Key`.
- Public request creation is available only when the owning project exposes
  `entitlements.can_use_public_requests=true` (Pro-owned projects).

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

Paid request intent behavior:
- PaymentIntents are created as Connect direct charges on the performer's
  connected account.
- `stripe_account_id` is returned so web Stripe.js can initialize the
  connected-account payment context correctly.

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
- If the owner plan does not include public requests, API also returns `422`
  with message only:

```json
{
  "message": "This project is not currently accepting requests."
}
```

- If project tips are disabled independently, positive-tip and tip-only
  submissions return `422` with message only (no `code` field).

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
  `free_request` threshold at $40 (`threshold_cents=4000`,
  `reward_label="Free Song Request"`, `reward_icon="music_note"`). Owners
  can edit or delete it.
- Each paid tip increments the audience member's `cumulative_tip_cents` on
  their `audience_profile`. This value only grows; it never resets.
- Claims are tracked in `audience_reward_claims` (one row per claim).
- **Repeating thresholds**: the audience earns the reward at every multiple
  of `threshold_cents`. Available claims = `floor(cumulative / threshold) -
  claims_made`.
- **Non-repeating thresholds**: earned once when cumulative >= threshold.
- **`free_request` type**: auto-claimable. The request page shows progress
  ("You're $X away from: Free Song Request!") and a claim button when earned.
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

- `GET /project/{projectSlug}/learn-more` — redirects to `performer_info_url`
  and records a `learn_more` click in `performer_link_clicks`.
- `GET /project/{projectSlug}/track-performer` — redirects to
  `performer_track_url` and records a `track_performer` click in
  `performer_link_clicks`.
- Both routes fall back to the project page if the corresponding URL is unset.
- Clicks are recorded with `clicked_at` in UTC and the audience
  `visitor_token` (cookie-based).
- Click counts are surfaced in the owner-facing stats report under
  `link_clicks.learn_more` and `link_clicks.track_performer`, scoped to
  the selected timeline window.
