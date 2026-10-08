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

**May depend on:** `Repositories/` (the facts, never the DB directly, AD-2), `Models/`,
`Support/Time/` (the week window, AD-11).
**Reached from:** `Http/Controllers/`. Read-only: the LOA sync (`Sync/SyncLoasHandler`) is the one
writer of `loas` and `sync_runs`.

The KPI service's `on_loa` and LOA-excluded denominators still read `attendance.status = loa`
(`KpiService::isLoaCovered()`); Epic 5 swaps that predicate for `LoaCoverage` so both agree.
