# TestFlight beta enrollment automation

End-to-end wiring so an admin can click **Invite to TestFlight** on an
early-access row in `/admin/users` and have the email land in the
configured external beta group at Apple — no Fastlane shell-out, no
Ruby on the web host.

Android / Google Workspace Group enrollment is intentionally not wired
yet (creds aren't ready); the service layer is shaped to drop that in
alongside the iOS path later.

## How it fits together

```
admin clicks Invite           Livewire dispatches             queued job calls
on early-access row     ─►    InviteEarlyAccessToTestFlight ─► TestFlightEnrollmentService
                                                                       │
                                                                       ▼
                                                        AppStoreConnectClient
                                                        (ES256 JWT, App Store Connect REST)
                                                                       │
                                                                       ▼
                                                        POST /v1/betaTesters
                                                        + group + app relationships
```

Failure messages are persisted to `early_access_requests.testflight_error`
and surfaced on the same admin row. The button becomes **Re-invite**
after the first successful run; re-running is safe (idempotent — Apple
returns 204 when a tester is already in the group).

Touched files:

- `web/app/Services/TestFlight/AppStoreConnectClient.php`
- `web/app/Services/TestFlight/TestFlightEnrollmentService.php`
- `web/app/Jobs/InviteEarlyAccessToTestFlight.php`
- `web/app/Console/Commands/InviteEarlyAccessToTestFlightCommand.php`
- `web/app/Providers/AppServiceProvider.php` (client singleton binding)
- `web/config/services.php` (`app_store_connect` block)
- `web/database/migrations/*_add_testflight_columns_to_early_access_requests_table.php`
- `web/resources/views/components/⚡admin-users-page.blade.php`
- `web/tests/Feature/TestFlight/TestFlightEnrollmentServiceTest.php`
- `web/tests/Feature/AdminUsersPageTest.php`

## One-time setup at Apple

### 1. App Store Connect API key (shared with Fastlane)

If the Fastlane screenshot pipeline is already shipping (see
`app/fastlane/Deliverfile`), the key already exists — re-use it. If
not:

1. App Store Connect → **Users and Access** → **Integrations** →
   **App Store Connect API** → **Generate API Key**.
2. Role: **App Manager** (needed for TestFlight write).
3. Download the `.p8` — Apple only shows it once.
4. Note the **Key ID** and (above the table) the **Issuer ID**.

### 2. Find the app's numeric ID

The bundle id is `com.gnarhard.tipelodeon`. Apple's REST API needs the
numeric record id, not the bundle id. The easiest way to find it:
open the app in App Store Connect; the id is in the URL
(`…/apps/<APP_ID>/…`).

### 3. Create an external beta group

App Store Connect → TestFlight → **External Testing** →
**Create New Group**. Name it something stable like
`Tipelodeon External Beta`. Grab the group id from the URL after
clicking into it.

**Required for enrollment to do anything visible:** the group must
have at least one approved build attached, otherwise Apple accepts
the invite but never sends an email. Push a TestFlight build via
Xcode / Fastlane and attach it to this group before flipping the
admin button on.

## Wiring it into Forge

Add these to the site's environment (Forge → site → Environment):

```env
# Shared with app/fastlane/Deliverfile — reuse if already set.
APP_STORE_CONNECT_API_KEY_KEY_ID=ABCDEFGHIJ
APP_STORE_CONNECT_API_KEY_ISSUER_ID=00000000-0000-0000-0000-000000000000
APP_STORE_CONNECT_API_KEY_KEY_FILEPATH=/home/forge/secrets/AuthKey_ABCDEFGHIJ.p8

# New — TestFlight-specific.
APP_STORE_CONNECT_APP_ID=1234567890
APP_STORE_CONNECT_BETA_GROUP_ID=00000000-0000-0000-0000-000000000000
```

Upload the `.p8` to the path in `APP_STORE_CONNECT_API_KEY_KEY_FILEPATH`
and make sure the Forge user (`forge`) can read it:

```bash
mkdir -p /home/forge/secrets
chmod 700 /home/forge/secrets
# scp AuthKey_ABCDEFGHIJ.p8 forge@host:/home/forge/secrets/
chmod 600 /home/forge/secrets/AuthKey_ABCDEFGHIJ.p8
```

Run the migration on deploy (already covered by the standard deploy
script):

```bash
php artisan migrate --force
```

The job uses the default queue connection (`redis` in prod). The
existing queue worker picks it up — no new daemon needed.

## Smoke test

Before relying on the admin button, run the synchronous artisan
command. It bypasses the queue and prints the failure verbatim, which
is the fastest way to debug bad creds / missing app id / etc.:

```bash
# Run on a machine that can reach api.appstoreconnect.apple.com
# (i.e. the prod box or any dev machine with the .p8 + env vars).
php artisan tipelodeon:testflight:invite you@yourcompany.com
```

Expected output on success:

```
Invited you@yourcompany.com (tester id <uuid>).
```

The early-access row in `/admin/users` will then show the **Invited**
pill and an `invited <time> ago` timestamp.

Common failures:

| Output | Fix |
| --- | --- |
| `Missing services.app_store_connect.* configuration value.` | Env var not set / not loaded — restart php-fpm + queue worker after editing Forge env. |
| `App Store Connect key file is not readable: …` | File missing or unreadable by the `forge` user; check `chmod 600` + ownership. |
| `App Store Connect private key could not be parsed` | The `.p8` was corrupted (line endings) during upload; re-upload in binary mode. |
| `HTTP 401 Authentication credentials are missing or invalid.` | Wrong Key ID, wrong Issuer ID, or the key was revoked. |
| `HTTP 409 … duplicate` on create | A prior partial run left the tester at Apple; the service handles this automatically on the next click — should self-heal. |
| Invite succeeds but tester gets no email | Beta group has no attached build. Attach a TestFlight build to the group. |

## Day-to-day operation

1. User submits the landing-page early-access form.
2. Admin visits `/admin/users`, scrolls to **Early Access Requests**.
3. Clicks **Invite to TestFlight** on the row.
4. Job runs; the row flips to **Invited** (or **Failed** with the error
   inline + a **Re-invite** button to retry once the underlying issue
   is fixed).

To bulk-invite a backlog without writing UI, loop the artisan command:

```bash
php artisan tinker --execute '
  App\Models\EarlyAccessRequest::query()
    ->whereNull("testflight_invited_at")
    ->pluck("email")
    ->each(fn ($email) => \Illuminate\Support\Facades\Artisan::call(
        "tipelodeon:testflight:invite", ["email" => $email]
    ));'
```

## Local development

The service is opt-in: if the env vars aren't set, the **Invite**
button will dispatch the job, the job will hit
`TestFlightConfigurationException`, and the row's `testflight_error`
column will explain which env var is missing — no calls leave the box.

To exercise the happy path locally without hitting Apple, the feature
test (`tests/Feature/TestFlight/TestFlightEnrollmentServiceTest.php`)
uses `Http::fake()` against a runtime-generated P-256 key, so no
network or real `.p8` is required.

## Deferred: Android / Google Workspace Group

When Workspace + service-account credentials exist, the next steps:

1. Create a Google Group (e.g. `tipelodeon-beta@yourdomain.com`); point
   the Play Console closed track at it as the tester audience.
2. Add a `GoogleGroupEnrollmentService` next to
   `TestFlightEnrollmentService` that calls the Admin SDK to add a
   member.
3. Have `InviteEarlyAccessToTestFlight` (or a renamed parent job) fan
   out to both services and aggregate failures into `testflight_error`
   + a new `play_store_error` column.

The current admin button copy ("Invite to TestFlight") deliberately
names the iOS path only — change it to "Invite to beta" when both
platforms are live.
