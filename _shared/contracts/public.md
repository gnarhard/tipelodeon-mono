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
