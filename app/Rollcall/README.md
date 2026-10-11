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

- `TemplateSyntax::check($body)` (3.10, AD-17): the grammar a rollcall template must follow, checked when an Admin saves
  one. The language is Mustache's core and cannot run code: `{{name}}` prints a value, `{{#troopers}}...{{/troopers}}`
  repeats for each trooper, `{{#flag}}...{{/flag}}` and `{{^flag}}...{{/flag}}` show when a value is, or is not, set,
  and `|upper` and `|lower` are the only filters. Every name is from a fixed list, so a typo is refused at save and never
  reaches a section. The renderer is the browser's (`resources/js/lib/bbcode.js`), so the preview follows each toggle;
  both read the shared cases in `tests/Fixtures/bbcode-templates.json`. `STARTER` is a template for a company that has
  none.

## How the rollcall binds end to end (3.7 to 3.11)

The page opens a section and week (3.7), pre-fills it from the roster, the leave data and the prior week, and shows the
company's active template resolved on that request (3.6, `template` prop). A leader marks it (3.8, saved through the audited
`MarkAttendance` Command), each stored mark shows where it came from (3.9), and the BBCode is rendered in the browser from
the template and the draft, ready to copy (3.10). The template is resolved on every render and never cached, so an Admin's
edit or rollback changes every section under the company on its next load, and no other company's. Marks are data and the
template is presentation: a template change between load and save does not affect what is saved. A leader has no way to
edit a template from here; authoring is Admin-only in Settings, Templates (3.5).

**May depend on:** `Repositories/` (never the DB directly, AD-2), `Models/`, `Loa/` (a row's leave) and `Support/Time/`
**Reached from:** `Http/Controllers/`. Read-only: templates are written only by the audited
`Commands/Templates` Commands. Who may look is the Controller's ScopeResolver gate (AD-8), not decided here.
