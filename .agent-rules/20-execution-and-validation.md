# Execution And Validation

## Workflow

1. Locate the source of truth in `_shared/`.
2. Inspect both stacks when the task touches shared behavior.
3. Plan the change. Do not keep backwards compatibility unless explicitly requested.
4. Implement backend, frontend, and shared contract changes together when required.
5. Add or update unit tests and feature or integration tests for every behavior you modify.
6. Run the full test suite and required validation commands for every repo you plan to submit to GitHub, not just the tests related to your change.
7. If any test fails, even for a pre-existing or unrelated reason, fix it before submission or stop and report that GitHub submission is blocked.
8. Summarize the change, including rollout or migration notes when applicable.

## Always

- Prefer small, reviewable diffs.
- Do not add dependencies unless they are necessary.
- Do not reformat entire files unless the formatter requires it.
- Never exceed database table name length limits; avoid MySQL `1059 Identifier name` failures when planning migrations.
- Do not push branches, open PRs, or update PRs while any test in a repo you intend to submit is failing. GitHub submission is blocked until the repo is error-free.

## Common commands

Run these from the relevant repo root.
These are the minimum baseline commands. Before GitHub submission, every test suite for each changed repo must be green.

### Backend (`songtipper/web`)

```bash
php -v
composer install
php artisan key:generate
php artisan migrate
php artisan test
./vendor/bin/pint
```

### Mobile (`songtipper/mobile_app`)

```bash
flutter --version
flutter pub get
flutter test
dart format .
dart analyze
```

If a build is needed:

```bash
flutter build apk
flutter build ios
```

If commands fail because of missing env, missing services, or platform constraints, report the failure and pivot to static checks plus code reasoning.

## File layout hints

### Web

- Routes: `web/routes/api.php`, `web/routes/web.php`
- Controllers: `web/app/Http/Controllers`
- Form requests: `web/app/Http/Requests`
- Resources: `web/app/Http/Resources`
- Models: `web/app/Models`
- Migrations: `web/database/migrations`
- Policies: `web/app/Policies`

### Mobile App

- Entry: `mobile_app/lib/main.dart`
- API client: `mobile_app/lib/**/api*` or `mobile_app/lib/**/http*`
- Models: `mobile_app/lib/models` or nearby feature folders
- Repositories: `mobile_app/lib/repositories`
- State: `mobile_app/lib/**/notifier*` or `mobile_app/lib/**/provider*`
- Storage or offline: `mobile_app/lib/**/storage*`, `mobile_app/lib/**/cache*`

### Shared

- OpenAPI spec: `_shared/api/openapi.yaml`
- Contracts: `_shared/contracts/*.md`
- Rules: `_shared/api-contract-rules.md`
- Architecture: `_shared/ARCHITECTURE.md`
- Shared overview: `_shared/README.md`
