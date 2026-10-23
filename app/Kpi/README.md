# `Kpi/` — the one shared KPI service (AD-21)

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

An automatic makeup (capture saw the trooper at another section's practice, 2026-10-23) is recorded on the
trooper's own section's practice and credits their SP like a makeup marked by hand. It does not make that practice
held for the rest of the section, so a section that did not practise is not shown as having held SP; the week still
counts as eligible and attended for the trooper who made it up (a week a trooper attended is always eligible for them).

`trooperAttendance()` takes an optional window (`$windowWeeks`, default `WINDOW_WEEKS`) so a caller that needs the
same definitions over a longer span reads them here instead of re-deriving SP. Only `PracticeGuests` uses it.

`PracticeGuests` (decided 2026-10-23) is the roster's "Guests at our practices" read model, the Joint Service
Achievement Medal evidence: the serving troopers from outside a unit recorded at its sections' practices
(`cross_section_sightings`, capture or a host leader's hand entry, one visit per practice) over the last
`portal.jsam.window_weeks` Cav weeks, with their visits, the last week seen and their own SP % over the same window
from `trooperAttendance()` for their own section. `JsamCriteria` marks a candidate: visits at least
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
