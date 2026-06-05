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
- `POST /{projectId}/members` - owner-only invite of an existing Tipelodeon user
- `DELETE /{projectId}/members/{membershipId}` - owner-only removal of a project member
- `POST /{projectId}/transfer-ownership` - owner-only handoff of the band to an existing member

---

## Project members

- Owners can invite another existing Tipelodeon user by email.
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

## Ownership transfer (Make band leader)

Ownership is a single column (`projects.owner_user_id`); the owner is never a
`project_members` row. Registration does not auto-create a project, so when a
band member signs up before the leader, the member ends up owning the band.
This endpoint lets the current owner hand the band to a rightful leader who is
already an invited member.

- `POST /{projectId}/transfer-ownership` — owner-only. Body:
  `{ "project_member_id": <int> }` (the membership row id from `members[].id`).
  This exact field name is used verbatim by the FormRequest, controller, and
  the Dart client.
- Runs one DB transaction that: flips `owner_user_id`; deletes the new owner's
  member row (the owner is never a member row); upserts the former owner as a
  `member`; reconciles both `notify_on_request` preference rows to the role
  defaults (new owner `true`, former owner `false`); promotes the new owner's
  synced song copies to canonical lineage (nulls `source_project_song_id` so
  `ownerProjectSongs()` returns them); and writes an append-only
  `project_ownership_transfers` audit row.
- The former owner is demoted to `member` as a **consequence** of the flip,
  not via a separate role change.
- Returns `200` with the refreshed members overview
  (`{ "data": { "owner", "members" } }`) plus a top-level `warnings: [...]`
  array of `{ code, message }`. Clients then re-fetch the access bundle
  (`GET /me/projects`) for the authoritative per-project role, so the response
  body is not load-bearing for role.
- **Warnings (allow + warn, never block):**
  - `new_owner_payout_not_ready` — the new owner's payout account is not
    enabled, so new fan tips are declined until they finish setup. No tips are
    charged or held in the meantime (the per-request payout gate already
    rejects with `payout_setup_incomplete`).
  - `new_owner_over_project_limit` — informational; the limit gates *creating*
    projects, not handoffs.
  - `active_performance_session` — informational; tipping is not session-coupled.
- **Blocks:** non-owner → `403`; target not a member of *this* project,
  unknown id, non-integer, or a soft-deleted target → `422`.
- Acknowledgement of consequences is **client-side** (a confirm dialog). There
  is no server two-phase handshake — it would deadlock the Idempotency-Key
  middleware, which replays the stored response for a repeated
  `(actor, method, path, Idempotency-Key)`.
- Two queued emails go out `afterCommit`: one to the new owner, one to the
  former owner.

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
- `cooldowns_enabled` (boolean; default `true`; when `true`, songs go on cooldown for `cooldown_minutes` after being performed in the **currently active `PerformanceSession`** and audience must tip at least `cooldown_bust_amount_cents` to bypass; cooldowns are session-scoped and do not carry over when a new session starts)
- `cooldown_minutes` (int 1..1440; default 60; how long a song stays on cooldown after being performed in the active session)
- `cooldown_bust_amount_cents` (unsigned int; default 3000 = $30; minimum tip required to bypass an active cooldown — replaces `min_tip_cents` floor for the affected song; audience may tip more)
- `repeats_enabled` (boolean; default `true`; when `false`, any song already played in the current active `PerformanceSession` is locked from re-request — public repertoire shows "Already performed" and the API returns `code: song_already_performed_this_session` 422)
- `free_request_threshold_cents` (cumulative tip threshold for earning a free request; 0 = disabled; default 3000; rounded up to whole dollar on write; deprecated — use `reward_thresholds` instead)
- `reward_thresholds[]` (array of reward threshold objects owned by this project; see below)
- `audience_starting_tip_cents` (whole-dollar cent value; default 2000 = $20; the amount the audience snackbar opens with when a song's Request button is tapped)
- `is_accepting_requests`
- `is_accepting_tips`
- `is_accepting_original_requests`
- `notify_on_request` (per-viewer; sourced from `project_member_preferences` for the requesting user; defaults to `true` for the owner and `false` for new members; updated via `PUT /{projectId}/preferences`, not via project update)
- `show_persistent_queue_strip` (per-viewer; sourced from `project_member_preferences` for the requesting user; defaults to `true` for everyone; updated via `PUT /{projectId}/preferences`, not via project update)
- `public_repertoire_set_id` (nullable int, FK to `setlist_sets.id`; when set, the public project page shows only songs from this set instead of the full `is_public` repertoire; `nullOnDelete` auto-resets when the set is deleted)
- `public_repertoire_set_name` (read-only, set name when `public_repertoire_set_id` is loaded)
- `public_repertoire_setlist_name` (read-only, parent setlist name when `public_repertoire_set_id` is loaded)
- `public_repertoire_setlist_id` (nullable int, FK to `setlists.id`; when set, the public project page shows the union of all songs across every set of this setlist instead of the full `is_public` repertoire; takes precedence over `public_repertoire_set_id`; mutually exclusive — setting either field clears the other; `nullOnDelete` auto-resets when the setlist is deleted)
- `public_repertoire_full_setlist_name` (read-only, setlist name when `public_repertoire_setlist_id` is loaded)
- `min_suggested_setlist_songs` (int, default 5, range 1..100; minimum number of songs an audience member must pick when suggesting a setlist via `/project/{slug}/suggest-setlist`)
- `max_suggested_setlist_songs` (int, default 25, range 1..100; maximum number of songs; must be >= `min_suggested_setlist_songs`)
- `chart_viewport_prefs` (deprecated, nullable object)

Payout readiness fields:
- `payout_setup_complete` (boolean)
- `payout_account_status` (`not_started|pending|enabled|restricted`)
- `payout_status_reason` (nullable machine code/string for UI messaging)

Entitlements fields:
- `entitlements.repertoire_song_limit` (currently `null` — uncapped)
- `entitlements.project_limit` (currently `null` — uncapped)
- `entitlements.single_chart_upload_limit_bytes` (`10485760`)
- `entitlements.bulk_chart_upload_limit_bytes` (`10485760`)
- `entitlements.bulk_chart_file_limit` (`20`)
- `entitlements.ai_interactive_per_minute` (`100`)
- `entitlements.bulk_ai_window_limit` (`1000`)
- `entitlements.bulk_ai_window_hours` (`1`)
- `entitlements.can_access_queue`
- `entitlements.can_access_history`
- `entitlements.can_view_owner_stats`
- `entitlements.can_view_wallet`

Project creation limit:
- The current entitlements set `project_limit=null` (uncapped). The
  `project_limit_reached` 422 response is preserved for forward compatibility
  but is not currently emitted.

---

## Payout gate behavior for requests and tips

When updating a project:
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

## Member preferences (per-viewer)

`notify_on_request` and `show_persistent_queue_strip` are scoped per user
per project. Each project member (owner and members) has their own values
backed by `project_member_preferences (project_id, user_id, ...)`.

### Endpoint

`PUT /api/v1/me/projects/{projectId}/preferences`

Authorization: any user with access to the project (owner or member).

Request body (all optional, only set fields you want to change):

```json
{
  "notify_on_request": false,
  "show_persistent_queue_strip": true,
  "acknowledge_invisible_requests": false
}
```

Response `200`:

```json
{
  "message": "Preferences updated.",
  "preferences": {
    "notify_on_request": false,
    "show_persistent_queue_strip": true
  }
}
```

### Defaults

- Owner default: `notify_on_request=true`, `show_persistent_queue_strip=true`.
- Member default on first membership: `notify_on_request=false`,
  `show_persistent_queue_strip=true`. Members opt in to email explicitly.
- The owner's row is backfilled on first preference write or on project read
  via `preferenceFor()`.

### Notification gate (invisible requests)

When the editing user would have both `notify_on_request=false` and
`show_persistent_queue_strip=false` while the project is accepting requests,
the editing user has no passive way to notice incoming requests. This is a
confirmation gate, not a hard block — sidemen who don't need notifications
should be able to silence themselves.

If the resulting preference state for the editing user would have both
`notify_on_request=false` and `show_persistent_queue_strip=false` while
`project.is_accepting_requests=true`, and the payload toggles at least one
of those two fields toward off, and `acknowledge_invisible_requests` is not
`true`, the API returns `422`:

```json
{
  "code": "notification_channel_required",
  "message": "You are about to disable all of your request notifications. Confirm to proceed.",
  "details": { "required_field": "acknowledge_invisible_requests" }
}
```

On retry with `acknowledge_invisible_requests: true`, the update succeeds.
The flag is ephemeral per-request and is never persisted.

### Project-update gate (re-enabling requests)

`PATCH /projects/{projectId}` no longer accepts `notify_on_request` or
`show_persistent_queue_strip`. It still gates `is_accepting_requests` flipping
from `false` to `true`: if the editor's own preferences are both off, the same
`notification_channel_required` 422 fires, asking the editor to acknowledge.

### Client expectations

- On `422` with `code=notification_channel_required`, surface a confirmation
  dialog explaining the consequence (incoming requests will be invisible to
  you unless you manually open the queue screen) and retry the original
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
  - `link_clicks.website` (int) — clicks on the "Website" link
  - `link_clicks.follow` (int) — clicks on the "Follow" link
- `top_audience_tipper` is the single audience profile with the highest
  summed `tip_amount_cents` across the window, or `null` when no audience
  profile tipped. The object shape is `AudienceTipperSummary`
  (`audience_profile_id`, `display_name`, `request_count`,
  `tip_total_cents`). Excludes manual cash entries that have no
  `audience_profile_id`.

Endpoint access is owner-only — non-owners receive `403`.

---

## Canonical settings write fields

- `name`
- `performer_info_url`
- `performer_track_url`
- `min_tip_cents` (backend rounds cent inputs up to the next whole dollar)
- `queue_nudge_cents` (minimum 100; backend rounds up to whole dollar; default 500)
- `cooldowns_enabled` (boolean; default true)
- `cooldown_minutes` (int 1..1440; default 60)
- `cooldown_bust_amount_cents` (unsigned int >= 0; default 3000)
- `repeats_enabled` (boolean; default true)
- `free_request_threshold_cents` (0 to disable; backend rounds up to whole dollar)
- `audience_starting_tip_cents` (int, minimum 100; backend rounds up to whole dollar; default 2000)
- `is_accepting_requests`
- `is_accepting_tips`
- `is_accepting_original_requests`
- `acknowledge_invisible_requests` (ephemeral per-request flag; not persisted; see notification gate above)
- `public_repertoire_set_id` (nullable int; set to override public song list with one set, null to reset; setting a non-null value also clears `public_repertoire_setlist_id`)
- `public_repertoire_setlist_id` (nullable int; set to override public song list with every song across every set of a setlist, null to reset; setting a non-null value also clears `public_repertoire_set_id`; takes precedence on read)
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
  `free_request` threshold at $30 (`threshold_cents=3000`,
  `reward_label="Free Song"`, `reward_icon="music_note"`,
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
