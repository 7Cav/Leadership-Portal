# `Rollcall/` — what the rollcall screen shows and renders from (Epic 3)

Read-only logic behind the weekly rollcall, so no screen re-derives it.

- `TemplateResolver::forUnit($unit)` → `ResolvedTemplate` (3.6, AD-17): which rollcall template version applies to a
  unit. It walks up the org tree to the owning company (the unit itself when it is a company) and returns that
  company's active version, with the version id so a render can record exactly which one it used. Every unit under one
  company resolves to the same version and a unit under another company never borrows it.
  `TemplateResolution` is the outcome: `resolved`, `no_active_template` (the company has not authored one yet) or
  `no_owning_company` (the unit sits above every company). Both "none" cases are explicit states, never a blank body.
  Resolution happens on every call, never from a cached copy, so an Admin's activation shows on the next render.

- `TemplateSyntax::check($body)` (3.10, AD-17): the grammar a rollcall template must follow, checked when an Admin saves
  one. The language is Mustache's core and cannot run code: `{{name}}` prints a value, `{{#troopers}}...{{/troopers}}`
  repeats for each trooper, `{{#flag}}...{{/flag}}` and `{{^flag}}...{{/flag}}` show when a value is, or is not, set,
  and `|upper` and `|lower` are the only filters. Every name is from a fixed list, so a typo is refused at save and never
  reaches a section. The renderer is the browser's (`resources/js/lib/bbcode.js`), so the post follows each mark;
  both read the shared cases in `tests/Fixtures/bbcode-templates.json`. `STARTER` is an example for a company that has
  none.

## How the rollcall binds end to end (3.7 to 3.11)

The page opens a section and week (3.7), pre-fills it from the roster, the leave data and the prior week, and receives the
company's active template, resolved on that request (3.6, the `template` prop with its version id). A leader marks it (3.8,
autosaved through the audited `MarkAttendance` Command), each stored mark shows where it came from (3.9), and the forum post
is rendered in the browser from the template and the current marks, ready to copy (3.10, `lib/rollcallBbcode.js`). There is
no built-in post: a company with no template, a section under no company, or a stored template that no longer renders shows
what is missing instead. The template is resolved on every render and never cached, so an Admin's edit or rollback changes
every section under the company on its next load, and no other company's. Marks are data and the template is presentation:
a template change between load and save does not affect what is saved. A leader has no way to edit a template from here;
authoring is Admin-only in Settings, Templates (3.5).

Leave comes from `Loa/LoaCoverage`, the one definition (3.4, 4.4, AD-21), sent per trooper as `loa` (`LoaCover::toArray()`)
with the LOA source and last sync (`loaSync`, AD-20). Nothing stores an `loa` mark: a covered trooper's row is locked, listed
under "On LOA", left out of "Mark all present" and last week's marks, and posts as `LOA`. `MarkAttendance` still refuses a
practice mark for them; if a leave syncs after the page loaded, the refusal says `loa_covered`, the screen locks the row and
keeps saving their signature. A leave that needs review asserts nothing (AD-4): the row stays markable and only shows the
badge. Home's "marked" step counts a covered trooper as marked.

**May depend on:** `Repositories/` (never the DB directly, AD-2) and `Models/`.
**Reached from:** `Http/Controllers/`. Read-only: templates are written only by the audited
`Commands/Templates` Commands. Who may look is the Controller's ScopeResolver gate (AD-8), not decided here.
