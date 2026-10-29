# `Rollcall/` — what the rollcall screen shows and renders from (Epic 3)

Read-only logic behind the weekly rollcall, so no screen re-derives it.

- `TemplateResolver::forUnit($unit)` → `ResolvedTemplate` (3.6, #375, AD-17): which rollcall template version applies
  to a unit. It walks up the org tree to the owning company (the unit itself when it is a company) and takes the
  section's pick while it is still available (one of the section's own templates, one the company has switched on, or
  the regiment default), else the company's active template if it is switched on, else the regiment-wide default, with
  the template and version ids so a render can record exactly which one it used (`source`: `picked`, `company` or
  `default`). A section's own templates are only ever offered to and used by that section, and a unit under one
  company never borrows another company's template.
  `TemplateResolution` is the outcome: `resolved`, `no_active_template` (not even the regiment default has a version)
  or `no_owning_company` (the unit sits above every company). Both "none" cases are explicit states, never a blank
  body. Resolution happens on every call, never from a cached copy, so a company's change shows on the next render.
  `optionsFor()` and `pickerFor()` are what the rollcall's template picker offers. A platoon or section moved to
  another company renders from copies of the templates its sections used, made for the new company by the move (#378),
  never from the old company's; when a copy has been switched off since, `pickerFor()` says where it came from and which
  template the post uses now. A section's own templates move with it and are never copied.

- `TemplateSyntax::check($body)` (3.10, AD-17): the grammar a rollcall template must follow, checked when an Admin saves
  one. The language is Mustache's core and cannot run code: `{{name}}` prints a value, `{{#troopers}}...{{/troopers}}`
  repeats for each trooper, `{{#flag}}...{{/flag}}` and `{{^flag}}...{{/flag}}` show when a value is, or is not, set,
  and `|upper` and `|lower` are the only filters. Every name is from a fixed list, so a typo is refused at save and never
  reaches a section. The renderer is the browser's (`resources/js/lib/bbcode.js`), so the post follows each mark;
  both read the shared cases in `tests/Fixtures/bbcode-templates.json`. `STARTER` is an example for a company that has
  none.

- `RollcallRoster` (3.7): the section, week and trooper rows (with the week's marks and last week's practice marks) the
  rollcall page reads and its forum post is filled from. The template editor's preview of a real section reads the same
  rows, so the two cannot drift apart.

## How the rollcall binds end to end (3.7 to 3.11)

The page opens a section and week (3.7), pre-fills it from the roster, the leave data and the prior week, and receives the
company's active template, resolved on that request (3.6, the `template` prop with its version id). A leader marks it (3.8,
autosaved through the audited `MarkAttendance` Command), each stored mark shows where it came from (3.9), and the forum post
is rendered in the browser from the template and the current marks, ready to copy (3.10, `lib/rollcallBbcode.js`). There is
no built-in post: a company with no template, a section under no company, or a stored template that no longer renders shows
what is missing instead. The template is resolved on every render and never cached, so an edit or rollback changes
every section using that template on its next load, and no other company's. Marks are data and the template is
presentation: a template change between load and save does not affect what is saved. A leader picks a template here but
has no way to edit one; authoring is the company's or the section's command's (and an Admin's) in Settings, Templates
(3.5, #375).

Leave comes from `Loa/LoaCoverage`, the one definition (3.4, 4.4, AD-21), sent per trooper as `loa` (`LoaCover::toArray()`)
with the LOA source and last sync (`loaSync`, AD-20). Nothing stores an `loa` mark: a covered trooper's row is locked, listed
under "On LOA", left out of "Mark all present" and last week's marks, and posts as `LOA`. `MarkAttendance` still refuses a
practice mark for them; if a leave syncs after the page loaded, the refusal says `loa_covered`, the screen locks the row and
keeps saving their signature. A leave that needs review asserts nothing (AD-4): the row stays markable and only shows the
badge. Home's "signed" and "marked" steps count a covered trooper as done.

## Captured marks and makeups (Epic 7)

A capture of a section's practice pre-marks the troopers it saw as `present` with the `auto` source (7.6), through
the same writer as a leader's mark, and a leader's mark always wins. A trooper is on exactly one rollcall, their
primary section's (AD-14), and attending another section's practice is a makeup for their own section's practice that
Cav week.

The capture does not credit other-section troopers: it lists them on the review drawer as other-section and writes
nothing for them, no makeup on their own rollcall and no `cross_section_sightings` row. Until the portal has practice
time slots, the capture window is the whole Cav week so far and the game servers are shared by every section, so being
seen on the server does not mean someone was at that practice (decided 2026-10-23). Makeups come from leaders: their
own section's leader by hand, or a host leader adding them as a guest.

`MarkAttendanceHandler::preMarkMakeups()` (a `makeup` with the `auto` source, shown with the auto source dot) is what
a host leader's hand-added guest writes (see "Guests at our practices" below), and stays for the capture for when
practice times exist, with these rules:

- Only while a `cross_section_sightings` row of that trooper in that Cav week supports it, read under a lock.
- Same Cav week only (`WeekOf`, Saturday to Friday): never an older or a later week.
- Never a downgrade: `present` or `attached` stays, so does a leader's manual mark of any status and an `loa` mark. An
  unmarked trooper and an automatic `missed` become the makeup. When their own practice is captured later and sees
  them, the auto makeup becomes `present`.
- Nothing is written into a week whose own-section weekly report was really sent (AD-12), a practice its leader
  confirmed (7.8), or a week before `WeekOf::floor()`. A trooper whose leave covers the week, or who no longer serves,
  is skipped, as in the pre-mark.
- A makeup a leader marks by hand counts the same in the KPIs. An automatic makeup alone does not make the section's
  practice *held* (Home's "SP held" step, the counting marks): it records a practice attended elsewhere, so a section
  that did not practise is not shown as having practised. The trooper's own SP still counts the week as attended
  (see `Kpi/`). A makeup marked by hand still holds the practice, as before.

## Guests at our practices (JSAM evidence)

The Joint Service Achievement Medal is for a trooper who attends several practices of sections they are not assigned
to while still attending their own, recommended by the commander of the unit that benefited. The portal never awards
it; it shows the evidence.

- **Sources.** A `cross_section_sightings` row is either `manual` (the host section's leader added them on their
  rollcall, `recorded_by` that leader) or `capture` (the game server saw them). For now guests come only from the host
  leader: the capture window is the whole Cav week and the portal has no practice times yet, so the capture does not
  record guests (decided 2026-10-23); `capture` rows written before that stay, and the server records guests again
  once practices have set times. One row per trooper and host practice whatever the source, so a trooper is one visit
  per practice. A capture of a trooper already added by hand turns that row into a `capture` one (keeping
  `recorded_by`), so the server's evidence can never be undone by hand; a hand entry for a trooper the server already
  saw adds nothing.
- **Adding a guest by hand** (`RecordGuestAttendance`): gated by the host section's write scope (a staff grant and a
  view-as preview only read), refused for a week before `WeekOf::floor()`, a host week whose report was really sent
  (409, `locked`) and a confirmed host practice. The guest must be serving, placed in a section, and not in the host
  section; for a week that has ended, "placed" means the section whose rollcall lists them that week
  (`TrooperRepository::ownSectionsInWeek`), where the makeup goes too. The picker (`GET /rollcall/{unit}/guests/search`) finds serving troopers outside the leader's scope by
  username and answers only id, username, rank and own section designation, never the milpac.
- **The makeup it brings.** A hand-added guest gets the same automatic makeup on their own section's rollcall as a
  capture sighting, through `MarkAttendanceHandler::preMarkMakeups()` with all its guards (same Cav week, never over
  `present`/`attached`/`loa` or any manual mark, never into a locked, confirmed or before-floor week, skipped for
  leave and for a trooper no longer serving there). It is written after the entry commits, and only while a sighting
  of that trooper that Cav week still exists, read under a lock taken before their own practice's: an Undo that lands
  in between leaves no makeup behind. It is audited as `attendance.auto_marked` with the host leader as
  the actor, so the guest's own leader can see whose word it was and change the mark.
- **Undo** (`RemoveGuestAttendance`): only a `manual` entry, by someone who may write the host section, under the same
  host guards. A capture sighting cannot be removed by hand. The makeup is taken back (cleared to unmarked,
  `attendance.auto_unmarked`, same actor) only while it is still the automatic makeup and nothing else supports it:
  no other sighting of that trooper, capture or manual, at any practice that Cav week, and their own week is not
  locked or confirmed. A makeup a leader has since confirmed or changed by hand, or one the trooper's own capture has
  turned into `present`, stays as it is.

The candidates are read in `Kpi/PracticeGuests` and shown on the roster (see `Kpi/README.md`).

## Who is on a rollcall (2026-10-24)

Only troopers placed in a section. HQ staff are placed on their HQ node (a platoon, company, battalion or the regiment)
and staff, ELOA, Reservists and New Recruits in a group: none of those is a section, so they are on no rollcall, get no
practice, capture or makeup, and are never offered as a guest (`GuardsHostPractice` and the guest picker ask for a
section placement). A staff member's own staff practice entry (from their staff report or their milpac, policy
2026-10-29) lists them in the host's "Guests" area through `RollcallGuests`: `staffGuest` when the host is under the
HQ they were on when the week ended, a guest otherwise. Shown only, never on the roster or in any count.

**May depend on:** `Repositories/` (never the DB directly, AD-2) and `Models/`.
**Reached from:** `Http/Controllers/`. Read-only: templates are written only by the audited
`Commands/Templates` Commands. Who may look is the Controller's ScopeResolver gate (AD-8), not decided here.
