# Tipelodeon Monorepo

Tipelodeon is a performer-focused app to manage songs, setlists, charts and live audience requests.

- Purpose: Manage repertoire (songs, metadata, tags), create setlists, store charts (PDFs/images), and handle audience song requests / live queue syncing.
- Architecture: Backend API + web UI in backend/, performer mobile app in frontend/ (Flutter), and shared API/contracts in shared/. - Key features: Repertoire & metadata, setlist building, chart/file uploads, audience request queue, project-scoped auth, offline-first mobile behavior.
- Data & contracts: Shared API schema / rules live in shared/ (source of truth for backend ↔ mobile).
- Auth & scope: Token-based authentication with authorization scoped to a Project/band.
- Scale & imports: Designed to support bulk imports (see database/seeders), and integrates with external metadata/enrichment as needed.

Tipelodeon is split into multiple repositories:

- `tipelodeon/` (this repo): shared files, tooling, and top-level coordination.
- `tipelodeon/web/`: Laravel API + marketing site.
- `tipelodeon/mobile_app/`: Flutter mobile app.

`web/` and `mobile_app/` are intentionally independent Git repositories and are ignored by this root repo.

## Repository remotes

- Monorepo: `git@github.com:gnarhard/tipelodeon-mono.git`
- Web: `git@github.com:gnarhard/tipelodeon-web.git`
- Mobile App: `git@github.com:gnarhard/tipelodeon-app.git`

## Prerequisites

- Git with SSH access to GitHub
- PHP 8.4+, Composer, Node.js, npm
- MySQL and Redis (for `web/`)
- Flutter 3.41+, Dart 3.10.7+, Xcode, CocoaPods, Android Studio (for `mobile_app/`)
- Server must accept 45 MBs uploads, 3 simultaneous 15 MB mp3 uploads are allowed.

## First-time setup

1. Clone the monorepo.
2. Clone each child repository into its fixed directory name.
3. Initialize game submodules.
4. Install and configure web dependencies.
5. Install and configure game dependencies.

```bash
git clone git@github.com:gnarhard/tipelodeon-mono.git
cd tipelodeon-mono

git clone git@github.com:gnarhard/tipelodeon-web.git web
git clone git@github.com:gnarhard/tipelodeon-app.git mobile_app
```

### Web setup (`web/`)

```bash
cp web/.env.example web/.env

cd web
composer install
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
cd ..
```

Notes:

- Update `web/.env` for your local database/redis configuration.
- If you use Valet, point it at `web/public`.

### Mobile App setup (`mobile_app/`)

Install mobile app dependencies:

```bash
cd mobile_app
flutter clean
flutter pub get
(cd ios && pod install --repo-update)
cd ..
```

## Running locally

- Web:

```bash
cd web
php artisan dev
```

- Mobile App:

```bash
cd mobile_app
flutter run
```

## Optional: start all MCP servers

From the monorepo root:

```bash
./start-mcp-servers.sh
```

## Admin designation

Use the following command to add admin privileges to an account:

`php artisan admin:designate admin@example.com`

## Stripe Integration

Stripe is used in two ways:

1. Standard Stripe subscription products for Pro and Veteran plans. 30 days free creates a "payment intent."
2. Connect Express. Musicians sign up for a Connect Express account to take tips from audience members. Tipelodeon is setup as a platform, not a marketplace, meaning money will not flow through Tipelodeon before it reaches the musician's account. Tipelodeon takes no fees nor charges any usage fees.

If you see "Unable to initialize payment right now. Please try again." This means the user who signed up for their account in Stripe Connect Express signed up in the sandbox instead of the live account (or vice versa).

Stripe Connect platform has a payment method and the Stripe account also has different payment methods. The regular stripe account will display when musicians sign up and when they update their billing. The Connect platform payment methods will show for the musician's when they take payment from the audience.

### Stripe Webhook testing

First run: `stripe login`

Then, run this: `stripe listen --forward-to https://tipelodeon.test/stripe/webhook`

### Connect Express Relink

`php artisan payout:relink-stripe-account your@email.com acct_XXXXXXXXXXXXXXXXX`

### Restore Lost Requests Data

`php artisan requests:restore-from-stripe user@example.com`

### Revoke and warn about lingering offers
`php artisan billing:revoke-discount user@example.com`

### Revoke and clean up the BillingOffer row in one shot

A BillingOffer is an invitation to the platform.
`php artisan billing:revoke-discount user@example.com --delete-offer`

### Why Venmo/PayPal are not used for tips

PayPal and Venmo cannot be used for performer tips because Tipelodeon's architecture requires that audience money bypass the platform entirely and flow directly to performers. This direct payment flow is critical for:

- **Performer trust**: Ensures musicians see immediate, direct payment to their accounts without intermediary processing
- **Legal risk mitigation**: Direct performer payments significantly reduce liability exposure for the company

PayPal and Venmo explicitly prohibit direct transfers between accounts because that's their core function—they prevent exactly the flow Tipelodeon requires. While an alternative architecture where money flows through Tipelodeon first (with performers requesting payouts) would technically be possible with these services, it doesn't align with the platform's trust and legal strategy.

## Song Data Integrity

Two complementary tools keep the songs table clean: rule-based checks (instant) and AI-powered review (async batch).

### Rule-based checks

```bash
# List all available checks
php artisan songs:check-integrity --list

# Run all checks
php artisan songs:check-integrity

# Run a specific check
php artisan songs:check-integrity --check=extra_whitespace

# Auto-fix safe issues (casing, whitespace, AI-suggested fixes)
php artisan songs:check-integrity --fix
```

Available checks: `duplicate_normalized_keys`, `near_duplicates`, `title_casing`, `artist_casing`, `extra_whitespace`, `suspicious_characters`, `placeholder_values`, `very_short_values`, `title_contains_artist`, `orphaned_songs`, `ai_flagged_issues`.

### AI-powered review

Songs are submitted to Anthropic's Batch API for review of misspellings, wrong artist names, bad formatting, and incorrect titles. Results are stored as `SongIntegrityIssue` records.

```bash
# Preview which songs will be submitted
php artisan songs:ai-review --dry-run

# Submit a batch (default limit: 500)
php artisan songs:ai-review

# Limit batch size
php artisan songs:ai-review --limit=100

# Re-review songs that have already been reviewed
php artisan songs:ai-review --force
```

Each song is only reviewed once. After review, `last_integrity_review_at` is set and the song is permanently skipped in future runs. Use `--force` to override this.

The batch runs daily at 3:00 AM MT via the scheduler. Results are polled every 5 minutes by the `PollBatchResults` job and written to the `song_integrity_issues` table. Issues surface in `songs:check-integrity` under the `ai_flagged_issues` check and can be auto-fixed with `--fix`.


## Marketing screenshots

Captures App Store screenshots for iPhone 6.9", iPhone 6.5", iPad 13", and macOS, frames them with device bezels, and uploads to App Store Connect — all from one command. Source data is the session-40 demo dataset, rendered by `flutter drive` against in-memory fakes (no backend, no real account needed).

### One-time setup

Requires Ruby ≥ 3.0 and Xcode with the iPhone 16 Pro Max / iPhone 15 Plus / iPad Pro 13" (M4) simulators installed.

```bash
bundle install
xcrun simctl list devices | grep -E "iPhone 16 Pro Max|iPhone 15 Plus|iPad Pro 13"
```

For uploads, generate an [App Store Connect API key](https://appstoreconnect.apple.com/access/integrations/api) (`Developer` access is enough), save the `.p8`, and export three env vars:

```bash
export APP_STORE_CONNECT_API_KEY_KEY_ID="<10-char key id>"
export APP_STORE_CONNECT_API_KEY_ISSUER_ID="<uuid>"
export APP_STORE_CONNECT_API_KEY_KEY_FILEPATH="$HOME/.appstoreconnect/AuthKey_<id>.p8"
```

### Commands

```bash
# Capture only — writes PNGs to fastlane/screenshots/en-US/<device>/
bundle exec fastlane ios screenshots
bundle exec fastlane mac screenshots

# Frame the captured PNGs with device bezels + captions (Framefile.json)
bundle exec fastlane ios frame
bundle exec fastlane mac frame

# Upload framed PNGs to App Store Connect (no binary, no metadata)
bundle exec fastlane ios upload          # dry-run by default
DELIVER_FORCE=true DELIVER_OVERWRITE_SCREENSHOTS=true \
  bundle exec fastlane ios upload        # actually replace existing PNGs

# All three in one pass
bundle exec fastlane ios ship
bundle exec fastlane mac ship
```

### What gets captured

Five hero screens, in this order, with filenames `01_perform.png` … `05_repertoire.png`:

| # | Screen | Source |
|---|---|---|
| 1 | Perform tab | `PerformScreen` |
| 2 | Session detail (session 40) | `PerformanceDetailScreen` |
| 3 | Project stats | `ProjectStatsScreen` |
| 4 | Setlists | `SetlistsListScreen` |
| 5 | Repertoire | `RepertoireListScreen` |

The render harness lives at `integration_test/screenshots/screenshot_harness.dart`. Seed data + caption strings are checked in; the captured/framed PNGs are gitignored.

### CI

The `.github/workflows/screenshots.yml` workflow runs on `workflow_dispatch` only. Trigger it from the Actions tab with `platform: ios` or `mac` and `upload: false` to produce a downloadable artifact for review, or `upload: true` to push to ASC. Uses the `APP_STORE_CONNECT_API_KEY_KEY_ID`, `APP_STORE_CONNECT_API_KEY_ISSUER_ID`, and `APP_STORE_CONNECT_API_KEY_BASE64` repo secrets.