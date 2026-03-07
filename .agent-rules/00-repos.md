# Repos

## Repo map

- `songtipper/web/`: Laravel API plus web routes for audience pages and performer signup/login
- `songtipper/mobile_app/`: Flutter performer app
- `songtipper/_shared/`: shared contracts, schemas, and docs; source of truth for cross-stack behavior

## Repository remotes

- Monorepo: `git@github.com:gnarhard/songtipper-mono.git`
- Web: `git@github.com:gnarhard/songtipper_web.git`
- Mobile App: `git@github.com:gnarhard/songtipper_mobile_app.git`

When finished, create a PR for each repo you modified without waiting to be asked, but only after every test suite in each submitted repo is passing with zero errors, including unrelated failures. Resolve any merge conflicts in those PRs using your best judgment, and share every PR link with the developer. If the PR is closed, merged, and the branch is deleted, create a new PR.

## Ignore

- `web/.env`
- `**/*.p8`

## Repo-specific rules

- Before editing `web`, read `web/AGENTS.md`.
- Before editing `mobile_app`, read `mobile_app/AGENTS.md.
- If `mobile_app/AGENTS.md` is missing, read
  `mobile_app/.agent-rules/flutter.md`,
  `mobile_app/.agent-rules/presentation-boundaries.md`, and
  `mobile_app/.agent-rules/app-architecture.md`.
- In the web repo, AI-specific skill directories may exist under `web/.claude`, `web/.gemini`, `web/.codex`, and `web/.github`.
