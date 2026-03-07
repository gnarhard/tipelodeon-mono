# Song Tipper Monorepo

Song Tipper is a performer-focused app to manage songs, setlists, charts and live audience requests.

- Purpose: Manage repertoire (songs, metadata, tags), create setlists, store charts (PDFs/images), and handle audience song requests / live queue syncing.
- Architecture: Backend API + web UI in backend/, performer mobile app in frontend/ (Flutter), and shared API/contracts in shared/. - Key features: Repertoire & metadata, setlist building, chart/file uploads, audience request queue, project-scoped auth, offline-first mobile behavior.
- Data & contracts: Shared API schema / rules live in shared/ (source of truth for backend ↔ mobile).
- Auth & scope: Token-based authentication with authorization scoped to a Project/band.
- Scale & imports: Designed to support bulk imports (see database/seeders), and integrates with external metadata/enrichment as needed.

Song Tipper is split into multiple repositories:

- `songtipper/` (this repo): shared files, tooling, and top-level coordination.
- `songtipper/web/`: Laravel API + marketing site.
- `songtipper/mobile_app/`: Flutter mobile app.

`web/` and `mobile_app/` are intentionally independent Git repositories and are ignored by this root repo.

## Repository remotes

- Monorepo: `git@github.com:gnarhard/songtipper.git`
- Web: `git@github.com:gnarhard/songtipper_web.git`
- Mobile App: `git@github.com:gnarhard/songtipper_mobile_app.git`

## Prerequisites

- Git with SSH access to GitHub
- PHP 8.4+, Composer, Node.js, npm
- MySQL and Redis (for `web/`)
- Flutter 3.41+, Dart 3.10.7+, Xcode, CocoaPods, Android Studio (for `mobile_app/`)

## First-time setup

1. Clone the monorepo.
2. Clone each child repository into its fixed directory name.
3. Initialize game submodules.
4. Install and configure web dependencies.
5. Install and configure game dependencies.

```bash
git clone git@github.com:gnarhard/songtipper-mono.git
cd songtipper-mono

git clone git@github.com:gnarhard/songtipper_web.git web
git clone git@github.com:gnarhard/songtipper_mobile_app.git mobile_app
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

## Stripe Integration

Stripe is used in two ways:

1. Standard Stripe subscription products for Basic and Pro plans. 30 days free creates a "payment intent."
2. Connect Express. Musicians sign up for a Connect Express account to take tips from audience members. Song Tipper is setup as a platform, not a marketplace, meaning money will not flow through Song Tipper before it reaches the musician's account. Song Tipper takes no fees nor charges any usage fees.

If you see "Unable to initialize payment right now. Please try again." This means the user who signed up for their account in Stripe Connect Express signed up in the sandbox instead of the live account (or vice versa).