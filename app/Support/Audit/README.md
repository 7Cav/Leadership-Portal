# `Support/Audit/` — the audited-action envelope (AD-16)

The one path every state-changing Command routes through, recording actor, action,
target, reason, content digest, and timestamp into `audit_log`. This prevents
ad-hoc per-Command logging from producing an inconsistent, un-queryable audit trail.

**Reached from** `Commands/` (the `CommandHandler → AuditEnvelope` edge is already
in the graph, so 1.10's envelope is reachable without a graph change). Template
edits, org-structure edits, and role grants may **not** bypass this envelope.
