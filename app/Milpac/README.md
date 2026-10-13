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
- `sync`: where the ID block came from and how current it is (AD-20): the last successful roster sync, and a stale
  flag when a later run did not succeed.

Durations count on the server's UTC day (AD-11).

**May depend on:** `Repositories/` (never the DB directly, AD-2) and `Models/`.
**Reached from:** `Http/Controllers/TrooperDetailController` (the milpac) and `TrainingReadinessMatrixController` (the
matrix, below). Read-only. It never decides who may look: the
controller gates every request with the ScopeResolver (AD-8) before asking.

## The readiness matrix (Story 6.9)

`ReadinessMatrix::for($unit)` returns the props of the `TrainingReadinessMatrix` page for one unit:

- `troopers`: the active troopers (Combat, Reserve and ELOA, never Inactive) placed anywhere in the unit's subtree,
  each with a status per course and a `role` (`SL`, `ASL` or null) from `Trooper::roleUnder()`, the same rule the
  roster uses, so the page lists the leader first without guessing from billet text. A trooper with no row for a
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
