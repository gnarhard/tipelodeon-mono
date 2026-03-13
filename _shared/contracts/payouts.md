# Payouts API Contracts (v1.2)

## Scope and auth

- Protected routes require `Authorization: Bearer <token>`.
- Write endpoints support `Idempotency-Key`.
- Route prefix: `/api/v1/me/payout-account`.

---

## Endpoints

- `GET /api/v1/me/payout-account`
- `POST /api/v1/me/payout-account/onboarding-link`
- `POST /api/v1/me/payout-account/dashboard-link`
- `GET /api/v1/me/projects/{projectId}/wallet`
- `GET /api/v1/me/projects/{projectId}/stats`
- `GET /api/v1/me/projects/{projectId}/wallet/sessions`
- `GET /api/v1/me/payouts`

Pro plan gates:
- `wallet`, `stats`, `wallet/sessions`, and `payouts` require Pro.
- Project-scoped wallet/stat endpoints also require project ownership.
- When Pro is required and unavailable, API returns `403` with
  `code=feature_requires_pro`.

---

## Payout account status model

`payout_account` payload:
- `status` (`not_started|pending|enabled|restricted`)
- `setup_complete` (boolean)
- `status_reason` (nullable string machine code / Stripe reason)
- `stripe_account_id` (nullable string)
- `charges_enabled` (boolean)
- `payouts_enabled` (boolean)
- `requirements_currently_due` (array of strings)
- `requirements_past_due` (array of strings)

Status semantics:
- `setup_complete=true` only when `status=enabled`.
- `return_url` from Stripe onboarding is not a completion signal.
- Completion is derived from Stripe account status
  (`requirements`, `charges_enabled`, `payouts_enabled`), synchronized from
  Stripe API and `account.updated` webhooks.

---

## Onboarding link endpoint

`POST /onboarding-link`:
- Ensures an Express connected account exists for the current user.
- Returns single-use onboarding `url` and latest `payout_account` snapshot.

Example success:

```json
{
  "url": "https://connect.stripe.com/setup/...",
  "payout_account": {
    "status": "pending",
    "setup_complete": false,
    "status_reason": "requirements_due",
    "stripe_account_id": "acct_123",
    "charges_enabled": false,
    "payouts_enabled": false,
    "requirements_currently_due": ["external_account"],
    "requirements_past_due": []
  }
}
```

---

## Dashboard link endpoint

`POST /dashboard-link`:
- Always ensures an Express connected account exists for the current user.
- Returns `url`, `link_type`, and current `payout_account`.
- `link_type` behavior:
  - `dashboard`: payout status is fully enabled and URL is Stripe Express dashboard login.
  - `onboarding`: payout status is not enabled and URL is onboarding flow.

```json
{
  "url": "https://connect.stripe.com/...",
  "link_type": "onboarding",
  "payout_account": {
    "status": "pending",
    "setup_complete": false,
    "status_reason": "requirements_due",
    "stripe_account_id": "acct_123",
    "charges_enabled": false,
    "payouts_enabled": false,
    "requirements_currently_due": ["external_account"],
    "requirements_past_due": []
  }
}
```

---

## Wallet summary endpoint

`GET /api/v1/me/projects/{projectId}/wallet`:
- Pro-only owner endpoint (`403` for non-owners and Basic-owned projects).
- Returns:
  - account-level Stripe balance (`available`, `pending`, USD totals),
  - project-level earnings aggregates from SongTipper request/session data,
  - current payout account status snapshot.

Semantics:
- Wallet scope is `account_level` because one performer has one Stripe account.
- Project earnings are reporting views; Stripe balance is source of truth for
  cashout availability.

---

## Project stats endpoint

`GET /api/v1/me/projects/{projectId}/stats`:
- Pro-only owner endpoint.
- Required query params:
  - `timezone` as an IANA identifier, for example `America/Denver`
  - `preset` as one of:
    `today|yesterday|this_week|last_week|this_month|last_month|this_year|last_year|all_time|custom`
- Required only for `preset=custom`:
  - `start_date`
  - `end_date`
- Returns a single report object with:
  - `period.preset`, `period.timezone`, `period.local_start_date`,
    `period.local_end_date`, `window_start_utc`, `window_end_utc`,
    `generated_at`
  - `money.gross_tip_amount_cents`, `money.fee_amount_cents`,
    `money.net_tip_amount_cents`
  - `counts.request_count`, `counts.played_count`
  - nullable `highlights.most_requested` and `highlights.highest_earning`
  - `rankings.most_played`, `rankings.most_requested`,
    `rankings.highest_earning`

Semantics:
- Week presets use Monday-start local weeks.
- `custom` uses inclusive local calendar dates in the supplied timezone.
- `all_time` spans from project creation through report generation time.
- Money and request counts use the accepted request timestamp.
- Played counts use `played_at`.
- Song rankings only consider repertoire-linked songs in the current project.
- Tip-only placeholders, original-request placeholders, and custom manual titles
  are excluded from the top-song cards.
- Stripe-backed requests use persisted Stripe fee/net settlement values.
- If same-day Stripe settlement data is still missing, backend may hydrate it
  from Stripe on demand; if it cannot be resolved, endpoint returns `502`.

---

## Session earnings endpoint

`GET /api/v1/me/projects/{projectId}/wallet/sessions`:
- Pro-only owner endpoint.
- Paginated performance session aggregates:
  - `paid_request_count`
  - `total_tip_amount_cents`
  - session timestamps/status

---

## Payout history endpoint

`GET /api/v1/me/payouts`:
- Pro-only endpoint.
- Returns connected-account payout objects from Stripe.
- Query params:
  - `limit` (1..100, default 20)
  - `status` (`pending|in_transit|paid|failed|canceled`)
- If no payout account exists yet, returns empty `data` with
  `payout_account.status=not_started`.
