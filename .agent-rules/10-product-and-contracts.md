# Product And Contracts

## Prime directive

- Keep `web`, `mobile_app`, and `_shared` in sync. Any change to API shape, auth, error format, pagination, sorting, filtering, or field naming must update `_shared` and the corresponding Dart models and services.
- During this development cycle, do not implement backwards-compatible changes unless explicitly requested. Prefer direct breaking changes and update both stacks plus `_shared` together.

## Product scope

SongTipper helps performers manage:

- Repertoire with keys, capo, tuning, energy, mood, era, and genre metadata
- To-learn songs with YouTube and Ultimate Guitar links
- Setlists, notes, and smart generation
- Performance sessions with completion tracking and smart reordering
- Charts with PDF uploads, rendering, annotations, and page viewport preferences
- Audience requests with tips and Stripe integration
- Audience profiles, achievements, and live leaderboards

Web handles authenticated APIs, persistence, uploads, and audience-facing routes. Mobile is the performer app and should remain offline-friendly where possible.

## Shared contract source of truth

- Primary contract: `_shared/api/openapi.yaml` (OpenAPI 3.0, v1.2)

Supporting docs:

- `_shared/contracts/auth.md`
- `_shared/contracts/projects.md`
- `_shared/contracts/repertoire.md`
- `_shared/contracts/queue.md`
- `_shared/contracts/charts.md`
- `_shared/contracts/setlists.md`
- `_shared/contracts/public.md`
- `_shared/api-contract-rules.md`
- `_shared/contracts/README.md`

## Required contract rules

- Define request and response bodies, status codes, error format, pagination model, and auth requirements.
- Use `snake_case` in Laravel JSON and map to `camelCase` in Dart serializers.
- Document nullable fields explicitly, including default behavior.
- Removing or renaming fields without a migration plan is allowed in this cycle only when `web`, `mobile_app`, and `_shared` are updated together in the same task.
- All write endpoints must support the `Idempotency-Key` header for safe retries.

## Canonical API response shape

Unless an existing format already exists, use:

Success:

```json
{
  "data": "...",
  "meta": {}
}
```

Error:

```json
{
  "error": {
    "code": "string_machine_code",
    "message": "human_readable_message",
    "details": {
      "field": ["msg1", "msg2"]
    }
  }
}
```

If the backend already uses a different structure, follow the existing standard and document it in `_shared/`.

## Authentication and authorization assumptions

- Assume performer users authenticate to backend APIs with tokens.
- Authorization is typically scoped to a project.
- Any endpoint that reads or writes performer data must enforce project scoping.

If auth details are unclear, inspect:

- `web/routes/api.php`
- `web/app/Http/Middleware`
- `web/config/auth.php`
- `mobile_app/lib/**/auth*`
- `mobile_app/lib/**/token*`

Then document the confirmed behavior in `_shared/`.

## Offline-first expectations

- Cache read models locally when the app already has a storage pattern for it.
- Queue writes where that behavior already exists or is clearly intended.
- Never leave the UI blocked on network calls; show cached state and retry affordances.

Before introducing a new caching approach, inspect existing patterns in:

- `mobile_app/lib/services`
- `mobile_app/lib/repositories`
- `mobile_app/lib/storage`
- files matching `*cache*`, `*hive*`, or `*offline*`

## Cross-stack safety checks

Before changing any API:

- Search frontend usage for the endpoint or model.
- Ensure the response shape matches what Dart expects.
- If validation rules change, ensure the frontend form handling changes with it.

Before changing any Flutter model:

- Confirm the backend sends those fields.
- If you add fields, make sure the backend includes them and document them in `_shared/`.
