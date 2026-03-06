# AGENTS.md — SongTipper

This file is intentionally short. Load only the rule files relevant to the task.

Always read first:

- `.agent-rules/00-worktrees-and-repos.md`

Read as needed:

- `.agent-rules/10-product-and-contracts.md` for product scope, shared-contract rules, auth assumptions, offline expectations, and cross-stack safety checks.
- `.agent-rules/20-execution-and-validation.md` for the working process, validation steps, commands, and file layout hints.
- `.agent-rules/30-conventions-and-delivery.md` for JSON/date/pagination/idempotency conventions, PR requirements, and delivery expectations.
- `.agent-rules/40-feature-reference-v1.2.md` for the current feature and endpoint capability reference.

If you need to edit app code, also read the repo-specific rules in the corresponding app worktree:

- `web/AGENTS.md`
- `mobile_app/.agent-rules/*.md`

Notes:

- This root worktree may contain only shared docs and `_shared/` files. Do not assume `web/` or `mobile_app/` exist here.
- Keep `web`, `mobile_app`, and `_shared` in sync for any API or contract change.
- Do not add backwards-compatible shims unless explicitly requested.
- Open a PR for every repo you modify before handoff, even if the user did not explicitly ask for PR creation.
- If any PR you open has merge conflicts, resolve them using your best judgment before handoff.
