# `Loa/` — read-only LOA logic (Epic 4)

Everything the portal needs to *read* about leave lives here, so no screen re-derives it.

- `LoaProvenance::current()` → `LoaSyncState` (4.3, AD-20): the source label every LOA value is
  shown with (`Forum thread`) and the time of the last **successful** LOA sync. `stale` is set
  when the latest finished run did not succeed, so the page says it is showing the last good
  cache (AD-4). With no successful sync yet, `neverSynced()` is true and `lastSyncedAt` is null:
  an explicit state, never a blank or an invented time.
- `LoaCoverage::forTroopers($ids, $weekStart)` → `array<trooperId, LoaCover>` (4.4, AD-21): the one
  definition of "is this trooper LOA-covered in this Cav week". Whole Cav weeks (Saturday to
  Friday UTC, `WeekOf`): a leave touching any day covers the week, and an open-ended leave
  (`ends_at` null) covers every week from its start. One query however many troopers.
  `LoaCover::state` is `Covered`, `NeedsReview` or `None`:
  - `Covered` excuses the absence and carries the dates.
  - `NeedsReview` is the fail-safe (AD-4). It suppresses an at-risk or AWOL attribution
    (`suppressesAtRisk()`) but is neither "covered" nor "absent", and carries no dates. The parser
    keeps none for such a row, so it applies from the week the row was first cached until the post
    is fixed and the next sync reads it cleanly.
  - A cleanly read leave that covers the week wins over one that needs review.
  - `toArray()` is the badge's prop shape.

- `LoaCover::derivedAttendanceStatus()` (3.4, AD-14): the attendance status a cover derives for the week, `Loa`
  when a cleanly read leave covers it and nothing otherwise. A needs-review leave derives none, so it never reads as
  an absence nor as away. The status is derived here and never stored; `MarkAttendanceHandler` reads it through
  `LoaCoverage` to refuse a hand-entered practice mark for a covered trooper.

**May depend on:** `Repositories/` (the facts, never the DB directly, AD-2), `Models/`,
`Support/Time/` (the week window, AD-11).
**Reached from:** `Http/Controllers/`, `Kpi/` and `Commands/Attendance/MarkAttendanceHandler`. Read-only: the LOA sync (`Sync/SyncLoasHandler`) is the one
writer of `loas` and `sync_runs`.

`forTroopersOverWeeks($ids, $firstWeek, $weeks)` resolves a window the same way, in one query. The KPI service
(`Kpi/KpiService`, 4.8) reads its trailing four weeks from it for `on_loa`, the LOA-excluded denominators
and at-risk, so the badge and the KPIs share one definition.
