# Theme Token Follow-up

The shared token values are constrained to the approved base and accent colors,
and the pinned wave colors remain unchanged.

Open question:
The `web/` and `mobile_app/` consumers were not present in this worktree, so
the token key shape was preserved for compatibility. When those repos are
checked out, audit their theme usage and remove any no-longer-needed shade-based
references before simplifying the token schema itself.
