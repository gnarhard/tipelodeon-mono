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
- Run `./scripts/create-worktree <track_id>` from the main `songtipper` checkout only.
- The helper always creates the ignored path `./songtipper-worktrees/<track_id>` and branch `codex/<track_id>`.
- Do not manually create a sibling `../songtipper-worktrees` directory or hand-type `git worktree add`.
- Capture the absolute path printed by the helper, `cd` into it, and verify `pwd` is under `/songtipper-worktrees/` before doing any work.
- If worktree creation fails, output `ERROR` and stop. Do not fall back to the main repo.
- The only allowed agent-created directory in the main `songtipper` checkout is the ignored `songtipper-worktrees/` root.
- This root docs worktree may contain only `_shared/` and other shared docs. Do not assume `web/` or `mobile_app/` exist here.
- If the task requires app code, create and use separate worktrees for `web/` and `mobile_app/` as needed.
- When finished, create a PR for each repo you modified without waiting to be asked, but only after every test suite in each submitted repo is passing with zero errors, including unrelated failures. Resolve any merge conflicts in those PRs using your best judgment, and share every PR link with the developer.

## Ignore

- `web/.env`
- `**/*.p8`

## Repo-specific rules

- Before editing `web`, read `web/AGENTS.md` in the web worktree.
- Before editing `mobile_app`, read `mobile_app/AGENTS.md` in the mobile
  worktree.
- If `mobile_app/AGENTS.md` is missing, read
  `mobile_app/.agent-rules/flutter.md`,
  `mobile_app/.agent-rules/presentation-boundaries.md`, and
  `mobile_app/.agent-rules/app-architecture.md`.
- In the web repo, AI-specific skill directories may exist under `web/.claude`, `web/.gemini`, `web/.codex`, and `web/.github`.
