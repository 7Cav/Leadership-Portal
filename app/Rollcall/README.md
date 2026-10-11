# `Rollcall/` — what the rollcall screen shows and renders from (Epic 3)

Read-only logic behind the weekly rollcall, so no screen re-derives it.

- `TemplateResolver::forUnit($unit)` → `ResolvedTemplate` (3.6, AD-17): which rollcall template version applies to a
  unit. It walks up the org tree to the owning company (the unit itself when it is a company) and returns that
  company's active version, with the version id so a render can record exactly which one it used. Every unit under one
  company resolves to the same version and a unit under another company never borrows it.
  `TemplateResolution` is the outcome: `resolved`, `no_active_template` (the company has not authored one yet) or
  `no_owning_company` (the unit sits above every company). Both "none" cases are explicit states, never a blank body.
  Resolution happens on every call, never from a cached copy, so an Admin's activation shows on the next render.

- `RollcallScreen::for($section, $weekStart)` (3.7, AD-14, AD-20): the props of the rollcall page for one section and Cav
  week. Rows are the active troopers whose primary section it is, so a trooper with a billet elsewhere appears once. Each
  row's practice value is, in order: `loa`, derived from the leave data and never stored (3.4); the mark already saved
  for the week; `present` carried from the prior week when nothing is saved; or nothing. Only `present` carries, so no
  one is pre-marked absent. A carried value is shown, not stored. The banner counts what was saved and what was carried,
  and says when there was no prior week. Roster and LOA values come with their source and last-sync time. `locked` is
  true once the section's weekly report for the week has been sent (AD-12): the page then shows the week read-only, and
  the write path (`MarkAttendanceHandler`) refuses any save for it on its own.

**May depend on:** `Repositories/` (never the DB directly, AD-2), `Models/`, `Loa/` (a row's leave) and `Support/Time/`
**Reached from:** `Http/Controllers/`. Read-only: templates are written only by the audited
`Commands/Templates` Commands. Who may look is the Controller's ScopeResolver gate (AD-8), not decided here.
