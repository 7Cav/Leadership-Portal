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
  a makeup PM link when they missed a practice, and their transfers between units (newest first; also on the roster
  Sheet). Their attendance history follows them: after a transfer the new leadership sees the trooper's past rollcall
  signing, practice attendance and leave, but never the old unit's sent reports. Transfers from before this was
  recorded do not show. Awards are not synced from Cav yet and the page says so. Departed troopers still render,
  marked departed.
- Training readiness matrix (`/readiness/<unit>`): troopers by courses for one unit.
- Find trooper: the search in the shell (also the `/` key) finds troopers in your scope and opens their milpac page.
  Type a username, with or without a leading rank and with a space for the dot ("SGT Vasquez R"), or a cav id
  ("#1234"). An empty result says whether it was limited to your units. Departed troopers are flagged.

### Rollcall

One section's week, in three steps. It opens on the last section you opened, else the section your billet commands,
else the first section of the first platoon in your scope. A week selector moves back through past weeks (down to the week floor) and flags unfinished ones.

1. Signatures: for each trooper, Will attend, Cannot attend or Unsigned, as signed on the forum's rollcall thread.
2. Section practice: Present, Missed, Makeup or Attached. "Mark all present", "Mark the rest missed" and "use last
   week's marks" fill many rows at once, with Undo. Number keys work as shortcuts (press `?` for the list).
3. Review and post: the changes made on this visit, then the forum post as BBCode, built from the section's template
   and the current marks. Copy it, post it on the forum yourself, then click to mark it posted. The portal never posts
   to the forum. If marks change after you copied, the page tells you the copy is out of date. The section's leaders
   can pick the template here, from the ones their company switched on and the regiment default; "Company default"
   follows the company's choice. The pick stays until changed and is written to the audit log.

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
  - Monthly training plan (`/reports/<platoon>/plans/<YYYY-MM>`, "Training plan" on a platoon's report): the
    platoon's chain of command gives each Cav week of the month a training subject (every week whose Saturday falls
    in the month) and chooses whether the subjects are locked on the section reports. Each save goes to the company
    for approval. Company command (or above) approves it or sends it back with a comment the platoon leader sees.
    Approving writes each week's subject as the platoon training topic, so every section's report starts with it. A
    change after approval needs approval again; until then the approved subjects stay. A week whose platoon report is
    sent, or that is before the week floor, keeps its subject. Above a platoon, the same address lists every
    platoon's plan for the month and where it stands.
  - Send: freezes the KPIs, notes, subject and reasons with a reason for the record. When checks are open or children
    have not sent, the button becomes "Send anyway"; missing children are recorded and their numbers stay out.
  - A sent report is read back exactly as frozen and never recalculated. Sending a section's report locks that week's
    rollcall.
  - Weeks before the week floor open read-only.

### Game identities

Link each trooper in your scope to their in-game identity, one per game. The games are the ones an Admin keeps on the
Games page (Hell Let Loose (WW2), Hell Let Loose: Vietnam, Arma Reforger and WARDOGS to start with; Vietnam is not
captured until an Admin picks its interface); a retired game keeps its links but takes no new ones. A trooper can hold
one link on each game, and the same ID on two games is not a collision. A "needs a link" queue lists troopers missing a
link on any game, so one linked only on WW2 shows as missing Vietnam. The capture uses these links to recognise players, and the
section's game is the one most of its troopers have a link for. Every serving trooper placed in your scope is listed:
section members, HQ staff and group members (ELOA, Reservists, New Recruits, staff departments). Linking someone
outside a section only lets the capture recognise them; attendance is still marked in sections only.

### Counseling (DCS and LOR)

On a trooper's milpac page, the Counseling panel holds their Developmental Counseling Statements (DCS) and Letters of
Reprimand (LOR), newest first. Who may file and see which is in [permissions.md](permissions.md#counseling-dcs-and-lor).

- File a record: the type, the date it was given, the reason and the signed document, a PDF of 10 MB or less. The file
  is checked by its content, not its name, and kept on the portal's private disk. It downloads only from the record,
  for someone who may see it, named after the record (`LOR 2026-10-20 Novak.E.pdf`). Filing the same record again
  (same type, date, reason and document) within 10 minutes is refused as already filed, so a double click never
  counts twice.
- Google Drive: when the service account is set up (see [operations.md](operations.md#env-keys-that-matter)), a queued
  job copies the document to the 7Cav Google Drive folder and retries on failure. Each record shows "Drive copy
  pending", "Copied to Drive", "Drive copy failed" or "Portal only" (Drive not set up; the record is kept anyway).
- 90-day rules, counted back from today: the panel warns at a second DCS in 90 days (the next step is an LOR), a second
  LOR in 90 days (the trooper may be removed as SL or ASL) and a third (an Article 15). The filing form says what one
  more record would mean. An older record stays on file, marked as no longer counting.
- Correct or delete: the date and reason can be corrected, never the type or the document; a record can be deleted
  with its document. Both need a reason and are audited, as is filing. A copy already on Google Drive stays there.

## Settings menu (Admins)

| Page | What it does |
| --- | --- |
| Org structure | The unit tree with each unit's lead (billet holder, portfolio officer, DEVCOM's 1IC or acting lead, or vacant) and headcount. Admins can add, rename, move and delete units (a unit with anything attached cannot be deleted), run Import from Cav roster, set a game or team label on a unit (shown next to its name), assign or remove an acting lead (reason required), and place a trooper the sync could not place. Staff groups list their members; ELOA shows each trooper's last line unit ("was"). |
| Staff grants | Grant a non-Admin user read access to a unit and everything under it, or revoke it, with a reason. Lapsed grants (holder no longer serving) are flagged. |
| Templates | Rollcall BBCode templates. A company keeps named templates of its own, and the regiment default (edited by Admins only) shows in every company's list. A company's command (CO, XO, 1SG, the battalion and regiment command above, an acting lead of the company) and Admins can add a template, edit it, roll it back, switch it on or off for the company's sections, and make one the company default. A section uses its leader's pick, else the company default while it is switched on, else the regiment default, so a section always has a post. Battalion command can turn a company's own template editing off; the battalion or an Admin then sets that company's templates, and the page says so. A platoon moved to another company takes the templates its sections used along ("carried" in the rollcall's picker), unless the new company's battalion sets its templates; then the rollcall says which template the post now uses. A non-Admin sees only the companies in their scope, opening on their own. Each save is a new version with a reason; any older version can be activated again. The preview fills itself from the company's first section in your scope (sample data when there is none), or any other section you pick. The template language is a small Mustache subset (`{{name}}`, repeated and conditional blocks, `upper` and `lower`); mistakes are refused on save. |
| Games | The games the regiment plays: name, the kinds of player ID it asks for (the first is the default), and which telemetry interface reads its attendance (CRCON, BattleMetrics, Nightwatch or none). Add, edit, retire or reinstate a game; delete one only while nothing uses it. |
| Audit log | Every audited change (and every view as start and stop): who, what, when, the reason, filterable by actor, action, target, date and unit (including everything under it). |
| Activity log | The pages each user opened and when, newest first, filterable by user and date range, to retrace a bug report. Shows when an Admin was acting as or viewing as someone. Addresses and times only (sensitive query values and every sign-in query are dropped), never form contents. Kept 30 days. |
| Demo data | Only when `DEMO_TOOLS=true`. Generate 1 to 12 completed weeks of demo signatures, marks and sent reports for a unit's real troopers, or purge them. Never touches a week that holds real records. |

Non-Admins do not see the Settings menu. They can still open Org structure, Staff grants, Templates and Games by URL, read only (Templates only for the companies in their scope, and a company's command can manage its own company's templates there).

## Background jobs

All run on the queue; a queue worker must be running. Times are UTC.

| Job | When | What it does | Configured by |
| --- | --- | --- | --- |
| Roster sync | Hourly on the hour; Admin "Sync now"; leader unit sync | Reads the Cav roster, updates troopers (rank, billet, status), places each trooper by billet, marks leavers departed. A unit sync only writes that unit's troopers. | `CAV_API_KEY`, `CAV_API_BASE_URL` |
| LOA sync | Hourly at :15 (new threads), daily at 03:30 (full re-read of recent leave) | Reads LOA and ELOA threads from the configured forum sections. ELOA start and end dates come from the linked PAF thread. Posts it cannot read are stored as "needs review", never guessed. First run reads a year back. | `SEVENCAV_FORUM_API_KEY`, `SEVENCAV_FORUM_LOA_NODES`, `SEVENCAV_FORUM_PAF_NODE`, `LOA_SYNC_REQUEST_DELAY_MS`. Skipped while the key or sections are unset. |
| Counseling document copy | When a DCS or LOR is filed with Google Drive set up | Copies the document to the 7Cav Google Drive folder. Tried up to 5 times, with pauses of 1, 5, 15 and 60 minutes; the record shows the outcome. | `GOOGLE_DRIVE_SERVICE_ACCOUNT_EMAIL`, `GOOGLE_DRIVE_PRIVATE_KEY`, `GOOGLE_DRIVE_FOLDER_ID`. Skipped (record kept, "Portal only") while any is unset. |
| Practice capture | When a leader clicks "Try again" on the rollcall. That button only shows after a failed capture, and nothing in the code schedules a first capture. | Reads who was on the game server during the Cav week so far, pre-marks linked troopers in the section as Present (a leader's mark always wins), lists unmatched players. | Each game's telemetry interface, picked on the Games page. CRCON (`CRCON_BASE_URL`, `CRCON_API_KEY`) falls back to BattleMetrics; BattleMetrics (`BATTLEMETRICS_API_KEY`, `BATTLEMETRICS_SERVER_ID`); Nightwatch (`NIGHTWATCH_API_KEY`, `NIGHTWATCH_SERVER_IDS`). Each interface reads the servers set in env, not one per game. A game with no keys, or interface "none", shows "not configured". |

The capture does not record other sections' troopers as guests or makeups for now: without set practice times the
window is the whole week and the servers are shared, so being online does not mean being at that practice.

Activity log pruning runs from the scheduler itself, not the queue: daily at 04:15 `portal:prune-page-visits` deletes
page visits older than 30 days, in chunks.

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

- Admin "act as": a full impersonation with that person's permissions (red bar), able to send reports.
- An Admin-only page-visit log for reproducing bugs.

## Technical appendix

| Area | Code |
| --- | --- |
| Routes | `routes/web.php`, schedule and console commands in `routes/console.php` |
| Pages | `resources/js/Pages/*.vue`, navigation in `resources/js/Layouts/navigation.js` |
| Rollcall logic, templates, guests | `app/Rollcall/`, `app/Commands/Attendance`, `app/Commands/Guests`, `resources/js/lib/rollcall.js`, `rollcallBbcode.js` |
| KPIs, JSAM | `app/Kpi/KpiService.php`, `PracticeGuests`, `JsamCriteria`, `config/portal.php` (`jsam`) |
| Leave | `app/Loa/LoaCoverage.php`, `app/Sync/SyncLoasHandler.php` |
| Roster sync and placement | `app/Sync/SyncRosterHandler.php`, `RosterSyncScope*`, `app/Jobs/SyncRosterJob.php` |
| Capture | `app/Commands/Capture/` (`CaptureSources` maps each telemetry interface to its sources), `app/Commands/Games/`, `app/Jobs/CaptureSectionPracticeJob.php`, `app/Integration/` |
| Reports | `app/Commands/WeeklyReports/`, `app/Kpi/RollupKpis.php`, `ReportChain.php`, `resources/js/lib/report.js` |
| Training plans | `app/Commands/TrainingPlans/`, `TrainingPlanController`, `resources/js/Pages/Reports/TrainingPlan.vue`, `resources/js/lib/trainingPlan.js` |
| Counseling | `app/Commands/Counseling/`, `app/Authorization/CounselingAccess.php`, `app/Milpac/CounselingHistory.php`, `app/Integration/GoogleDrive/`, `app/Jobs/UploadCounselingDocumentJob.php`, `resources/js/components/milpac/CounselingPanel.vue` |
