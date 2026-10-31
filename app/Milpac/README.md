# `Milpac/` — what the Epic 6 pages show: the milpac and the readiness matrix

`TrooperMilpac::for($trooper, $scope)` returns the props of the one `TrooperDetail` page, whichever way a
leader reached the trooper:

- `trooper`: the ID block from the cached Cav roster (rank, billet, MOS, time in service and in grade, primary
  section). A trooper the sync could not place has `section: null`, shown as unmapped; one who has left every
  roster still renders, as departed, from their last cached identity.
- `courses`: the trooper's `trooper_courses` grouped as qualified, in progress and required, each group in the
  catalog's order. `none` rows and absent rows are the same thing and are not listed, and an empty group is not
  returned.
- `awards`: the earned-awards history, read-only (AD-5, FR17: no rec workflow). The roster sync stores no awards
  today and AD-3 rules out a new read for them, so `available` is false with no `items`, and the page says awards are
  not synced from the Cav roster yet (and links to the Cav milpac) instead of "none". When the roster cache holds
  awards, they are returned as `{name, awardedAt, citation}` with `available` true.
- `timeline`: the service record, newest first, each entry `{type, date, title, detail}` with a legend of the types
  present; built by `ServiceTimeline` from `TimelineEvent`s of a `TimelineEventType`. Today that is the current rank
  and its date in grade (the roster knows only the last promotion; none when the grade date is the join date or in the
  future, or the rank or date is unknown), earned awards and qualified courses that have an award date. A course with
  no date is left to the courses panel, and operations and discipline have no source yet. Two events on one day order
  by type (the enum's case order) and then by row, so a reload never reshuffles them.
- `actions`: the leadership quick actions, `{key, label, href}`, derived from the viewer's scope (the controller passes the
  ScopeResolver's unit ids in): open the section roster and open the section in rollcall, only when the scope holds the
  trooper's section. Hiding is cosmetic (AD-6): every target authorizes its own request. Nothing here changes a
  trooper. The platoon readiness matrix (6.9) is offered too, but only when the scope holds the platoon above the section.
- `makeup`: `{username, missedWeeks}` for the makeup PM, or null. The controller passes in the weeks the trooper
  missed in the KPI window (from `KpiService`); it is offered on the same scope terms as the section actions, and only
  for a trooper with a forum username who missed a practice. The page builds a link to the forum's own "start
  conversation" form from it; the portal never writes to the forum.
- `sync`: where the ID block came from and how current it is (AD-20), from `RosterProvenance` below.

Durations count on the server's UTC day (AD-11).

**May depend on:** `Repositories/` (never the DB directly, AD-2) and `Models/`.
**Reached from:** `Http/Controllers/TrooperDetailController` (the milpac), `TrainingReadinessMatrixController` (the
matrix, below), and the Home, roster, rollcall and report controllers (roster provenance, below). Read-only. It never decides who may look: the
controller gates every request with the ScopeResolver (AD-8) before asking.

## Roster provenance (AD-20)

`RosterProvenance::current()` returns a `RosterSyncState`: the one answer to "where does the roster come from and
how fresh is it", read by Home, the roster, the milpac, rollcall and the weekly report so no page judges it its own
way. The rule is the LOA one (`Loa\LoaProvenance`): the time shown is the last **successful** roster sync, and the
cache is `stale` when the latest finished run did not succeed (`failure` or `partial`) or the last good sync is older
than `STALE_AFTER_HOURS` (6). The source label is `milpac`.

- `toArray()` is the roster and milpac shape: `{source, lastSuccessAt, stale, lastAttempt}`. `lastAttempt` names the
  latest run only when it did not succeed, so a cache stale by age alone has none and the page says "overdue".
- `state()` is Home's one word: `failed`, `never`, `partial`, `stale` (overdue) or `ok`.
- Rollcall and the report show `source` and `lastSyncedAt` only.

Read-only over `sync_runs`; `Sync/SyncRosterHandler` is the one writer.

## Billet tags (#443)

`BilletTags::of($troopers)` returns, keyed by trooper id, the tag the roster, its Sheet and the readiness matrix print
next to a leader: the short name of the command billet they hold in the unit they are placed in (`Models\CommandRole`:
CO, XO and 1SG on a company; PL and PSG on a platoon; SL and ASL on a section; CO, XO and SGM on a battalion; CO, XO
and CSM at the regiment), or `Acting PL` and the like while an Admin's acting lead leads that unit. Everyone else has
no tag. Display only: scope reads the billet's commanded unit, never the tag.

## The readiness matrix (Story 6.9)

`ReadinessMatrix::for($unit)` returns the props of the `TrainingReadinessMatrix` page for one unit:

- `troopers`: the active troopers (Combat, Reserve and ELOA, never Inactive) placed anywhere in the unit's subtree,
  each with a status per course and a `role`, the billet tag from `BilletTags` above, the same rule the roster
  uses, so the page lists the leader first without guessing from billet text. A trooper with no row for a
  course reads `none`, the same as a stored `none`. Rows arrive in id order; the page orders them (the roster's
  `compareBillet`: role, then rank, then name).
- `courses`: the whole catalog in its one order (`sort_order`, then `code`). Every course is a column even when
  nobody holds or needs it, so a bottleneck cannot disappear.
- `coverage`: per course, qualified over every trooper shown, as a whole percent. A plain ratio, not a canonical
  KPI, so it does not go through the KPI service (AD-21). `null` when the unit has no troopers, never 0 of 0.

The controller gates the unit with the ScopeResolver (AD-8): a unit outside the viewer's effective scope is a 403
whatever link led there, and view-as narrows it (AD-9). The milpac's quick action (6.7) links to the trooper's
platoon; that link is convenience, this gate is the boundary. Reads are constant in the number of troopers and
courses.

## Counseling (#381, #565)

`CounselingHistory::for($trooper, $readsAny, $sees, $counts, $can, $files)` returns the milpac's `counseling` prop: the
DCSs and LORs the viewer may see, drafts first, then newest first (each `CounselingRecordView::of()`, plus
`kindAssumed`, `document` for a source PDF, `flags` and `can`), the `warnings` banner (no favorable action, for 30 days
after the latest LOR) and, for each type in `$files` and each kind, the `forecast` the start form reads.
`CounselingRecordView` is one record as the pages show it: where it stands in the wiki procedure (`state`) and every
deadline that runs on it, worked out from the record on the read, never stored.

`CounselingStanding` holds the rules (2026-10-28, after the 7Cav wiki), pure and unit-tested: each record is weighed
against the records dated before it, on the ladder it is for (`CounselingKind`: a missed section practice or rollcall,
or other conduct; no kind is ruled as other conduct), so a flag stays with a record whatever today is. The forecast is
a list of date ranges, each with the flags a new record dated in it would earn, so the form never works the rules out
itself. Who sees which type and who may change a record come in from the controller
(`Authorization\CounselingAccess`), and a type the viewer may not see is never read into the result, so neither the
records nor the flags can give it away. The controller adds `sees`, `canFile` and `departed` (the trooper has left the
Cav: read-only for everyone).
