# `Support/Time/` — the canonical Cav week (AD-11)

`WeekOf::start($moment)` is the **only** code in the portal that maps a time to a
`week_start`: the Saturday 00:00 UTC that opens the Cav week, which runs from that instant up
to (not including) the next Saturday 00:00 UTC. Input in any zone is normalized to UTC first; there is no per-unit timezone. A caller *handed* a `week_start` validates it with
`WeekOf::requireStart()`, which refuses anything but a boundary. Never compute a
week boundary inline (`startOfWeek`, "previous Saturday", …) — call this. A best-effort unit test
guards it by scanning `app/` and `database/` for week-boundary idioms.

**Reached from** `Http/Controllers/`, `Commands/`, `Repositories/`, `Kpi/` and `Loa/` (rollcall week
selection, the practice-row writer, attendance bucketing, the KPI window).

**May depend on:** nothing in `app/` — it is a pure function over Carbon, below the layer graph.
Display formatting (forum convention `30May26`) is a frontend concern
(`resources/js/lib/forumDate.js`), not this layer's — with one exception: `WeekLabel::ending()` gives the label a user
reads for a week in server-side text (refusals, audit log labels, the staff report Sheet row), its end date `30Oct26` (#568), so that text and the
UI name a week the same way. It derives the week through `WeekOf::start()` and never keys anything.
