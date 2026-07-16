# `Commands/` — write-side business logic (AD-1, AD-15, AD-16)

Command + `CommandHandler` pairs. This is the **only** place write-side business
logic lives. A controller never mutates state directly — it dispatches a Command.

**May depend on:** `Repositories/`, `Support/Audit/` (the audited-action envelope),
and a `Services/` seam. **Must not** touch the DB directly (go through a Repository)
or reach an `Integration/` connector (go through a `Services/` seam).

Naming: Commands are imperative (`SendWeeklyReport`); handlers end in `…Handler`.
Every state-changing Command routes through the audited envelope (AD-16); personnel
actions and report sends **require** a reason.
