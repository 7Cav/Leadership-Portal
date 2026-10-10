# `Rollcall/` — what the rollcall screen shows and renders from (Epic 3)

Read-only logic behind the weekly rollcall, so no screen re-derives it.

- `TemplateResolver::forUnit($unit)` → `ResolvedTemplate` (3.6, AD-17): which rollcall template version applies to a
  unit. It walks up the org tree to the owning company (the unit itself when it is a company) and returns that
  company's active version, with the version id so a render can record exactly which one it used. Every unit under one
  company resolves to the same version and a unit under another company never borrows it.
  `TemplateResolution` is the outcome: `resolved`, `no_active_template` (the company has not authored one yet) or
  `no_owning_company` (the unit sits above every company). Both "none" cases are explicit states, never a blank body.
  Resolution happens on every call, never from a cached copy, so an Admin's activation shows on the next render.

**May depend on:** `Repositories/` (never the DB directly, AD-2) and `Models/`.
**Reached from:** `Http/Controllers/`. Read-only: templates are written only by the audited
`Commands/Templates` Commands. Who may look is the Controller's ScopeResolver gate (AD-8), not decided here.
