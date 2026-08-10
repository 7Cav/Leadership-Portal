# `Commands/` — write-side business logic (AD-1, AD-15, AD-16)

Command + `CommandHandler` pairs. This is the **only** place write-side business
logic lives. A controller never mutates state directly — it dispatches a Command.

**May depend on:** `Repositories/`, `Models/`, `Integration/`, and `Support\Audit`. This is
the **only** layer that may reach an `Integration/` connector — under the slim,
Laravel-idiomatic layer model there is no `Services/` facade; a CommandHandler calls the
outbound seam directly (`CommandHandler → Integration → External`, AD-3). **Must not** touch
the DB directly — go through a Repository (AD-2).

Naming: Commands are imperative (`SendWeeklyReport`); handlers end in `…Handler`.
Every state-changing Command routes through the audited-action envelope (`Support/Audit/`,
AD-16) — personnel actions and report sends **require** a reason.
