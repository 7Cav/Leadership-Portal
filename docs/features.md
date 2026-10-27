# Features

## In short

The week's work, for a section leader: open Rollcall, record who signed the rollcall thread, mark who was at Section
Practice, copy the forum post the portal builds, mark it posted, then send the section's weekly report. Platoons,
companies, battalions and the regiment then review and send their own reports, which roll up the ones below them.
Home shows where every unit in your scope is in that cycle.

Who may open or change each page is in [permissions.md](permissions.md). This file says what the pages do.

## Leadership menu

### Home

The dashboard for your scope, for the Cav week whose report cycle is running (the closing week stays current until its
report deadline, 23:59 UTC on the Saturday after it ends, has passed).

- Week bar: the days of the week and the report due date, with five progress lanes added up over every section in
  scope: Rollcall posted, Rollcall signed, SP held, Attendance marked, Report sent. Each section's step links to the
  rollcall step or report where it gets done.
- KPI tiles: the six KPIs (see "KPIs" below) live for your whole scope, with a sparkline of the last four weeks. A past
  week is only drawn when every section in scope sent its report that week.
- Needs attention: units holding up the report chain, and at-risk troopers, each linked to the place to fix it, with a
  makeup PM link for at-risk troopers.
- Subordinate units: the units directly under the top of your scope, with their report status and KPIs.

Home only counts line units (regiment, battalions, companies, platoons, sections). Staff groups have no report chain.

### Roster

Your scope's units and troopers, from the cached Cav roster.

- Two views: ORBAT (the order of battle, by unit) and Matrix (each trooper's SP marks for the last 20 Cav weeks, with
  LOA weeks shown).
- A unit picker for any unit in scope, and a breadcrumb trail up the chain.
- Trooper sheet: click a trooper for their section, leader, KPIs and 20 weeks of marks with where each mark came from.
  It links to the trooper's milpac page and their section's rollcall.
- Acting leads and portfolio leads are marked; HQ staff appear on their HQ node (platoon, company, battalion or
  regiment).
- Guests at our practices: troopers from outside the unit recorded at its sections' practices, with visits, last week
  seen and their own section SP. Those who meet the JSAM thresholds are marked as candidates (see "JSAM guests").
- Sync: an Admin's "Sync now" refreshes the whole roster from Cav; a leader gets a sync of just the unit on screen. Both
  queue a job; reload after a minute. The page shows when the roster and the leave data last synced.

Related pages reached from the roster:

- Milpac page (`/troopers/<id>`): the trooper's ID block (rank, billet, MOS, time in service and grade, section),
  courses, a service record timeline, quick links to their section roster, rollcall and platoon readiness matrix, and
  a makeup PM link when they missed a practice. Awards are not synced from Cav yet and the page says so. Departed
  troopers still render, marked departed.
- Training readiness matrix (`/readiness/<unit>`): troopers by courses for one unit.
- Find trooper: the search in the shell (also the `/` key) finds troopers in your scope by forum username and opens
  their milpac page. Departed troopers are flagged.

### Rollcall

One section's week, in three steps. With no section chosen it shows a section picker; the portal remembers the last
section you opened. A week selector moves back through past weeks (down to the week floor) and flags unfinished ones.

1. Signatures: for each trooper, Will attend, Cannot attend or Unsigned, as signed on the forum's rollcall thread.
2. Section practice: Present, Missed, Makeup or Attached. "Mark all present", "Mark the rest missed" and "use last
   week's marks" fill many rows at once, with Undo. Number keys work as shortcuts (press `?` for the list).
3. Review and post: the changes made on this visit, then the forum post as BBCode, built from the company's active
   template and the current marks. Copy it, post it on the forum yourself, then click to mark it posted. The portal
   never posts to the forum. If marks change after you copied, the page tells you the copy is out of date.

Every change saves in the background as you click, and is written to the audit log.

On the same page:

- LOA: a trooper whose leave covers the week is locked, listed under "On LOA", and posts as `LOA`. A leave post the
  sync could not read shows a "needs review" badge but stays markable.
- Capture review: when game-server data was captured for the practice, marks it made show their source, and the leader
  can confirm the practice ("Confirmed by ..."). Editing a mark by hand afterwards clears the confirmation.
- Unmatched players: players the capture saw that no trooper is linked to. Link one to a trooper (this also saves their
  game identity and marks them present), dismiss them, or restore a dismissed one.
- Telemetry banner: says when the section's game has no capture source set up, or when the last capture failed, naming
  the feeds tried. "Try again" queues a new capture for the week so far.
- Guests: add a serving trooper from another section who attended this practice, or remove one you added. See "JSAM
  guests" and "Makeups".
- Fireteams: mark fireteam leaders (FTLs), put troopers in their fireteams, and choose how the post shows them. A company
  command can set a policy for all its sections (show or hide FTLs, leaders first, and whether SL and ASL may lead a
  fireteam), which overrides the sections' own choices while it is set.
- Report deadline banner with a link to the section's report.

Only troopers placed in a section are on a rollcall. HQ staff, staff departments, ELOA, Reservists and New Recruits are
on none.

### Reports

- Roll-up workspace (`/reports`): the tree of every line unit in your scope for one Cav week (the current week and the
  12 before it), each with its send status, KPIs and change from last week. A section leader goes straight to their own
  report.
- A unit's report (`/reports/<unit>/<week>`):
  - Section report: KPIs and the change from last week, a "Ready to send" checklist (Rollcall complete, Practice marked,
    Unaccounted troopers explained, Training subject filled, Notes reviewed), a reason for each unaccounted trooper from
    a fixed list, the training subject (last sent subject offered as a suggestion), notes, history and an 8 week trend.
  - Platoon and higher: the same, but the KPIs are the sum of the direct children's sent reports, with each child's
    status. The "Every unit has sent" check names who is holding up the chain.
  - Platoon training topic: the platoon's chain of command sets a subject for its sections for the week, either as a
    default the sections can change or enforced (locked on every section's report).
  - Send: freezes the KPIs, notes, subject and reasons with a reason for the record. When checks are open or children
    have not sent, the button becomes "Send anyway"; missing children are recorded and their numbers stay out.
  - A sent report is read back exactly as frozen and never recalculated. Sending a section's report locks that week's
    rollcall.
  - Weeks before the week floor open read-only.

### Game identities

Link each trooper in your scope to their in-game identity, one per game: Hell Let Loose, Arma Reforger and WARDOGS. A
"needs a link" queue lists troopers with none. The capture uses these links to recognise players, and the section's
game is the one most of its troopers have a link for. Only troopers placed in a section are listed.

## Settings menu (Admins)

| Page | What it does |
| --- | --- |
| Org structure | The unit tree with each unit's lead (billet holder, portfolio officer or acting lead, or vacant) and headcount. Admins can add, rename, move and delete units (a unit with anything attached cannot be deleted), run Import from Cav roster, set a game or team label on a unit (shown next to its name), assign or remove an acting lead (reason required), and place a trooper the sync could not place. Staff groups list their members; ELOA shows each trooper's last line unit ("was"). |
| Staff grants | Grant a non-Admin user read access to a unit and everything under it, or revoke it, with a reason. Lapsed grants (holder no longer serving) are flagged. |
| Templates | One rollcall BBCode template per company. Each save is a new version with a reason; any older version can be activated again. Preview with sample data or a real section's current week. The template language is a small Mustache subset (`{{name}}`, repeated and conditional blocks, `upper` and `lower`); mistakes are refused on save. |
| Audit log | Every audited change: who, what, when, the reason, filterable by actor, action, target, date and unit (including everything under it). |
| Demo data | Only when `DEMO_TOOLS=true`. Generate 1 to 12 completed weeks of demo signatures, marks and sent reports for a unit's real troopers, or purge them. Never touches a week that holds real records. |

Non-Admins do not see the Settings menu. They can still open Org structure, Staff grants and Templates by URL, read only.

## Background jobs

All run on the queue; a queue worker must be running. Times are UTC.

| Job | When | What it does | Configured by |
| --- | --- | --- | --- |
| Roster sync | Hourly on the hour; Admin "Sync now"; leader unit sync | Reads the Cav roster, updates troopers (rank, billet, status), places each trooper by billet, marks leavers departed. A unit sync only writes that unit's troopers. | `CAV_API_KEY`, `CAV_API_BASE_URL` |
| LOA sync | Hourly at :15 (new threads), daily at 03:30 (full re-read of recent leave) | Reads LOA and ELOA threads from the configured forum sections. ELOA start and end dates come from the linked PAF thread. Posts it cannot read are stored as "needs review", never guessed. First run reads a year back. | `SEVENCAV_FORUM_API_KEY`, `SEVENCAV_FORUM_LOA_NODES`, `SEVENCAV_FORUM_PAF_NODE`, `LOA_SYNC_REQUEST_DELAY_MS`. Skipped while the key or sections are unset. |
| Practice capture | When a leader clicks "Try again" on the rollcall. That button only shows after a failed capture, and nothing in the code schedules a first capture. | Reads who was on the game server during the Cav week so far, pre-marks linked troopers in the section as Present (a leader's mark always wins), lists unmatched players. | Hell Let Loose: CRCON (`CRCON_BASE_URL`, `CRCON_API_KEY`), falling back to BattleMetrics. Arma Reforger: BattleMetrics (`BATTLEMETRICS_API_KEY`, `BATTLEMETRICS_SERVER_ID`). WARDOGS: Nightwatch (`NIGHTWATCH_API_KEY`, `NIGHTWATCH_SERVER_IDS`). A game with no keys shows "not configured". |

The capture does not record other sections' troopers as guests or makeups for now: without set practice times the
window is the whole week and the servers are shared, so being online does not mean being at that practice.

The org import (Settings, Org structure, Import from Cav roster) is not scheduled; an Admin runs it.

## JSAM guests

The Joint Service Achievement Medal is for a trooper who attends several practices of sections they are not assigned to
while still attending their own. The portal shows the evidence; it never awards.

- A host section's leader adds a guest on their rollcall. The guest must be serving, placed in a section, and not in
  the host section. The picker searches by username across the whole roster and shows only name, rank and section.
- One visit per trooper per host practice.
- The roster's "Guests at our practices" panel marks a candidate when, over the last `JSAM_WINDOW_WEEKS` (default 12)
  Cav weeks, they made at least `JSAM_MIN_VISITS` (default 3) visits to the viewed unit's practices and their own
  section SP is at least `JSAM_MIN_OWN_SP_PERCENT` (default 75).
- The commander of the unit that benefited recommends the award.

## Makeups

A trooper is on exactly one rollcall, their own section's. Attending another section's practice counts as a makeup for
their own section's practice that Cav week.

- Their own leader can mark Makeup by hand.
- When a host leader adds them as a guest, the portal writes an automatic makeup on their own section's rollcall for
  the same week, shown with an "auto" source dot. It never overwrites Present, Attached, LOA or any mark a leader set by
  hand, and is never written into a sent, confirmed or before-floor week, or for a trooper on leave.
- Removing the guest takes the automatic makeup back, unless something else supports it or a leader has since changed
  or confirmed it.
- A makeup counts for the trooper's SP. An automatic makeup alone does not make their own section's practice "held".

## KPIs

One definition, used by Home, the roster, the reports and the roll-ups. The window is the last 4 whole Cav weeks
including the current one. Only troopers placed in a section are measured.

| KPI | Definition |
| --- | --- |
| Strength | Troopers placed in sections in the unit. (The org page and roster headcount also count HQ staff and groups.) |
| RC % · 30d | Weeks with a signed rollcall (Will attend or Cannot attend) divided by all weeks in the window. |
| SP % · 30d | Practices marked Present, Makeup or Attached, divided by the practices their own section held. A practice is held once a leader put an SP mark on anyone. A week the trooper attended always counts as eligible for them. |
| At-risk | Troopers with 2 or more practices explicitly marked Missed in the window. Unmarked is never a miss. |
| On LOA | Troopers whose leave covers the current week. |
| Makeups | Makeup marks in the current week. |
| Own section SP (JSAM only) | SP as above, except a makeup never counts as attended, and a week their own section did not hold is left out. |

Leave covering a week takes that week out of both RC and SP for the trooper. A leave post that needs review takes its
weeks out too (no miss, not in either denominator) but does not count as On LOA. A non-leaf unit's reported KPIs are
the sum of its direct children's sent reports.

## Planned (not yet built)

From the backlog of 2026-10-27. None of these exist in the code today.

- Find trooper: fix it showing no troopers; possibly rename it if it also shows units.
- Rollcall generator defaults to the first platoon under the viewer's scope.
- Battalion HQ switch to stop company commanders setting rollcall for every section in their company.
- A regiment-wide default rollcall template that always shows in Templates; company commanders choosing which templates
  their rollcalls may use; DEVCOM 1IC (CO of D/DEVCOM) setting DEVCOM's templates.
- Admin "act as": a full impersonation with that person's permissions (red bar), able to send reports.
- Monthly Training Plans: written by platoon leaders, reviewed by the company, filling in the weeks' subjects.
- Moving a platoon to another company keeps its saved settings and templates.
- Attendance history that shows transfers, so new leadership sees signing, attendance and LOAs but not past reports.
- LOR and DCS tracker with automatic upload.
- Warrant Officers shown in the NCO colour on the roster.
- View as entries in the audit log, and an Admin-only page-visit log for reproducing bugs.

## Technical appendix

| Area | Code |
| --- | --- |
| Routes | `routes/web.php`, schedule and console commands in `routes/console.php` |
| Pages | `resources/js/Pages/*.vue`, navigation in `resources/js/Layouts/navigation.js` |
| Rollcall logic, templates, guests | `app/Rollcall/`, `app/Commands/Attendance`, `app/Commands/Guests`, `resources/js/lib/rollcall.js`, `rollcallBbcode.js` |
| KPIs, JSAM | `app/Kpi/KpiService.php`, `PracticeGuests`, `JsamCriteria`, `config/portal.php` (`jsam`) |
| Leave | `app/Loa/LoaCoverage.php`, `app/Sync/SyncLoasHandler.php` |
| Roster sync and placement | `app/Sync/SyncRosterHandler.php`, `RosterSyncScope*`, `app/Jobs/SyncRosterJob.php` |
| Capture | `app/Commands/Capture/` (`CaptureSources` is the game to source map), `app/Jobs/CaptureSectionPracticeJob.php`, `app/Integration/` |
| Reports | `app/Commands/WeeklyReports/`, `app/Kpi/RollupKpis.php`, `ReportChain.php`, `resources/js/lib/report.js` |
