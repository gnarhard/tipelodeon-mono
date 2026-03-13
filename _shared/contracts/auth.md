# Auth API Contracts

## Auth and scope

Authentication endpoints are **public** (no auth required for login, password reset) except for logout and password update which require Bearer token.

Route prefix: `/api/v1/auth`

All auth write endpoints accept an optional `Idempotency-Key` header.

## Performer Billing Setup (web)

- New web signups must verify their email address before they can reach billing setup or the performer dashboard.
- After web registration, performers must complete billing setup before accessing the web dashboard.
- Available billing plans:
  - `basic_monthly` at `$4.99/month`
  - `basic_yearly` at `$49.99/year`
  - `pro_monthly` at `$19.99/month`
  - `pro_yearly` at `$199.99/year`
- Paid plans collect a payment method up front and begin with a 30-day free trial.
- Complimentary access can be granted in two forms:
  - `free_year` expires after the configured complimentary period
  - `lifetime` never expires
- Complimentary users still select a plan so the app knows whether they are on the Basic or Pro tier, but they may skip payment method collection while the discount is active.
- This billing setup is currently enforced in the web session flow and is not represented as a dedicated API contract field in the mobile auth payload.

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
          "plan_code": "basic_monthly",
          "plan_tier": "basic",
          "repertoire_song_limit": 100,
          "single_chart_upload_limit_bytes": 2097152,
          "bulk_chart_upload_limit_bytes": 2097152,
          "bulk_chart_file_limit": 20,
          "ai_interactive_per_minute": 10,
          "bulk_ai_window_limit": 500,
          "bulk_ai_window_hours": 6,
          "can_use_public_requests": false,
          "can_access_queue": false,
          "can_access_history": false,
          "can_view_owner_stats": false,
          "can_view_wallet": false
        }
      }
    ]
  },
  "user": {
    "id": 1,
    "name": "Mike Johnson",
    "email": "mike@example.com",
    "instrument_type": "vocals"
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
    "instrument_type": "vocals"
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
  "instrument_type": "drums"
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

`instrument_type` may also be `null` to clear the selection.

Allowed values:

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
    "instrument_type": "drums"
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
