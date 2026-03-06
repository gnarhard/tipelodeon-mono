# Conventions And Delivery

## Naming

- Web JSON uses `snake_case`.
- Mobile models use `camelCase` and map from `snake_case`.

## Dates

- Use ISO 8601 strings with timezone, for example `2026-02-15T18:00:00+00:00`.
- Always store and return UTC on the server; the client converts to local timezone.
- Document timezone handling in `_shared/`.

## Pagination

Use the standard Laravel paginator shape unless the API already defines something else:

```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 50,
    "total": 123
  },
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  }
}
```

## Idempotency

- All write operations support `Idempotency-Key`.
- The mobile app outbox must send stable keys per logical operation.
- Keys are UUID v4.
- The server deduplicates by key within a 24-hour window.
- Retries with the same key return the original `200` or `201` response.

## Metadata fields

### Mood (v1.2)

- Global: `songs.mood` (nullable)
- Project override: `project_songs.mood` (nullable)
- Effective value: `project_songs.mood ?? songs.mood`
- Validation: `^[a-zA-Z0-9_-]+$`
- Example values: `party`, `chill`, `romantic`
- Supported in repertoire list, create, update, and bulk import

### Energy level

- Allowed values: `low`, `medium`, `high`
- Uses the same global-plus-project-override pattern as mood

### Genre

- Free text, max 50 characters
- Uses the same global-plus-project-override pattern as mood

## Final summary format

When you finish a task, summarize with:

- `What changed (web)`
- `What changed (mobile app)`
- `Contract updates (shared)`
- `How to test`
- `Rollout / migration notes`

## Pull request template policy

- Web PR template: `web/.github/pull_request_template.md`
- Mobile PR template: `mobile_app/.github/pull_request_template.md`
- PR creation is required after every completed task that changes a repo. Do not wait for the user to ask for it.
- Do not push, open, or update any GitHub branch or PR unless every test suite in each repo being submitted is passing with zero errors, including unrelated or pre-existing failures.
- If any PR you open shows merge conflicts, fix them before handoff using your best judgment and rerun any validation affected by the conflict resolution.

Every PR body must include:

- `Why`
- `Scope`
- `What Changed`
- `Contract / API Impact`
- `Breaking Changes`
- `Validation` with exact commands run
- `Risk / Rollback`
- `Checklist`

If a template is missing in a repo, add it first and then open the feature PR with that template.

## If you are unsure

- Do not guess silently. Inspect the codebase first.
- If ambiguity remains, make the smallest safe change.
- Add a note in `_shared/` describing the open question.
- Suggest the next best verification step.

## Finish the job completely

Do the needed follow-through work when you can. If a migration, validation step, or other task is part of finishing the change, do it instead of handing it back unfinished.
