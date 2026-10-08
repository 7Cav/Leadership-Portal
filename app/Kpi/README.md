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

**May depend on:** `Repositories/` (the facts — never the DB directly, AD-2), `Models/` (enums,
the `Unit` it is handed), `Support/Time/` (the week window, AD-11).
**Reached from:** `Http/Controllers/` and `Commands/`. Read-only; it never writes. Callers pass
only units the ScopeResolver permits (AD-8) — the service does not re-check scope.

LOA is not modelled yet (Epic 4): coverage is read from `attendance.status = loa` in one
predicate, `KpiService::isLoaCovered()` — the seam Epic 4 replaces for weeks with a practice
row. LOA in a week with no practice row also needs `forTrooper()`'s RC denominator and
`onLoa` derived from LOA week coverage; the predicate's docblock spells this out.
