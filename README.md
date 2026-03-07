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

## Task worktree workflow

Run the helper from the main `songtipper/` checkout. It creates task worktrees in the ignored `./songtipper-worktrees/` directory there and replaces the old sibling `../songtipper-worktrees` convention.

```bash
repo_root="$(pwd -P)"
worktree_path="$(./scripts/create-worktree feature-name)"
cd "$worktree_path"
pwd  # Must resolve under /songtipper-worktrees/
```

If the helper prints `ERROR`, stop and fix that instead of working from the main checkout.

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
