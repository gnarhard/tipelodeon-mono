# Auth API Contracts

## Auth and scope

Authentication endpoints are **public** (no auth required for login, password reset) except for logout and password update which require Bearer token.

Route prefix: `/api/v1/auth`

All auth write endpoints accept an optional `Idempotency-Key` header.

## Performer Fees

Tipelodeon is free for performers — no plan tiers, subscriptions, or trials.
Audience members pay a flat $2 platform fee on every digital tip; performers
always net the full tip amount. Cash tips logged through the app carry no
platform fee. New signups land directly in the app after email verification —
there is no web dashboard.

---

## Register

- **Method**: `POST`
- **Path**: `/register`

Creates a new performer account. The server emails a 6-digit verification code
to the supplied address; the caller must complete `Verify Email` before any
authenticated endpoint becomes accessible. **No token is issued by this
endpoint.**

### Request body

```json
{
  "name": "string (required, max 255)",
  "email": "string (required, lowercase email, unique)",
  "password": "string (required, confirmed, default complexity rules)",
  "password_confirmation": "string (required, must match password)",
  "instrument_type": "string (required, must be one of the supported instruments)",
  "secondary_instrument_type": "string (optional)",
  "device_name": "string (optional)"
}
```

### Success response (`201`)

```json
{
  "user_id": 42,
  "email": "alpha@example.com",
  "requires_verification": true
}
```

### Error response (`422`)

Standard Laravel validation errors (`message`, `errors`).

### Throttle

5 requests per minute per IP.

**Notes:**
- `instrument_type` is required during API registration.
  `secondary_instrument_type` is always optional.
- The platform also queues an `AdminNewUserSignupMail` to admin recipients.

---

## Verify Email

- **Method**: `POST`
- **Path**: `/verify-email`

Submit the 6-digit code emailed during registration. On success the server
marks the account verified and returns the same envelope as `Login`
(`{token, accessBundle, user}`).

### Request body

```json
{
  "user_id": 42,
  "code": "123456",
  "device_name": "string (optional)"
}
```

### Success response (`200`)

Identical to the `Login` envelope.

### Error response (`422`)

```json
{
  "message": "The verification code is invalid or has expired."
}
```

### Throttle

10 requests per minute per IP.

---

## Resend Verification Code

- **Method**: `POST`
- **Path**: `/resend-verification-code`

Rotate and re-issue the 6-digit code. The previously issued code is
invalidated immediately.

### Request body

```json
{
  "user_id": 42
}
```

### Success response (`200`)

```json
{
  "message": "Verification code sent."
}
```

### Throttle

5 requests per hour per IP.

---

## Login

- **Method**: `POST`
- **Path**: `/login`

### Request body

```json
{
  "email": "string (required)",
  "password": "string (required)",
  "device_name": "string (optional, defaults to 'api')"
}
```

### Success response (`200`)

```json
{
  "token": "1|abc123...",
  "accessBundle": {
    "projects": [
      {
        "id": 1,
        "name": "Friday Jazz Night",
        "slug": "friday-jazz",
        "performer_info_url": "https://example.com/about",
        "performer_profile_image_url": "https://example.com/storage/performers/1/profile.png",
        "min_tip_cents": 500,
        "is_accepting_requests": true,
        "is_accepting_tips": true,
        "is_accepting_original_requests": true,
        "notify_on_request": true,
        "show_persistent_queue_strip": true,
        "owner_user_id": 1,
        "payout_setup_complete": false,
        "payout_account_status": "pending",
        "payout_status_reason": "requirements_due",
        "entitlements": {
          "repertoire_song_limit": null,
          "single_chart_upload_limit_bytes": 10485760,
          "bulk_chart_upload_limit_bytes": 10485760,
          "bulk_chart_file_limit": 20,
          "ai_interactive_per_minute": 30,
          "bulk_ai_window_limit": 500,
          "bulk_ai_window_hours": 6,
          "can_access_queue": true,
          "can_access_history": true,
          "can_view_owner_stats": true,
          "can_view_wallet": true
        }
      }
    ]
  },
  "user": {
    "id": 1,
    "name": "Mike Johnson",
    "email": "mike@example.com",
    "instrument_type": "vocals",
    "secondary_instrument_type": "piano",
    "ai_lyric_disclaimer_acknowledged": false,
    "chart_tour_completed": false
  }
}
```

> `notify_on_request` and `show_persistent_queue_strip` in each
> `accessBundle.projects[]` entry are viewer-scoped: the values returned
> are the signed-in user's preferences from `project_member_preferences`,
> not project-wide values. Updating them goes through
> `PUT /me/projects/{projectId}/preferences`, not the project endpoint —
> see `contracts/projects.md` → "Member preferences (per-viewer)".

### Error response (`401`)

```json
{
  "message": "Invalid credentials."
}
```

### Error response (`403`)

```json
{
  "message": "Please verify your email address before signing in.",
  "user_id": 42,
  "email": "alpha@example.com",
  "requires_verification": true
}
```

`user_id` and `email` are echoed back so clients that lost the
post-registration verification context (e.g. the user reopened the app
before entering the OTP) can resume the verify-email flow without
re-registering.

**Notes:**
- New accounts are created via the API `Register` endpoint above. The web
  registration form has been removed.
- Newly created accounts must confirm email ownership before token login
  succeeds.
- API registration requires a primary instrument; secondary instrument is
  optional.

---

## Logout

- **Method**: `POST`
- **Path**: `/logout`
- **Auth**: Required (Bearer token)

Revoke the current Sanctum access token.

### Success response (`200`)

```json
{
  "message": "Logged out successfully."
}
```

### Error response (`401`)

Unauthenticated.

---

## Forgot Password

- **Method**: `POST`
- **Path**: `/forgot-password`

Request a password reset email.

### Request body

```json
{
  "email": "string (required, valid email)"
}
```

### Success response (`200`)

```json
{
  "message": "If an account with that email exists, we have emailed a password reset link."
}
```

**Notes:**
- Response is intentionally generic to avoid revealing whether an email exists
- Validation errors still return standard Laravel `422` responses

---

## Reset Password

- **Method**: `POST`
- **Path**: `/reset-password`

Reset password using email + reset token.

### Request body

```json
{
  "token": "string (required)",
  "email": "string (required, valid email)",
  "password": "string (required, confirmed)",
  "password_confirmation": "string (required, must match password)"
}
```

### Success response (`200`)

```json
{
  "message": "Password has been reset."
}
```

### Error response (`422`)

```json
{
  "message": "The provided password reset token is invalid."
}
```

---

## Profile

- **Method**: `GET`
- **Path**: `/api/v1/me/profile`
- **Auth**: Required (Bearer token)

Return the authenticated user's profile.

### Success response (`200`)

```json
{
  "user": {
    "id": 1,
    "name": "Mike Johnson",
    "email": "mike@example.com",
    "instrument_type": "vocals",
    "secondary_instrument_type": "piano",
    "ai_lyric_disclaimer_acknowledged": false,
    "chart_tour_completed": false
  }
}
```

- **Method**: `PATCH`
- **Path**: `/api/v1/me/profile`
- **Auth**: Required (Bearer token)

Update the authenticated user's mobile-owned profile fields.

### Request body

```json
{
  "name": "Mike Johnson",
  "email": "mike@example.com",
  "instrument_type": "drums",
  "secondary_instrument_type": "keyboard",
  "ai_lyric_disclaimer_acknowledged": true,
  "chart_tour_completed": true
}
```

All fields are optional — only included fields are updated.

- `name` and `email` must be non-empty when present; `email` must be lowercase
  and unique across active (non-soft-deleted) users.
- Submitting a new `email` clears `email_verified_at` and issues a fresh
  verification code to the new address. The response reflects the updated
  `email`, but the verification flag does not return until the new address
  is confirmed.
- Re-submitting the user's current `email` is a no-op and does not reset
  verification.
- `ai_lyric_disclaimer_acknowledged` is a per-user, cross-device flag. The
  Flutter app shows the "AI lyrics may be inaccurate" disclaimer as a
  confirm-before-generate dialog whenever this flag is `false`. Sending
  `true` records the acknowledgement (server-side timestamp); sending
  `false` clears it so the dialog returns. The "Reset tutorials" action in
  account settings sends `false`.
- `chart_tour_completed` is a per-user, cross-device flag. The Flutter app
  shows an in-place gesture-map overlay (page turns, mark-performed,
  show/hide controls) the first time a chart is displayed whenever this flag
  is `false`. Dismissing the overlay (or tapping the command-bar "Help"
  button and then dismissing) sends `true`; the "Reset tutorials" action in
  account settings sends `false` so the overlay surfaces again.

---

## Account usage

- **Method**: `GET`
- **Path**: `/api/v1/me/usage`
- **Auth**: Required (Bearer token)

Returns the authenticated owner account's current usage state, including:
- storage usage and thresholds
- AI usage and estimated cost
- current review/block state
- activity and archival timestamps
- emitted warning markers

Both `instrument_type` and `secondary_instrument_type` may be set to `null` to clear the selection.
Each field is sent independently via `PATCH` — only included fields are updated.

`instrument_type` is required during API registration. `secondary_instrument_type` is always optional.

Allowed values for both fields:

- `vocals`
- `guitar`
- `bass`
- `piano`
- `violin`
- `trumpet`
- `trombone`
- `saxophone`
- `drums`
- `keyboard`
- `harmonica`
- `mandolin`
- `banjo`
- `ukulele`
- `percussion`

### Success response (`200`)

```json
{
  "message": "Profile updated successfully.",
  "user": {
    "id": 1,
    "name": "Mike Johnson",
    "email": "mike@example.com",
    "instrument_type": "drums",
    "secondary_instrument_type": "keyboard"
  }
}
```

---

## Delete Account

- **Method**: `DELETE`
- **Path**: `/api/v1/me/account`
- **Auth**: Required (Bearer token)

Permanently deletes the authenticated user, revokes all Sanctum tokens, and
removes the user record.

### Success response (`204`)

Empty body. Subsequent requests using the old token return `401`.

---

## Update Password

- **Method**: `PUT`
- **Path**: `/password`
- **Auth**: Required (Bearer token)

Update password for authenticated user.

### Request body

```json
{
  "current_password": "string (required, must match current password)",
  "password": "string (required, confirmed)",
  "password_confirmation": "string (required, must match password)"
}
```

### Success response (`200`)

```json
{
  "message": "Password updated successfully."
}
```

### Error response (`422`)

```json
{
  "message": "Current password is incorrect.",
  "errors": {
    "current_password": [
      "Current password is incorrect."
    ]
  }
}
```
