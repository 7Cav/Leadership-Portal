# `Commands/` — write-side business logic (AD-1, AD-15, AD-16)

Command + `CommandHandler` pairs. This is the **only** place write-side business
logic lives. A controller never mutates state directly — it dispatches a Command.

**May depend on:** `Repositories/`, `Models/`, `Integration/`, `Support\Audit`,
`Authorization/`, `Support\Time`, `Kpi/`, `Loa/` and `Rollcall/` (the `CommandHandler` rule in
`deptrac.yaml` is the source of truth). Under the slim, Laravel-idiomatic layer model there is
no `Services/` facade; a CommandHandler calls the outbound seam directly
(`CommandHandler → Integration → External`, AD-3). The only other layer that may reach
`Integration/` is `Sync/`, for the one-writer cache syncs (AD-4). **Must not** touch the DB
directly — go through a Repository (AD-2).

Naming: Commands are imperative (`SendWeeklyReport`); handlers end in `…Handler`.
Every state-changing Command routes through the audited-action envelope (`Support/Audit/`,
AD-16) — personnel actions and report sends **require** a reason.
