# `Loa/` — read-only LOA logic (Epic 4)

Everything the portal needs to *read* about leave lives here, so no screen re-derives it.

- `LoaProvenance::current()` → `LoaSyncState` (4.3, AD-20): the source label every LOA value is
  shown with (`Forum thread`) and the time of the last **successful** LOA sync. `stale` is set
  when the latest finished run did not succeed, or when the last good sync is older than
  `STALE_AFTER_HOURS` (syncs that simply stopped must not look fresh), so the page says it is
  showing the last good cache (AD-4). With no successful sync yet, `neverSynced()` is true and
  `lastSyncedAt` is null: an explicit state, never a blank or an invented time.
  `backfilling` and `backfilledTo` say whether the sync's first read of the year is still going back through
  older posts (a share per hourly run, from the cursor the sync saves in `sync_runs.cursor`) and the oldest post
  it has reached. It is not a staleness signal: recent leave is read first, so only older weeks can miss leave,
  and the note says so in words.
- `LoaCoverage::forTroopers($ids, $weekStart)` → `array<trooperId, LoaCover>` (4.4, AD-21): the one
  definition of "is this trooper LOA-covered in this Cav week". Whole Cav weeks (Saturday to
  Friday UTC, `WeekOf`): a leave touching any day covers the week, and an open-ended leave
  (`ends_at` null) covers every week from its start. One query however many troopers.
  `overdue` and `endsInDays` are about *today*, so they are only set for the week that contains the
  reference day (`$today`, default now); a past week, as the heatmap asks, never reports them.
  `LoaCover::state` is `Covered`, `NeedsReview` or `None`:
  - `Covered` excuses the absence and carries the dates.
  - `NeedsReview` is the fail-safe (AD-4). It suppresses an at-risk or AWOL attribution
    (`suppressesAtRisk()`) but is neither "covered" nor "absent", and carries no dates. The parser
    keeps none for such a row, so it applies for the Cav week the thread was posted on the forum
    (`posted_at`) and the week after, then stops: a broken post cannot keep a trooper out of the
    KPIs for good, and the first sync's backfill of old malformed posts shields no one from that sync on. A row with
    no `posted_at` yet (stored before the column existed) is placed by `created_at` instead. When the
    post still gives usable leave dates (`review_starts_at`/`review_ends_at`: both read, end not before
    start, cut to 14 days), it applies to the weeks those days touch instead.
  - A cleanly read leave that covers the week wins over one that needs review.
  - A withdrawn row (`withdrawn_at` set: a complete full sync no longer found its thread, because it was
    deleted, hidden or moved out of the leave sections) counts for nothing. It is kept as history.
  - `toArray()` is the badge's prop shape.

- `LoaCover::derivedAttendanceStatus()` (3.4, AD-14): the attendance status a cover derives for the week, `Loa`
  when a cleanly read leave covers it and nothing otherwise. A needs-review leave derives none, so it never reads as
  an absence nor as away. The status is derived here and never stored; `MarkAttendanceHandler` reads it through
  `LoaCoverage` to refuse a hand-entered practice mark for a covered trooper, and to skip that trooper when
  capture pre-marks the practice (7.6), and to skip them when an automatic makeup is pre-marked for them.

**May depend on:** `Repositories/` (the facts, never the DB directly, AD-2), `Models/`,
`Support/Time/` (the week window, AD-11).
**Reached from:** the screens that show leave (the rollcall page, and Home's step matrix, which counts it), `Kpi/` and `Commands/Attendance/MarkAttendanceHandler`. Read-only: the LOA sync (`Sync/SyncLoasHandler`) is the one
writer of `loas` and of the `loa` rows in `sync_runs` (the roster sync and the practice capture write
their own `sync_runs` rows).

`forTroopersOverWeeks($ids, $firstWeek, $weeks)` resolves a window the same way, in one query. The KPI service
(`Kpi/KpiService`, 4.8) reads its trailing four weeks from it for `on_loa`, the LOA-excluded denominators
and at-risk, so the badge and the KPIs share one definition.
