# `Support/Audit/` — the audited-action envelope (AD-16)

The one path every state-changing Command routes through, recording actor, action,
target, reason, content digest, and timestamp into `audit_log`. This prevents
ad-hoc per-Command logging from producing an inconsistent, un-queryable audit trail.

**Reached from** `Commands/` (`CommandHandler → Support\Audit`). Template edits,
org-structure edits, and role grants may **not** bypass this envelope.

**May depend on:** `Repositories/` — specifically `AuditLogRepository`, the sole writer of
`audit_log` (AD-2). `AuditEnvelope::record()` opens the mutation-plus-log transaction via
that Repository and rejects reason-required actions with no reason before the mutation ever
runs.
