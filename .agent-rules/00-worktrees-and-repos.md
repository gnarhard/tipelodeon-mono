# Worktrees And Repos

## Repo map

- `songtipper/web/`: Laravel API plus web routes for audience pages and performer signup/login
- `songtipper/mobile_app/`: Flutter performer app
- `songtipper/_shared/`: shared contracts, schemas, and docs; source of truth for cross-stack behavior

## Repository remotes

- Monorepo: `git@github.com:gnarhard/songtipper-mono.git`
- Web: `git@github.com:gnarhard/songtipper_web.git`
- Mobile App: `git@github.com:gnarhard/songtipper_mobile_app.git`

## Mandatory worktree rules

- Work in a git worktree, never in the main repository.
- Create task worktrees under `../songtipper-worktrees/<track_id>/`.
- `cd` into the worktree before doing any work.
- If worktree creation fails, output `ERROR` and stop. Do not fall back to the main repo.
- Keep the main `songtipper` directory untouched by agents.
- This root docs worktree may contain only `_shared/` and other shared docs. Do not assume `web/` or `mobile_app/` exist here.
- If the task requires app code, create and use separate worktrees for `web/` and `mobile_app/` as needed.
- When finished, create a PR for each repo you modified and share the PR link with the developer.

## Ignore

- `web/.env`
- `**/*.p8`

## Repo-specific rules

- Before editing `web`, read `web/AGENTS.md` in the web worktree.
- Before editing `mobile_app`, read `mobile_app/.agent-rules/*.md` in the mobile worktree.
- In the web repo, AI-specific skill directories may exist under `web/.claude`, `web/.gemini`, `web/.codex`, and `web/.github`.
