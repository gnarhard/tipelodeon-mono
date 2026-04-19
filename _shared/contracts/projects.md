# Projects API Contracts (v1.2)

## Scope and auth

- Protected routes require `Authorization: Bearer <token>`.
- Write endpoints support `Idempotency-Key`.
- Route prefix: `/api/v1/me/projects`.

---

## Project endpoints

- `GET /` - list accessible projects
- `POST /` - create project
- `PUT /{projectId}` - update project settings
- `PATCH /{projectId}` - partial settings update
- `DELETE /{projectId}` - owner-only delete
- `POST /{projectId}/performer-image` - upload performer profile image
- `GET /{projectId}/members` - list owner and invited members
- `POST /{projectId}/members` - owner-only invite of an existing SongTipper user
- `DELETE /{projectId}/members/{membershipId}` - owner-only removal of a project member

---

## Project members

- Owners can invite another existing SongTipper user by email.
- Invite payload: `{ "email": "member@example.com" }`
- Invites always create or promote the collaborator to the `member` role.
- Re-inviting an existing `member` returns the existing membership instead of
  creating a duplicate row.
- `GET /{projectId}/members` is readable by the project owner and invited
  project members.
- Member list payload returns:
  - `owner` with `id`, `name`, `email`, and `role=owner`
  - `members[]` with `id`, `user_id`, `role`, `joined_at`, and nested `user`
    details
- Inviting the project owner's own email returns `422`.
- Owners can remove a member via `DELETE /{projectId}/members/{membershipId}`.
- Removing a member deletes the `project_members` row.
- Returns `{ "message": "Project member removed successfully." }` on success.
- Non-owners receive `403`. Membership IDs from other projects return `404`.

---

## Payout status refresh endpoint

- `GET /api/v1/me/payout-account?refresh_from_stripe=1` refreshes payout account
  status directly from Stripe before returning the payload.
- Mobile settings should call this endpoint before reading request availability
  when onboarding completion needs immediate confirmation.

---

## Project payload fields

Core fields:
- `id`
- `name`
- `slug`
- `owner_user_id`
- `performer_info_url` (nullable)
- `performer_track_url` (nullable; URL used as the "Track Performer" link on the public project page)
- `performer_profile_image_url` (nullable)
- `min_tip_cents` (rounded up to a whole-dollar cent value on write)
- `queue_nudge_cents` (additional cents required to claim #1 queue position; rounded up to whole dollar on write; minimum 100; default 500)
- `free_request_threshold_cents` (cumulative tip threshold for earning a free request; 0 = disabled; default 4000; rounded up to whole dollar on write; deprecated — use `reward_thresholds` instead)
- `reward_thresholds[]` (array of reward threshold objects owned by this project; see below)
- `quick_tip_amounts_cents` (exactly 3 whole-dollar cent values in descending display order)
- `is_accepting_requests`
- `is_accepting_tips`
- `is_accepting_original_requests`
- `notify_on_request` (default true; when true, owner receives email on new requests/tips)
- `show_persistent_queue_strip`
- `public_repertoire_set_id` (nullable int, FK to `setlist_sets.id`; when set, the public project page shows only songs from this set instead of the full `is_public` repertoire; `nullOnDelete` auto-resets when the set is deleted)
- `public_repertoire_set_name` (read-only, set name when `public_repertoire_set_id` is loaded)
- `public_repertoire_setlist_name` (read-only, parent setlist name when `public_repertoire_set_id` is loaded)
- `min_suggested_setlist_songs` (int, default 5, range 1..100; minimum number of songs an audience member must pick when suggesting a setlist via `/project/{slug}/suggest-setlist`)
- `max_suggested_setlist_songs` (int, default 25, range 1..100; maximum number of songs; must be >= `min_suggested_setlist_songs`)
- `chart_viewport_prefs` (deprecated, nullable object)

Payout readiness fields:
- `payout_setup_complete` (boolean)
- `payout_account_status` (`not_started|pending|enabled|restricted`)
- `payout_status_reason` (nullable machine code/string for UI messaging)

Entitlements fields:
- `entitlements.plan_code`
- `entitlements.plan_tier` (`free|pro|veteran`)
- `entitlements.repertoire_song_limit` (`20` for Free, `200` for Pro, `null` for Veteran)
- `entitlements.project_limit` (`1` for Free, `3` for Pro, `null` for Veteran)
- `entitlements.single_chart_upload_limit_bytes` (`2097152`)
- `entitlements.bulk_chart_upload_limit_bytes` (`2097152`)
- `entitlements.bulk_chart_file_limit` (`20`)
- `entitlements.ai_interactive_per_minute` (`10` Free/Pro, `30` Veteran)
- `entitlements.bulk_ai_window_limit` (`500`)
- `entitlements.bulk_ai_window_hours` (`6`)
- `entitlements.can_use_public_requests` (Veteran-only)
- `entitlements.can_access_queue` (Veteran-only)
- `entitlements.can_access_history` (Veteran-only)
- `entitlements.can_view_owner_stats` (Veteran-only)
- `entitlements.can_view_wallet` (Veteran-only)
- `entitlements.can_invite_members` (Veteran-only — band sync)

Project creation limit:
- When a user's owned project count reaches `entitlements.project_limit`, `POST /`
  returns `422`:

```json
{
  "code": "project_limit_reached",
  "message": "Your plan allows up to 1 project(s). Upgrade for more.",
  "project_limit": 1
}
```

Downgrade behavior:
- When downgrading from a higher tier, existing projects and songs are never
  deleted. Users retain read-only access to all existing content.
- New project creation and song addition are blocked when the user exceeds the
  new tier's limits.
- Tipping and requests are disabled immediately on all owned projects when
  downgrading from Pro.

---

## Payout gate behavior for requests and tips

When updating a project:
- If the owner plan is Pro and the update would newly enable public requests
  or tips, API returns `422`:

```json
{
  "code": "feature_requires_pro",
  "message": "Audience requests and tips require Pro."
}
```

- If the resulting project state would have both `"is_accepting_requests": true`
  and `"is_accepting_tips": true` while owner payout setup is not complete, API
  returns `422`:

```json
{
  "code": "payout_setup_incomplete",
  "message": "Finish payout setup before enabling requests."
}
```

- If `"is_accepting_tips": false`, requests may stay open without payout setup.
- `payout_setup_complete` is true only when owner payout account status is
  `enabled`.
- When payout status transitions from non-`enabled` to `enabled`, backend
  automatically flips owned projects with `is_accepting_tips=true` to
  `is_accepting_requests=true` so performers can immediately take requests after
  onboarding completion.
- When payout status regresses (for example from Stripe `account.updated`), web
  backend can force `is_accepting_requests` to `false` for owned projects that
  still have `is_accepting_tips=true`.

---

## Notification gate (invisible requests)

When a project is accepting requests with no active notification channel, the
owner has no passive way to notice incoming requests — neither email nor the
persistent queue strip is visible. Disabling both can be intentional (a sideman
at another leader's gig, a soloist with requests temporarily paused), so this
is a confirmation gate rather than a hard block.

### Rule

If the **resulting** project state would have all three of:

- `is_accepting_requests = true`
- `notify_on_request = false`
- `show_persistent_queue_strip = false`

…and the payload does not include `acknowledge_invisible_requests = true`, the
API returns `422`:

```json
{
  "code": "notification_channel_required",
  "message": "You are about to disable all request notifications. Confirm to proceed.",
  "details": { "required_field": "acknowledge_invisible_requests" }
}
```

On retry with `acknowledge_invisible_requests: true`, the update succeeds. The
flag is ephemeral per-request and is never persisted.

### Transition semantics (required)

The gate fires only on transitions *into* the dangerous state. Evaluation:

1. Compute the resulting state by merging the incoming payload over existing
   project values (this matters for `PATCH`).
2. If the resulting state is not `requests=on, email=off, strip=off`, pass.
3. If the resulting state is the dangerous one, check whether the *incoming
   payload* flips at least one of `is_accepting_requests`,
   `notify_on_request`, or `show_persistent_queue_strip` toward that state
   (i.e. `is_accepting_requests` to `true`, or either notification field to
   `false`). If no relevant field is toggled, pass — the performer is already
   in the state and is editing something unrelated (e.g. `name`).
4. Otherwise, require `acknowledge_invisible_requests=true` or return 422.

This prevents repeat-confirmation regressions when performers already in the
state save unrelated settings.

### Scope

The gate applies to `PUT /{projectId}` and `PATCH /{projectId}`. Create
(`POST /`) is not gated — new projects inherit safe defaults
(`notify_on_request=true`, `show_persistent_queue_strip=true`), and the
creation payload does not accept these fields as overrides.

### Client expectations

- On `422` with `code=notification_channel_required`, surface a confirmation
  dialog explaining the consequence (incoming requests will be invisible
  unless the performer manually opens the queue screen) and retry the original
  payload with `acknowledge_invisible_requests: true`.
- Do not persist the flag locally or re-send it on unrelated subsequent
  updates — the backend re-evaluates transition semantics each request.

---

## Wallet endpoints related to projects

- `GET /api/v1/me/projects/{projectId}/wallet`
- `GET /api/v1/me/projects/{projectId}/stats`
- `GET /api/v1/me/projects/{projectId}/wallet/sessions`

All three endpoints are Pro-only owner reporting views for that project.

- `/wallet` and `/wallet/sessions` expose payout/wallet reporting while sharing
  one account-level Stripe wallet across all owner projects.
- `/stats` returns the owner-facing timeline stats report scoped by:
  - required `timezone` query param (IANA identifier)
  - required `preset` query param:
    `today|yesterday|this_week|last_week|this_month|last_month|this_year|last_year|all_time|custom`
  - `start_date` and `end_date` only when `preset=custom`

Timeline semantics:
- Week presets use Monday-start local weeks.
- Relative presets resolve in the supplied reporting timezone.
- `custom` dates are inclusive local calendar dates in the supplied timezone.
- `all_time` spans from project `created_at` through report generation time.

Stats response fields:
- `period`, `money`, `counts`, `highlights`, `rankings`, and `rewards_gifted`
  are always present.
- `rankings.most_played`, `rankings.most_requested`, and `rankings.highest_earning`
  each contain up to 10 entries for the selected period.
- `records.best_day` is nullable and, when present, includes:
  - `gross_tip_amount_cents`
  - `local_date`
  - `timezone`
  - `is_current_period_record`
- `records.best_day` is project-scoped and based on the highest lifetime
  one-day gross tip total in the supplied reporting timezone.
- `is_current_period_record=true` when the selected timeline range contains the
  record date.
- `rewards_gifted` reports audience reward claims scoped to the selected
  period's UTC window (based on claim `claimed_at`):
  - `rewards_gifted.total` is the sum of all claim counts in the window.
  - `rewards_gifted.rewards[]` lists every reward threshold currently owned
    by the project (ordered by `sort_order` then `id`), even when its
    `count` for the period is 0. Each entry includes `reward_threshold_id`,
    `reward_label`, `reward_icon` (nullable curated icon code), and `count`.
- `link_clicks` reports audience clicks on performer links scoped to the
  selected period's UTC window (based on `clicked_at`):
  - `link_clicks.learn_more` (int) — clicks on the "Learn More About the
    Performer" link
  - `link_clicks.track_performer` (int) — clicks on the "Track the
    Performer" link

If the owning project is not on Pro, these endpoints return `403` with
`code=feature_requires_pro`.

---

## Canonical settings write fields

- `name`
- `performer_info_url`
- `performer_track_url`
- `min_tip_cents` (backend rounds cent inputs up to the next whole dollar)
- `queue_nudge_cents` (minimum 100; backend rounds up to whole dollar; default 500)
- `free_request_threshold_cents` (0 to disable; backend rounds up to whole dollar)
- `quick_tip_amounts_cents` (exactly 3 whole-dollar cent values in descending display order)
- `is_accepting_requests`
- `is_accepting_tips`
- `is_accepting_original_requests`
- `notify_on_request`
- `show_persistent_queue_strip`
- `acknowledge_invisible_requests` (ephemeral per-request flag; not persisted; see notification gate above)
- `public_repertoire_set_id` (nullable int; set to override public song list, null to reset)
- `min_suggested_setlist_songs` (int, 1..100)
- `max_suggested_setlist_songs` (int, 1..100; must be >= min)
- `remove_performer_profile_image`
- `chart_viewport_prefs` (deprecated write target, kept temporarily)

---

## Deprecated field

`chart_viewport_prefs` at project scope is deprecated.

Canonical viewport persistence is per `(user, chart, page)` via:
- `GET /api/v1/me/charts/{chartId}/pages/{page}/viewport`
- `PUT /api/v1/me/charts/{chartId}/pages/{page}/viewport`

Clients should migrate to these endpoints and stop writing project-level viewport blobs.

---

## Reward thresholds

- `GET /{projectId}/reward-thresholds` — list thresholds for an owned project
- `POST /{projectId}/reward-thresholds` — create a threshold
- `PUT /{projectId}/reward-thresholds/{rewardThresholdId}` — update a threshold
- `DELETE /{projectId}/reward-thresholds/{rewardThresholdId}` — delete a threshold
- `PUT /{projectId}/reward-thresholds/reorder` — reorder by `ids[]`

### Default threshold on project creation

- Every newly created project automatically receives a single repeating
  `free_request` threshold at $40 (`threshold_cents=4000`,
  `reward_label="Free Song Request"`, `reward_icon="music_note"`,
  `is_repeating=true`, `sort_order=0`).
- This behaviour is server-side; clients do not need to create it.
- Owners may edit or delete the default threshold at any time.

### Reward threshold payload fields

- `id`
- `threshold_cents` (rounded up to a whole-dollar cent value on write; minimum 100)
- `reward_type` (short string, max 50; `free_request` is the auto-claimable type)
- `reward_label` (display label, max 255)
- `reward_icon` (nullable curated icon code — see below)
- `reward_icon_emoji` (read-only emoji mapped from `reward_icon`; `null` when `reward_icon` is `null`)
- `reward_description` (nullable string, max 500 chars; optional longer explanation shown under the label)
- `is_repeating` (boolean; repeating thresholds grant one claim at every multiple)
- `sort_order` (integer; ascending display order)

### Curated `reward_icon` codes

`reward_icon` must be one of: `music_note`, `card_giftcard`, `star`,
`favorite`, `local_bar`, `local_cafe`, `mic`, `celebration`, `emoji_events`,
`album`, `checkroom`, `headphones`. Any other value returns `422`. Mobile
clients render the same codes via Material icons; the audience web views
render the emoji mapped to each code.
