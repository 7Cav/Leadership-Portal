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

`AuditEnvelope::recordBatch()` is the same envelope for one action that writes many
rows (e.g. a week's attendance marks, Story 3.3): the mutation returns an
`App\Repositories\AuditedBatch`, and the batch logs once against its target with a digest
over every row it wrote.

## Acting for someone (#379)

While an Admin, or a leader acting as a billet holder below them (#432), acts as another person, each request runs as
that person, so Commands pass the person's id as actor. `ActingFor` (set per request by the `ActAsUser` middleware)
turns that into the real user as `actor_id` and the person as `on_behalf_of_id`. Anything else written during the
request (their own `act_as.started` and `act_as.stopped`) is recorded as is.

`ActingFor` is scoped, so it is gone by the time a queued job runs in a worker. Work queued while acting carries it
along: `ActingFor::snapshot()` is taken when the work is queued (an `ActingSnapshot` on the queued command) and the
handler holds it again with `restore()` while it runs, putting back what was held before once it is done. The capture
"Try again" does this, so `practice.captured` and `attendance.auto_marked` name the real user on the person's behalf
(or in the billet's place). The unit roster sync writes no audit entry, so it only needs `sync_runs.triggered_by` to
name the real user, which the controller passes in.
