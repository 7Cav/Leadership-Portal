# `Support/Time/` — the canonical Cav week (AD-11)

`WeekOf::start($moment)` is the **only** code in the portal that maps a time to a
`week_start`: the Saturday 00:00 UTC that opens the Cav week, which runs from that instant up
to (not including) the next Saturday 00:00 UTC. Input in any zone is normalized to UTC first; there is no per-unit timezone. Never compute a
week boundary inline (`startOfWeek`, "previous Saturday", …) — call this. A best-effort unit test
guards it by scanning `app/` and `database/` for week-boundary idioms.

**Reached from** `Http/Controllers/`, `Commands/`, `Repositories/` and `Kpi/` (rollcall week
selection, the practice-row writer, attendance bucketing, the KPI window).

**May depend on:** nothing in `app/` — it is a pure function over Carbon, below the layer graph.
Display formatting (forum convention `30May26`) is a frontend concern
(`resources/js/lib/forumDate.js`), not this layer's.
