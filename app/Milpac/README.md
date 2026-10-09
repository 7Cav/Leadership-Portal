# `Milpac/` — what the trooper detail page shows (Epic 6)

`TrooperMilpac::for($trooper, $scope)` returns the props of the one `TrooperDetail` page, whichever way a
leader reached the trooper:

- `trooper`: the ID block from the cached Cav roster (rank, billet, MOS, time in service and in grade, primary
  section). A trooper the sync could not place has `section: null`, shown as unmapped; one who has left every
  roster still renders, as departed, from their last cached identity.
- `courses`: the trooper's `trooper_courses` grouped as qualified, in progress and required, each group in the
  catalog's order. `none` rows and absent rows are the same thing and are not listed, and an empty group is not
  returned.
- `awards`: the earned-awards history, read-only (AD-5, FR17: no rec workflow). The roster sync stores no awards
  today and AD-3 rules out a new read for them, so `items` is empty and the page shows "No awards on record". When
  the roster cache holds awards, they are returned as `{name, awardedAt, citation}` and the page already draws them.
- `timeline`: the service record, newest first, each entry `{type, date, title, detail}` with a legend of the types
  present. Today that is the last promotion (the roster's date in grade and current rank; none when the grade date is
  the join date or in the future, or the rank or date is unknown), earned awards and qualified courses that have an award date. A
  course with no date is left to the courses panel, and operations and discipline have no source yet. Two events on
  one day order by type (promotion, award, course) and then by row, so a reload never reshuffles them.
- `actions`: the leadership quick actions, `{key, label, href}`, derived from the viewer's scope (the controller passes the
  ScopeResolver's unit ids in). Open section roster needs the trooper's section; open in rollcall needs the section too
  and its route; the platoon readiness matrix needs the platoon above the section. Each is shown only when the scope
  holds that unit and its route is registered, so nothing links to a 404 (rollcall arrives with 3.7, the matrix with
  6.9). Hiding is cosmetic (AD-6): every target authorizes its own request. Nothing here changes a trooper.
- `sync`: where the ID block came from and how current it is (AD-20): the last successful roster sync, and a stale
  flag when a later run did not succeed.

Durations count on the server's UTC day (AD-11).

**May depend on:** `Repositories/` (never the DB directly, AD-2) and `Models/`.
**Reached from:** `Http/Controllers/TrooperDetailController`. Read-only. It never decides who may look: the
controller gates every request with the ScopeResolver (AD-8) before asking.
