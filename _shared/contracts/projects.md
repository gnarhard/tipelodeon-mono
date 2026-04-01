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
- `POST /{projectId}/header-banner-image` - upload header banner image for public page
- `POST /{projectId}/background-image` - upload background image for public page
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
- `performer_profile_image_url` (nullable)
- `header_banner_image_url` (nullable; full-width banner displayed on the public project page)
- `background_image_url` (nullable; subtle background watermark on the public project page)
- `brand_color_hex` (nullable; 7-char hex string like `#ff5733` used to tint the public project page)
- `min_tip_cents` (rounded up to a whole-dollar cent value on write)
- `free_request_threshold_cents` (cumulative tip threshold for earning a free request; 0 = disabled; default 4000; rounded up to whole dollar on write)
- `quick_tip_amounts_cents` (exactly 3 whole-dollar cent values in descending display order)
- `is_accepting_requests`
- `is_accepting_tips`
- `is_accepting_original_requests`
- `notify_on_request` (default true; when true, owner receives email on new requests/tips)
- `show_persistent_queue_strip`
- `public_repertoire_set_id` (nullable int, FK to `setlist_sets.id`; when set, the public project page shows only songs from this set instead of the full `is_public` repertoire; `nullOnDelete` auto-resets when the set is deleted)
- `public_repertoire_set_name` (read-only, set name when `public_repertoire_set_id` is loaded)
- `public_repertoire_setlist_name` (read-only, parent setlist name when `public_repertoire_set_id` is loaded)
- `chart_viewport_prefs` (deprecated, nullable object)

Payout readiness fields:
- `payout_setup_complete` (boolean)
- `payout_account_status` (`not_started|pending|enabled|restricted`)
- `payout_status_reason` (nullable machine code/string for UI messaging)

Entitlements fields:
- `entitlements.plan_code`
- `entitlements.plan_tier` (`free|basic|pro`)
- `entitlements.repertoire_song_limit` (`20` for Free, `200` for Basic, `null` for Pro)
- `entitlements.project_limit` (`1` for Free, `3` for Basic, `null` for Pro)
- `entitlements.single_chart_upload_limit_bytes` (`2097152`)
- `entitlements.bulk_chart_upload_limit_bytes` (`2097152`)
- `entitlements.bulk_chart_file_limit` (`20`)
- `entitlements.ai_interactive_per_minute` (`10` Free/Basic, `30` Pro)
- `entitlements.bulk_ai_window_limit` (`500`)
- `entitlements.bulk_ai_window_hours` (`6`)
- `entitlements.can_use_public_requests` (Pro-only)
- `entitlements.can_access_queue` (Pro-only)
- `entitlements.can_access_history` (Pro-only)
- `entitlements.can_view_owner_stats` (Pro-only)
- `entitlements.can_view_wallet` (Pro-only)
- `entitlements.can_invite_members` (Pro-only — band sync)

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
- If the owner plan is Basic and the update would newly enable public requests
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
- `period`, `money`, `counts`, `highlights`, and `rankings` remain unchanged.
- `records.best_day` is nullable and, when present, includes:
  - `gross_tip_amount_cents`
  - `local_date`
  - `timezone`
  - `is_current_period_record`
- `records.best_day` is project-scoped and based on the highest lifetime
  one-day gross tip total in the supplied reporting timezone.
- `is_current_period_record=true` when the selected timeline range contains the
  record date.

If the owning project is not on Pro, these endpoints return `403` with
`code=feature_requires_pro`.

---

## Canonical settings write fields

- `name`
- `performer_info_url`
- `min_tip_cents` (backend rounds cent inputs up to the next whole dollar)
- `free_request_threshold_cents` (0 to disable; backend rounds up to whole dollar)
- `quick_tip_amounts_cents` (exactly 3 whole-dollar cent values in descending display order)
- `is_accepting_requests`
- `is_accepting_tips`
- `is_accepting_original_requests`
- `notify_on_request`
- `show_persistent_queue_strip`
- `public_repertoire_set_id` (nullable int; set to override public song list, null to reset)
- `brand_color_hex` (nullable; 7-char hex string or null to clear)
- `remove_performer_profile_image`
- `remove_header_banner_image`
- `remove_background_image`
- `chart_viewport_prefs` (deprecated write target, kept temporarily)

---

## Deprecated field

`chart_viewport_prefs` at project scope is deprecated.

Canonical viewport persistence is per `(user, chart, page)` via:
- `GET /api/v1/me/charts/{chartId}/pages/{page}/viewport`
- `PUT /api/v1/me/charts/{chartId}/pages/{page}/viewport`

Clients should migrate to these endpoints and stop writing project-level viewport blobs.
