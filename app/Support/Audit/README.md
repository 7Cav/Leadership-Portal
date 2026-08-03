# `Support/Audit/` — the audited-action envelope (AD-16)

The one path every state-changing Command routes through, recording actor, action,
target, reason, content digest, and timestamp into `audit_log`. This prevents
ad-hoc per-Command logging from producing an inconsistent, un-queryable audit trail.

**Reached from** `Commands/`. Template edits, org-structure edits, and role grants
may **not** bypass this envelope.

> **Not yet an enforced layer.** This directory is a placeholder; the envelope and its
> `CommandHandler → Support/Audit` edge are added to `deptrac.yaml` when the story lands
> (Story 1.10).
