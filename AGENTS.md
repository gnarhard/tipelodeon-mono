# AGENTS.md — SongTipper

This file is intentionally short. Load only the rule files relevant to the task.

Always read first:

- `.agent-rules/00-repos.md`

Read as needed:

- `.agent-rules/10-product-and-contracts.md` for product scope, shared-contract rules, auth assumptions, offline expectations, and cross-stack safety checks.
- `.agent-rules/20-execution-and-validation.md` for the working process, validation steps, commands, and file layout hints.
- `.agent-rules/30-conventions-and-delivery.md` for JSON/date/pagination/idempotency conventions, PR requirements, and delivery expectations.
- `.agent-rules/40-feature-reference-v1.2.md` for the current feature and endpoint capability reference.

If you need to edit app code, also read the repo-specific rules in the corresponding app worktree:

- `web/AGENTS.md`
- `mobile_app/AGENTS.md`
- If `mobile_app/AGENTS.md` is missing in that worktree, read:
  `mobile_app/.agent-rules/flutter.md`,
  `mobile_app/.agent-rules/presentation-boundaries.md`, and
  `mobile_app/.agent-rules/app-architecture.md`

Notes:

- This root worktree may contain only shared docs and `_shared/` files. Do not assume `web/` or `mobile_app/` exist here.
- Keep `web`, `mobile_app`, and `_shared` in sync for any API or contract change.
- For mobile work, repo-local architecture rules override generic Flutter
  advice. Do not rely on generic framework guidance alone.
- Do not add backwards-compatible shims unless explicitly requested.
- Open a PR for every repo you modify before handoff, even if the user did not explicitly ask for PR creation.
- If any PR you open has merge conflicts, resolve them using your best judgment before handoff.
- Before pushing, opening, or updating anything on GitHub, every test suite in each repo being submitted must be passing with zero errors, including failures unrelated to the current task.

## MCP Servers

- `mobile_app` development can be assisted with the globally installed Dart MCP server.
- `web` development can be assisted with the locally installed Laravel Boost MCP server available at `./web/.mcp.json`. See `./web/CLAUDE.md` for agent instructions and `./web/.claude/skills` for skills.