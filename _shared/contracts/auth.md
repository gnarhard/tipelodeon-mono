# Auth API Contracts

## Auth and scope

Authentication endpoints are **public** (no auth required for login, password reset) except for logout and password update which require Bearer token.

Route prefix: `/api/v1/auth`

All auth write endpoints accept an optional `Idempotency-Key` header.

## Performer Billing (earnings-based)

- New signups get all features immediately with no credit card required.
- Billing is earnings-based — subscription activates only after the performer earns $400 cumulative in tips.
- Available billing plans:
  - `free` — all features, no card required, until $400 cumulative tips earned
  - `pro_monthly` at `$20/month` — auto-activates at $400 cumulative threshold
  - `pro_yearly` at `$200/year` — offered alongside monthly at activation
  - `top_earner` at `$50/month` — auto-upgrades when earning $2,500+/mo
- After $400 threshold: 14-day grace period to add payment. If no card after 14 days, audience requesting feature is gated until subscription activated.
- Auto-skip: if monthly tips < $200, billing is automatically skipped (monthly plans only).
- Complimentary access can be granted in two forms:
  - `free_year` expires after the configured complimentary period
  - `lifetime` never expires
- No billing setup wall at registration — performers go directly to the dashboard after email verification.

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
        "show_persistent_queue_strip": true,
        "owner_user_id": 1,
        "payout_setup_complete": false,
        "payout_account_status": "pending",
        "payout_status_reason": "requirements_due",
        "entitlements": {
          "plan_code": "pro_monthly",
          "plan_tier": "pro",
          "repertoire_song_limit": null,
          "single_chart_upload_limit_bytes": 2097152,
          "bulk_chart_upload_limit_bytes": 2097152,
          "bulk_chart_file_limit": 20,
          "ai_interactive_per_minute": 30,
          "bulk_ai_window_limit": 500,
          "bulk_ai_window_hours": 6,
          "can_use_public_requests": true,
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
    "secondary_instrument_type": "piano"
  }
}
```

### Error response (`401`)

```json
{
  "message": "Invalid credentials."
}
```

### Error response (`403`)

```json
{
  "message": "Please verify your email address before signing in."
}
```

**Notes:**
- Mobile app signup remains website-based.
- Newly created accounts must confirm email ownership before token login succeeds.
- Web registration requires a primary instrument; secondary instrument is optional.

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
    "secondary_instrument_type": "piano"
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
  "instrument_type": "drums",
  "secondary_instrument_type": "keyboard"
}
```

---

## Account usage

- **Method**: `GET`
- **Path**: `/api/v1/me/usage`
- **Auth**: Required (Bearer token)

Returns the authenticated owner account's current usage state, including:
- current billing plan code/tier
- storage usage and thresholds
- AI usage and estimated cost
- current review/block state
- activity and archival timestamps
- emitted warning markers

Both `instrument_type` and `secondary_instrument_type` may be set to `null` to clear the selection.
Each field is sent independently via `PATCH` — only included fields are updated.

`instrument_type` is required during web registration. `secondary_instrument_type` is always optional.

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
