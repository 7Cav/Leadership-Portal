# `Kpi/` — the one shared KPI service (AD-21)

**Who is measured (2026-10-24).** The KPI population is the serving troopers placed in a **section**
(`Trooper::assignedWithin`). HQ staff (placed on their platoon, company, battalion or the regiment) and everyone in a
group (staff departments, ELOA, Reservists, New Recruits) are on no rollcall, so they are not measured: no SP, no RC,
not in a report's strength. A non-leaf's live KPIs therefore stay the sum of its sections', and the report chain
(`ReportChain`, `RollupKpis`, `EchelonReportKpiSource`) walks line units only, so a group never holds a roll-up up. The
org page and roster show strength as the headcount instead: everyone serving placed under the unit, HQ staff and groups
included, New Recruits on their own group only.

**Headcount beside strength (#424).** `Headcount` is that count, defined once for the roster, Home and the report. Home's
tiles and a report's header show it labelled "Headcount", next to the measured figure now labelled "In rollcall". It is
never a KPI: no rate, at-risk flag or roll-up reads it, so the roll-up equality (a non-leaf's live KPIs equal the sum of
its children's sent snapshots) holds as before. A sent report keeps the headcount it was sent with, in the same
`weekly_reports.kpis` JSON (no column); one sent before this has none and shows none.

**Units with no troopers (#442, #478).** "Has troopers" is a headcount above zero, defined once in
`Headcount::withoutEmptyLineUnits()`. A line unit with nobody serving placed in it or under it is left off the roster's
ORBAT and picker and Home. The report chain asks for more, in `Headcount::reportingLineUnits()`: a section takes part
when someone was placed in it during the week (from the placement history; for the running week, also who is placed
now) or it sent the week's report, and a unit above sections when one of its line children takes part (or it sent). A
past week is never judged by who is placed now, so a section stood up since is not one it waits on. Home's sparkline
reads the same rule for each of its weeks at once (`reportingLineUnitsByWeek()`). A platoon whose only troopers are its HQ is not one of a roll-up's children
(`RollupKpis::children()`) and is not in `ReportChain`, though the roster shows it. A sent snapshot is never rewritten,
so a child frozen as missing stays missing on that report. The org page does not use either and lists every unit.

**Transfers (2026-10-27).** Each week of the window is read against the section the trooper was placed in that week,
from the placement history (`trooper_placements`, written by the roster sync and the Admin's manual placement). Their
marks at an old section still count after a move, the new section's practices from before they joined never do, and
RC counts only the weeks they were in a section. In the week of a move it is still one counting mark (AD-14): the
practice they attended, else the one they were marked at, else the later one. A unit's KPIs stay the sum of its current
members' rows, earlier weeks elsewhere included, so the roster and the totals never disagree. A trooper with no history
reads as placed in their current section for every week, as before. `AttendanceHistory` shows the same marks week by
week on the milpac, with each week's section and LOA.

`KpiService` is the **only** code that defines and computes the six canonical KPIs —
strength, RC % · 30d, SP % · 30d, at-risk, on LOA, makeups — for a unit's subtree and a Cav
week. The dashboard, the weekly report snapshot, the roll-up and Epic 4's at-risk flag all
read them from here; a feature that re-derives one (a "30-day" denominator, an at-risk
threshold) is the divergence AD-21 exists to prevent. The precise definitions are in the
`KpiService` docblock.

- `forUnit($unit, $weekStart)` → `UnitKpis`: the six KPIs, rates as `Ratio`
  (numerator + denominator) so a roll-up sums parts before dividing; a zero denominator is
  not-applicable (`value()` null → "—"), never 0%.
- `trooperAttendance($unit, $weekStart)` → `TrooperAttendance` per assigned trooper: the rows
  `forUnit()` sums, for a roster or at-risk flag to reuse instead of recomputing.
- `WeeklyReportKpis::snapshot($unit, $weekStart)` → `ReportSnapshot`: what a weekly report
  freezes on send (5.6) — the KPIs from the echelon's `ReportKpiSource` plus the `KpiDelta`
  against the unit's prior-week **sent** snapshot (null when that week has no sent report, 5.4). A sent report
  is read back with `ReportSnapshot::fromArray($report->kpis)`, never recomputed (AD-12).
  `ReportKpiSource` is the leaf/non-leaf seam, bound in `AppServiceProvider` to
  `EchelonReportKpiSource`: a section's live KPIs, or a non-leaf unit's `RollupKpis` sum with
  the children that had not sent frozen as `missing_children`. Rate deltas are integer basis
  points.

`RollupKpis` (5.7) is the non-leaf side (AD-13): `forUnit($unit, $weekStart)` →
`RollupResult`, the sum (`UnitKpis::plus()`) of the unit's **direct** children's **sent**
snapshots — never live attendance, never a grandchild's report — plus every child as a
`ChildReport` (`sent` | `draft` | `not_started`, KPIs only when sent). A child that has not sent
is excluded and named by `missingChildren()` (holding up the chain); `isComplete()` is the
pre-send check. Two queries however many children; a leaf unit is refused. 5.6's send command
freezes `RollupResult::$kpis` for a non-leaf unit, and 5.8's navigator renders `$children`.

`ReportChain` (5.8) is the navigator's read model: `build($root, $weekStart)` → a `ChainNode`
tree of every unit under `$root`, each with its report status, its KPIs (the frozen snapshot once
sent; otherwise live — a leaf from `KpiService::forSectionsWithin()`, a non-leaf from
`RollupKpis::fromReports()`) and the delta against last week's sent snapshot. Five queries
whatever the size of the chain; nothing in it is derived outside the pieces above.

A sent snapshot that does not read back is a corrupt record, never a zero. `RollupKpis` refuses it with
`ReportKpisUnavailableException::unreadableSnapshot()` (the same exception as a roll-up with no children or an
unreadable prior week), so the send is refused and the report screen says which child is unreadable.
`ReportChain` reads each sent snapshot once per build, reports a corrupt one once, and degrades only where it is
used: that unit's `ChainNode::$kpis` is null, an unsent parent above it has no roll-up and null KPIs, and an unsent
unit whose prior week is unreadable has no delta. Home and the navigator show such a unit as unavailable while the
rest of the chain renders.
**May depend on:** `Repositories/` (the facts — never the DB directly, AD-2), `Models/` (enums,
the `Unit` it is handed), `Support/Time/` (the week window, AD-11), `Loa/` (LOA coverage, 4.8).
**Reached from:** `Http/Controllers/` and `Commands/`. Read-only; it never writes. Callers pass
only units the ScopeResolver permits (AD-8) — the service does not re-check scope.

An automatic makeup (`source = auto`, 2026-10-23; written when a host leader adds the trooper as a guest, while the capture
does not write one until practice time slots exist, see `Rollcall/`) is recorded on the trooper's own section's practice and credits their SP like a makeup marked by hand. It does not make that practice
held for the rest of the section, so a section that did not practise is not shown as having held SP; the week still
counts as eligible and attended for the trooper who made it up (a week a trooper attended is always eligible for them).

`ownSectionPractice()` (decided 2026-10-23) is the JSAM "consistently attending their own" check, kept here so it is
one definition (AD-21): the SP above over a caller's window, for any set of troopers whatever their sections, with one
difference. A makeup (by hand or automatic) answers the weekly accountability, so it counts in the SP everywhere else,
but it is not attendance at the trooper's own section, so here it never counts as attended; a week their own section
held counts as eligible even when they made it up elsewhere, and a week their own section did not hold stays out, so
visits can never stand in for their own. LOA weeks leave it exactly as above. Only `PracticeGuests` uses it.

`PracticeGuests` (decided 2026-10-23) is the roster's "Guests at our practices" read model, the Joint Service
Achievement Medal evidence: the serving troopers from outside a unit recorded at its sections' practices
(`cross_section_sightings`, one visit per practice: a host leader's hand entry, or a game-server sighting recorded
before 2026-10-23; the capture records guests again once practices have set times) over the last
`portal.jsam.window_weeks` Cav weeks, with their visits, the last week seen and their own-section SP % over the same
window from `ownSectionPractice()`. `JsamCriteria` marks a candidate: visits at least
`portal.jsam.min_visits` and own SP at least `portal.jsam.min_own_sp_percent` (compared on the ratio's parts; an SP
with nothing eligible never qualifies). Visits count the viewed unit's practices only, so the number shown is the one
the badge is decided on. The portal never awards the medal.

LOA coverage is `Loa\LoaCoverage` (4.4), the one definition (4.8): for each week of the window it says
covered, needs review or none, whether or not a practice row exists. A covered week, and a week a
leave that needs review may apply to, leaves the window: no miss, out of both denominators. Only a
covered week counts as `on_loa`. A practice mark of `loa` also takes a week out, as a deliberate exception:
it is a recorded fact about that practice (leaders entered it by hand before 3.4; since then the rollcall refuses a status
for a trooper `LoaCoverage` covers, so no new one can be entered), so a week already marked is never held against the trooper even if the resolver later says
there is no leave. The two sources never disagree to the trooper's cost. A leave that needs review drops its
weeks even when the trooper has an explicit `missed` mark in them: that miss is ignored for those weeks (the
AD-4 fail-safe), so a trooper with a recorded miss and no at-risk flag may simply be in a shielded week. At-risk is still `missed >= 2` explicit
misses in the weeks that remain, so a trooper with few remaining weeks is never given misses they did not have.
