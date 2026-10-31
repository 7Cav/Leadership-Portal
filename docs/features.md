# Features

## In short

The week's work, for a section leader: open Roll call, record who signed the roll call thread, mark who was at Section
Practice, copy the forum post the portal builds, mark it posted, then send the section's weekly report. Platoons,
companies, battalions and the regiment then review and send their own reports, which roll up the ones below them.
Home shows where every unit in your scope is in that cycle.

Who may open or change each page is in [permissions.md](permissions.md). This file says what the pages do.

## Leadership menu

### Home

The dashboard for your scope, for the Cav week whose report cycle is running (the closing week stays current until its
report deadline, 23:59 UTC on the Saturday after it ends, has passed).

- Week bar: the days of the week and the report due date, with five progress lanes added up over every section in
  scope: Roll call posted, Roll call signed, SP held, Attendance marked, Report sent. Each section's step links to the
  roll call step or report where it gets done.
- KPI tiles: the six KPIs (see "KPIs" below) live for your whole scope, with a sparkline of the last four weeks. A past
  week is only drawn when every section in scope with troopers sent its report that week.
- Needs attention: units holding up the report chain, at-risk troopers and LOA posts that need review, each linked to
  the place to fix it, with a makeup PM link for at-risk troopers. An LOA post that needs review is one the LOA sync
  could not read, in the reporting week or the running one, for a trooper on that week's roll call in your scope: it
  opens the trooper's card on that roll call and links to the post on the forum, where it is fixed. "Owe a make-up"
  lists the troopers who signed Make-up requested in a window week and have no make-up recorded yet, with those weeks,
  each opening their card on the roll call of the latest one.
- Subordinate units: the units directly under the top of your scope, with their report status and KPIs.
- Staff report: for platoon, company and battalion HQ staff, a line saying their own staff report for the week that just ended
  is not sent yet, with "Open staff report", from the Saturday the week ends until its deadline. It goes once they send
  it (see "Staff report" below).

Home only counts line units (regiment, battalions, companies, platoons, sections). Staff groups have no report chain.
A line unit with no troopers is left out (see "Units with no troopers" below).

### Roster

Your scope's units and troopers, from the cached Cav roster.

- Three views, kept in the URL (`?view=orbat|matrix|strength`): ORBAT (the order of battle, by unit), Matrix (each
  trooper's SP marks for the last 20 Cav weeks, with LOA weeks shown) and Strength (see below).
- Strength tab (`/roster/<id>?view=strength`, also from "Strength" on Home's unit rows): the unit's headcount and in
  roll call per Cav week over 12, 26, 52 weeks or all (`&range=`), the change over the range, joins and departures per
  week (from the placement history, or from a sent snapshot that carries them), each week's headcount change from the
  week before (green up, red down, muted for none), a table of the same numbers, and the headcount of each unit under it as small multiples on one scale. For a
  battalion, each company's card also lists its platoons as compact sparkline rows on one shared scale, with each
  platoon's change. A sent week reads the report's frozen snapshot; another week from 2026-10-24 on is rebuilt from the
  placement history; a week with neither is a gap. The numbers load only when the tab is open (a partial reload), in
  three reads whatever the range or the size of the subtree, grandchildren included. Scoped like the roster (view as
  previews it), read only. The old address `/roster/<id>/strength` redirects to the tab.
- A unit picker for any unit in scope, and a breadcrumb trail up the chain. The ORBAT and the picker leave out line
  units with no troopers (see "Units with no troopers"); one opened by its link still shows.
- Billet tags: a trooper who holds a command billet of their unit is tagged with its short name: CO, XO and 1SG on a
  company; PL and PSG on a platoon; SL and ASL on a section; CO, XO and SGM on a battalion; CO, XO and CSM at the
  regiment (DEVCOM's 1IC and 2IC as its CO and XO). An acting lead shows as acting ("Acting PL") while they lead the unit. Everyone else has no tag. The
  readiness matrix uses the same tags.
- Unit leads: the name under each unit is its commanding billet: the CO of a company, battalion or the regiment, the
  PL of a platoon, the SL of a section (or an acting lead, or a portfolio's officer). With that billet vacant the
  unit's lead shows as vacant; the XO, PSG or ASL is never named in its place and keeps their tag and rights.
- Billet order (the default): each unit lists its command team first, in chain-of-command order: CO, XO, then the
  senior NCO (1SG, SGM or CSM); PL then PSG; SL then ASL. An acting lead stands where the billet they act for does.
  Everyone else follows by rank, then name. A column sort puts everyone where they fall.
- Trooper sheet: click a trooper for their unit, their billet tag and who leads the unit ("leads this platoon as PL"
  for the lead themselves), KPIs and 20 weeks of marks with where each mark came from. It links to the trooper's
  milpac page and their section's roll call.
- Leave from: the trooper sheet shows a "Leave from 17Oct26" chip when the trooper's leave starts the next Cav week.
- Acting leads and portfolio leads are marked; HQ staff appear on their HQ node (platoon, company, battalion or
  regiment).
- Guests at our practices: troopers from outside the unit recorded at its sections' practices, with visits, last week
  seen and their own section SP. Those who meet the JSAM thresholds are marked as candidates (see "JSAM guests").
- Sync: an Admin's "Sync now" refreshes the whole roster from Cav; a leader gets a sync of just the unit on screen. Both
  queue a job; reload after a minute. The page shows when the roster and the leave data last synced.
- Leave sync problems (Admins): under the leave note, what the latest LOA sync could not read: the problems reading the
  forum (each thread linked to the forum), how many posts it read that need review, or why a failed run stopped. Read
  from what the sync records; nothing extra is asked of the forum. There is no leave sync button.
- Guidon: a company, battalion or the regiment can have a guidon, the swallowtail flag split on the diagonal. Its
  command (and the command above it, and Admins) opens "Edit guidon" on the unit's roster to pick the two colors (a set
  palette or any custom color) and the text, with a live preview; nothing is saved until Save. The text starts as the
  battalion and the 7 on the top left ("2/7") and the company letter on the bottom right ("A"). Text is black or white,
  whichever reads better, and the generator warns when the two colors are too close. It downloads as SVG or as a
  300 pixel wide PNG for the wiki. A saved guidon shows next to the unit on the roster, in the unit picker and on Org
  structure; it sits beside the wiki's own art and replaces nothing. Each save is audited. An Admin acting as
  someone who may save it gets the button and the save too, recorded as the Admin acting for them; view as hides it.

Related pages reached from the roster:

- Milpac page (`/troopers/<id>`): the trooper's ID block (rank, billet, MOS, time in service and grade, section),
  courses, a service record timeline, quick links to their section roster, roll call and platoon readiness matrix, and
  a makeup PM link when they missed a practice, and their transfers between units (newest first; also on the roster
  Sheet). Their attendance history follows them: after a transfer the new leadership sees the trooper's past roll call
  signing, practice attendance and leave, but never the old unit's sent reports. Transfers from before this was
  recorded do not show. Awards are not synced from Cav yet and the page says so. Departed troopers still render,
  marked departed. For staff, the page also lists the section practices they attended (see Staff practices below).
  Every trooper may open their own page, read only, whatever their billet (decided 2026-10-28): it shows their own
  DCSs and LORs, and a staff member records their own practices there. The links into their unit and the makeup PM
  show only when their scope reaches it.
- Training readiness matrix (`/readiness/<unit>`): troopers by courses for one unit.
- Find trooper: the search in the shell (also the `/` key) finds troopers in your scope, and yourself, and opens their
  milpac page.
  Type a username, with or without a leading rank and with a space for the dot ("SGT Vasquez R"), or a cav id
  ("#1234"). An empty result says whether it was limited to your units. Departed troopers are flagged.

### Roll call

One section's week, in three steps. It opens on the last section you opened, else the section your billet commands,
else a section you are acting lead of, else the first section of the first platoon in your scope. It only lands on a
section with troopers, unless every section in your scope is empty. The section picker still lists empty sections,
marked "No troopers", so a leader can open one that is being stood up. A week selector moves back through past weeks (down to the week floor) and flags unfinished ones.
Opened without a week, a section shows the closing week while its report is still due (until 23:59 UTC on the
Saturday after the week) and that section has not sent it; "This week" opens the new week. A week chosen in the
selector or a link always wins.

1. Signatures: for each trooper, Will attend, Will not attend, Make-up requested, Excused or Unsigned, as signed on the
   forum's roll call thread. Make-up requested counts as signed; Excused takes the week out of RC.
2. Section practice: Attended, Not attended, Excused or Make-up complete. Make-up complete also covers a practice
   attended at another section (the old "Attached", merged into it). "Mark all attended", "Mark the rest not attended"
   and "use last week's marks" fill many rows at once, with Undo, and leave an excused first week alone. Number keys
   work as shortcuts, 1 to 5 for signatures and 1 to 4 for marks (press `?` for the list).
3. Review and post: the changes made on this visit, then the forum post as BBCode, built from the section's template
   and the current marks. Copy it, post it on the forum yourself, then click to mark it posted. The portal never posts
   to the forum. If marks change after you copied, the page tells you the copy is out of date. The section's leaders
   can pick the template here, from the section's own templates, the ones their company switched on and the regiment
   default; "Company default" follows the company's choice. The pick stays until changed and is written to the audit log.

Every change saves in the background as you click, and is written to the audit log.

On the same page:

- LOA: a trooper whose leave covers the week is locked, shows the LOA button pressed on both tabs (every other card shows it unpressed and disabled: it is set only from the LOA thread), is listed under "On LOA", and posts as `LOA`. A leave post the
  sync could not read shows a "needs review" badge but stays markable. A trooper whose cleanly read leave starts the
  next Cav week shows a "Leave from 17Oct26" chip, read-only, so the next practice can be planned around it.
- Excused: like LOA, the week counts for nothing (out of RC or SP, accounted on the report), in the same yellow. A
  section's leader can set it on the signature, the practice mark or both. A trooper's first Cav week after being
  placed into a section (from the placement history) and a new recruit's first week (from the join date) are excused
  automatically: the card shows Excused pressed with an "Excused, automatic" badge on each half nobody has recorded,
  and any button a leader picks overrides it. The post prints `EX`.
- Owes a make-up: a trooper signed Make-up requested shows "Owes a make-up" on their card, in the review step's
  "Owes a make-up" list (with the window weeks still owed) and on Home's Needs attention, until a Make-up complete is
  recorded for that week, from a guest entry or by hand. Attended, Excused or LOA for the week settle it too.
  Section staff and up (the section's leader and every command above it) can set Make-up complete. If a trooper still
  owes the week's make-up when the section's report is sent, the send dialog lists them under "Make-ups still owed" and
  will not send until the leader answers "Made it up" or "Did not" for each. "Made it up" records Make-up complete
  (an audited mark, in the sender's name) before the numbers freeze; "Did not" leaves the mark. The answers are frozen
  with the report and shown on it as "Make-ups checked at send".
- Capture review: when game-server data was captured for the practice, marks it made show their source, and the leader
  can confirm the practice ("Confirmed by ..."). Editing a mark by hand afterwards clears the confirmation.
  On CRCON a player counts as present when one of their sessions overlaps the practice. The sessions come from each
  player's profile; if a profile cannot be read or has no sessions, the player's first and last seen times are used instead.
- Unmatched players: players the capture saw that no trooper is linked to. Link one to a trooper (this also saves their
  game identity and marks them attended), dismiss them, or restore a dismissed one. An ID the server reports in two letter cases is one
  player (an Epic ID is taken in lower case). A player linked to a trooper who has left is listed
  under "Departed", with the trooper named; they are not marked and there is nothing to link. A player of a retired game
  has no Link, since a retired game takes no new links; they can still be dismissed. A player seen through
  BattleMetrics while CRCON could not be read, for whom BattleMetrics had only its own player number, says so and has
  no Link either: that number is not their game ID. Linking never swaps a trooper's Steam or Epic ID for an ID of
  another kind; change that on the Game identities page if they really changed platform.
- Telemetry banner: says when the section's game (the game it plays, else the one most of it has linked) has no capture source set up (or no training server selected), or
  when the last capture failed, naming the feeds tried. A capture that broke on an error inside the portal says so
  instead of blaming a feed. "Try again" queues a new capture for the week so far.
- Guests: add a serving trooper from another section who attended this practice, or remove one you added. See "JSAM
  guests" and "Makeups". Staff who said they attended (a staff practice entry, from their staff report or their milpac)
  are listed there too: "Staff guest" when the section is under the platoon or company HQ they were on when the week
  ended, "Guest" otherwise. They are shown only: they never count in the section's numbers, KPIs, strength, JSAM counts
  or makeups, are never on its roster, and the host leader cannot remove them.
- Fireteams: mark fireteam leaders (FTLs), put troopers in their fireteams, and choose how the post shows them. A company
  command can set a policy for all its sections (show or hide FTLs, leaders first, and whether SL and ASL may lead a
  fireteam), which overrides the sections' own choices while it is set.
- Report deadline banner with a link to the section's report.

Only troopers placed in a section are on a roll call. HQ staff, staff departments, ELOA, Reservists and New Recruits are
on none.

The running week's roll call lists who is placed in the section now. A week that has ended keeps the roster it ended
with, from the placement history, and so does everything that counts it: the section picker's progress, Home's step
matrix and unit table, the report chain, and a capture's pre-marks. A trooper who moved to another unit or left the
Cav after that week stays on it, can still be marked there and given a reason on its report, and counts in its KPIs and strength, while the section they
moved to cannot mark them for that week. A trooper who moved partway through a week is on the roll call of the section
they moved to, and their mark at either section counts (see "Makeups"). Such rows carry a small note: "Moved out",
"Departed", or "Joined" for one who moved in during the week. A trooper placed before the placement history began
(27Oct26) and never moved since is in their current section for every week since they joined the Cav: a recruit
is on no roll call of a week that ended before their join date. The roster sync records when a trooper
leaves the Cav; one who left before it began doing so has no date and is on no past week's roll call.

### Reports

- Roll-up workspace (`/reports`): the tree of every line unit in your scope for one Cav week (the current week and the
  12 before it), each with its send status, KPIs and change from last week. A section leader goes straight to their own
  report.
- A unit's report (`/reports/<unit>/<week>`):
  - Section report: KPIs and the change from last week, a "Ready to send" checklist (Roll call complete, Practice marked,
    Unaccounted troopers explained, Training subject filled, Notes reviewed), a reason for each unaccounted trooper from
    a fixed list, the training subject (last sent subject offered as a suggestion), notes, history and an 8 week trend.
  - Suggested reasons: where the portal's data says why a trooper is unaccounted, their reason is pre-selected:
    "Departed, no longer on the Cav roster", "Moved out of the section this week" (they left the section during the
    week and were not back in it when the week ended), or "LOA posted, the post needs review" (a leave post the LOA
    sync could not read), in that order when more than one applies. A suggestion is not saved, previewed or sent, and
    its check stays open, until the leader chooses "Keep suggested reason" or another reason; "Send anyway" leaves it
    out. A reason already saved is never replaced.
  - Platoon and higher: the same, but the KPIs are the sum of the direct children's sent reports, with each child's
    status. The "Every unit has sent" check names who is holding up the chain. A child with no troopers, or with only
    HQ staff, is not one of them (see "Units with no troopers").
  - Platoon training topic: the platoon's chain of command sets a subject for its sections for the week, either as a
    default the sections can change or enforced (locked on every section's report).
  - Monthly training plan (`/reports/<platoon>/plans/<YYYY-MM>`, "Training plan" on a platoon's report): the
    platoon's chain of command gives each Cav week of the month a training subject (every week whose Saturday falls
    in the month) and chooses whether the subjects are locked on the section reports. Each save goes to the company
    for approval. Company command (or above) approves it or sends it back with a comment the platoon leader sees.
    Approving writes each week's subject as the platoon training topic, so every section's report starts with it. A
    change after approval needs approval again; until then the approved subjects stay. A week the approved plan wrote
    can no longer be set, unlocked or cleared by hand on the report, only through the plan. Approving a revision that
    leaves a week blank clears the topic an earlier approval of the same plan wrote there (a topic set by hand stays).
    A week whose platoon report is sent, or that is before the week floor, keeps its subject. Above a platoon, the same address lists every
    platoon's plan for the month and where it stands.
  - Send: freezes the KPIs, notes, subject and reasons with a reason for the record. When checks are open or children
    have not sent, the button becomes "Send anyway"; missing children are recorded and their numbers stay out.
  - A sent report is read back exactly as frozen and never recalculated. Sending a section's report locks that week's
    roll call.
  - Weeks before the week floor open read-only.
  - HQ staff reports (a platoon's, company's or battalion's report only): each staff member on that HQ when the week ended (placed where they are now when their placement history began after it), with
    "Not sent", "Attended 2 practices", the FTX's name and date, an office hour, or "Did not attend", with other events and notes, linked to their staff report. Read only and live: it is not
    frozen with the unit's report. It lists only the staff whose report the viewer may read (see "Staff report").

### Unit switcher and View as

- Unit switcher: the block at the top of the sidebar names the top unit of your scope (several for a staff grant over
  more than one unit, empty line units left out as in the list), and the breadcrumb starts there. Open it to list every unit in your scope as a tree, sections
  included and empty line units left out. Choosing one opens the page you are on for that unit (its roster, a
  section's roll call, or its report or roll-up for the same week), else that unit's roster. It is navigation only: it
  never changes what you can see or do.
- View as (in the top bar): previews the portal as a unit's leader, or for an Admin as a person. Its list of units
  leaves out empty line units, except the one you are viewing as now. Starting or switching
  a preview opens the page you were on for the unit you picked when there is one (its roster, its roll call or the
  roll call landing, its report for the same week or the reports landing, its readiness matrix, and its roster from a
  trooper page), else the same page (Home only with no page to go back to). In a person preview, the roster and the
  roll call open where that person would land. Leaving the preview goes back to the page you were on. The gold bar says
  the preview is read only.
- While view as or act as is on, a page the previewed or acted-as identity may not open (a 403 or 404 for them) shows
  the shell with a short blocked note ("The trooper you are viewing as does not have permission to see this page.", or
  unit, or billet, or acting as) and the bar to leave, never the page's data.
- Both follow act as and View as: with either on, the switcher lists the units of the scope you are seeing.
- Act as (user menu): runs the portal as another person, writes included, under a red bar. A leader's picker lists the
  serving billet holders below them and the billets junior to theirs in their own command team (an SL their ASL, a PL
  their PSG, a company CO their XO and 1SG, the RCO the RXO and RCSM), never anyone above or beside them. The full
  rules are in [permissions.md](permissions.md#act-as). Starting and stopping keep the page you were on.

### Units with no troopers

A line unit with nobody serving placed in it, or in any unit under it, has nobody to report on. Until someone is placed
in it, it is left out of:

- the report chain: it is not a child that has to send, so it is not in "Every unit has sent", not in "Sent without"
  and not listed as "Not in at send", and the roll-up workspace does not draw it;
- the roster's ORBAT and unit picker, the sidebar's unit switcher, and the View as list (the unit you view as now stays
  listed);
- the roll call's landing (its section picker still lists them, marked "No troopers");
- Home's progress lanes and step matrix, the unit table, the sparkline's "every section sent" test, and Needs attention.

It comes back on all of them as soon as a trooper is placed in it or under it, by the roster sync or by an Admin, with
no other step. Troopers on leave count: a section whose only members are on LOA still counts. A report already sent
keeps what it froze, so a unit listed as missing at send stays listed on that report. Org structure hides them too, by
the same rule, behind a "Show empty units" checkbox, so an Admin can still open one to rename, move or delete it. A
shown empty unit is muted and does not say "Lead: vacant".

HQ staff count for the roster and Home, but not for the report chain, because they are on no roll call and have no
report of their own. A platoon whose only troopers are its PL and PSG, with every section empty, is shown on the
roster and the unit switcher, but it has no report to send: it is not a child its company waits on, Home's unit table
and Needs attention leave it out as the report chain does, and its own report says there is nothing to roll up. The same goes for a company whose only troopers are its HQ. In
the report chain, a section counts when it has troopers, and a platoon, company or battalion counts when a unit under
it does.

The report chain asks about the report's own week: a section counts for a week when that week's roll call lists
someone (from the placement history, a trooper who ended the week in it), and any unit counts when it sent that week's
report. So a section a trooper left partway through a week, with nobody else in it, is not one that week waits on. Who is placed now counts only for
the running Cav week. So a section stood up or filled after a week is not one that week waits on, and a section that
sends and is then emptied by a move still rolls up into its platoon for that week, keeps its platoon and company in that
week's chain, and drops out from the first week it is empty. A trooper placed before the placement history began
(27Oct26) and never moved since counts in their current section for every week.

A trooper who leaves the Cav still counts in the chain for every week that opened before they left, as they stay on
those weeks' roll calls (see above), so a section whose last trooper leaves drops out from the first week after they
went. One who left before the roster sync recorded the date counts for no week. A week a section sent stays in the
chain. A trooper an Admin left unplaced still counts through their earlier placements, though no past roll call lists
them.

The same week rule decides which sections Home's step matrix lists for the running week, which sections each past
point of Home's sparkline waits on, and which of your top units the roll-up workspace and Home start from for a week
(a unit with troopers now, or one that took part in the week). The roster, the unit pickers and the unit switcher
always show who is placed now.

### Game identities

Link each trooper in your scope to their in-game identity, one per game. The games are the ones an Admin keeps on the
Games page (Hell Let Loose (WW2), Hell Let Loose: Vietnam, Arma Reforger and WARDOGS to start with; Vietnam is not
captured until an Admin picks its interface); a retired game keeps its links but takes no new ones. A trooper can hold
one link on each game, and the same ID on two games is not a collision. A "needs a link" queue lists troopers missing a
link on any game, so one linked only on WW2 shows as missing Vietnam. The capture uses these links to recognise players. A
section's game is the game it plays as set on Org structure (its own or the nearest unit's above), and with none set, the
one most of its troopers have a link for. Add identity opens on the trooper's usual game while it is missing: the game
their unit plays when it is not retired, else the one most of their unit has linked, else the first game they are
missing. Every serving trooper placed in your scope is listed:
section members, HQ staff and group members (ELOA, Reservists, New Recruits, staff departments). Linking someone
outside a section only lets the capture recognise them; attendance is still marked in sections only.

The ID is checked against the shape of its kind when it is saved: a Steam ID is 17 digits starting 7656119, an Epic ID
32 characters of 0 to 9 and a to f (stored lower case, as the game server reports it). Other kinds, such as Xbox ID or
Arma's Player ID, take any value. The hint under the ID field says what the chosen kind looks like. An identity saved
with the same ID and kind it already has is not checked again, so an older one that does not fit its kind can still be
renamed; changing its ID or kind checks it. Names and IDs are
stored without hidden characters (zero-width spaces, direction marks and the like), normalised and trimmed; a value
with nothing left is refused. A name that mixes alphabets, such as Latin and Cyrillic, is saved with a "Check this
name" marker, since a letter may be a look-alike. Linking an unmatched player from the roll call takes the ID the server
reported as it is, saved as the first of the game's kinds whose shape it has (an Epic ID as an Epic ID), or as the
game's default kind when it fits none.

### Counseling (DCS and LOR)

A leader writes a Developmental Counseling Statement (DCS) or Letter of Reprimand (LOR) in the portal, field by field,
following the wiki procedure end to end (#565). On a trooper's milpac page the Counseling panel holds their records,
drafts first, then newest first. Who may start and see which is in [permissions.md](permissions.md#counseling-dcs-and-lor);
who may start one is unchanged from #381. A trooper sees their own shared records on their own page, never a draft,
and never starts, withdraws or deletes their own; an Admin by the flag may still withdraw or fill in a shared record of their own. Acting as someone never shows their
records through this: only what the acting identity's command shows.

- **Draft**: **New DCS or LOR** asks for the type and what it is for (a missed section practice or roll call, or other
  conduct) and opens the record as a case file at `/counseling/{id}`: the steps beside the regiment's form
  (7CAV-R-042 for a DCS, 7CAV-R-043 for an LOR) filled in live; on a phone one step at a time. The leader fills in the
  form's fields: the evidenced infraction and previous instances, the regulation, order or SOP, the notes of
  discussion, the plan of action (authority, misc) and comments (authority, misc), and on an LOR the four recommended
  consequences (a removal names its position) and special punishments. A draft saves from any step (**Save draft**,
  and **Continue**), shows when it was last saved, and warns about unsaved changes. It is seen only by its issuer,
  their chain and Admins, and deleted freely (`counseling.draft_deleted`). Section 1 (rank, Last.F and billet of the
  trooper and the authority) comes from milpac, live while a draft.
- **Meeting invite**: three Zulu windows; the wiki's "Meeting: …" PM goes to the trooper from the leader's own forum
  account, and the record shows the 72-hour meeting window. Required before an LOR is shared; a DCS may skip it.
- **Share**: blocked, saying what is missing, until the infraction and regulation, the notes of discussion, the
  leader's plan, the Date of Letter and the meeting confirmation are in (and on an LOR a consequence and the invite).
  Sharing locks the leader's part, snapshots Section 1 and the Date of Letter, and sends the wiki's "After-Action: …"
  PM with a link to the record. An LOR is also sent to the S1 1IC and 2IC (`config/counseling.php`) and the oversight
  officer of the trooper's battalion, and the unit whose commander would hear an appeal is fixed (one level above the
  issuing unit).
- **PMs**: every PM goes from the acting user's own forum account (leader, trooper, appeal authority). The portal asks
  for the forum's `conversation:write` scope only when someone is about to send one, checks the token is theirs, and
  keeps it encrypted in their session only. A PM the forum does not send leaves the record unchanged, with the reason.
- **Withdraw**: before it locks and before an appeal, the issuer's chain or an Admin takes a shared record back to
  draft with a reason (`counseling.withdrawn`); the trooper is told by PM, and their answer, if any, is dropped.
- **The trooper's answer**: on Home (with a countdown) and on their own page, the trooper fills in their plan of action
  and comments and, on an LOR, DO or DO NOT wish to appeal, with a reason to appeal; once (`counseling.responded`).
  An LOR is answered within 24 hours of the After-Action PM, a DCS until it locks; without an answer the record shows
  No response and stands.
- **Appeal**: DO appeal sends a PM from the trooper to the commander one level above and queues the LOR on their
  **Appeals** page (sidebar badge, 72-hour due time). They record HAVE AMENDED (the consequence edited) or HAVE NOT
  AMENDED, with a required reason; their rank, name and billet are snapshotted; the decision is final, locks the LOR,
  and goes by PM to the trooper, the issuer and S1 (`counseling.appeal_decided`). The consequence as first written
  stays in the record (`appeal_original`). An appeal not decided in 72 hours lapses and the letter stands.
- **Lock**: a record locks 72 hours after it is shared, an LOR also when its appeal is decided, and never while an
  appeal is pending. Then nobody withdraws it, Admins included; only the Regimental Executive Officer, signed in as
  themselves, overturns a locked LOR, with a reason (`counseling.overturned`). An overturned LOR stays on file, muted,
  and no longer counts toward any flag, the no favorable action notice or the forecast.
- **Windows**: a DCS is active for 30 days after it is shared (the trooper and the chain see it), inactive from day 31
  to day 90 (Platoon Leader and up and Admins; still counts), and expired from day 91 (Admins only; no longer counts;
  kept for good). Worked out on every read, no job. An LOR stays visible permanently.
- **Document**: `GET /counseling/{id}/document` checks access and audits the download (`counseling.downloaded`, with
  the SHA-256 of what was served). A record written in the portal answers the form's field values as JSON, and the
  browser fills them into the checked-in template (`resources/counseling/`) with pdf-lib and saves the PDF; the server
  keeps no generated file, so there is nothing to purge. A record kept with a source PDF (filed before #565, or
  imported) downloads that file, always as an attachment, `application/pdf`, `no-store`.
- **Records from before**: every record filed before #565 keeps its uploaded PDF as its source document and counts as
  shared and acknowledged when it was filed. Its fields can be filled in under **Fill in from the PDF** on its page. Admins import older records
  from Drive links or PDFs on Settings > Counseling import, or with `php artisan counseling:import`.
- The regulations' rules, measured between records by their dates as the 7Cav wiki does: each record is weighed
  against the ones dated before it, and a record that crossed a threshold shows the flag it earned, which stays with
  it as time passes. Other conduct: a repeat within 90 days of a DCS (an LOR is next if it was for the same issue), and
  an LOR within 30 days of another (the one repeat LOR, an Article 15, if it was for the same issue); a DCS within 30
  days of an LOR is flagged too. Missed section practice or roll call: a second miss within 30 days of the last (an LOR
  is called for; from 31 days, another DCS), a miss within 60 days of an LOR (a second LOR), and a second LOR within 60
  days of the first (an Article 15, company then battalion). LORs on any matter: a 2nd within 90 days (removal as SL or
  ASL is authorized) and a 3rd (an Article 15). "Within" includes the last day. For 30 days after an LOR the panel
  shows "No favorable action until" the date. The start form says what flags a new record would earn today. Flags are
  worked out from the records the viewer may count, so they never give a record away; a draft counts once shared.
- A trooper who has left the Cav: their records are read-only for everyone, Admins included; whoever saw the records
  still sees and downloads them. If the trooper serves again, the records can be worked on again.
- Google Drive is no longer used. Copies made before #565 stay on the Drive.

### Staff practices

Staff (troopers placed on an HQ node, or in a staff department such as S6) are on no section's roll call. ELOA,
Reservists and New Recruits are groups but not staff. On a staff member's milpac page, "Section practices attended"
lists the section practices they attended, newest week first; the roster's trooper sheet shows the same list, read only.

- Add one: the section (any section of the regiment with troopers in it) and the Cav week (this week and up to 11 back, never before the
  week floor; the server refuses an older week too). One entry per staff member per section per week. A practice holds
  one entry per trooper of either kind, staff or guest: a trooper listed there as a guest before they moved to the
  staff is told so, and a trooper with a staff entry who has since moved into a section cannot be added there as a
  guest, with the refusal saying where the other entry is. Who may is in
  [permissions.md](permissions.md#staff-practices). Each entry says who entered it; one the staff member entered
  themselves reads "Self-reported by".
- Game server: when a practice capture sees a staff member through a linked game identity, the practice is listed as
  "Seen on the game server". The capture keeps them on that practice's own record of who it saw, as it does every
  linked trooper from elsewhere; it credits nobody. Its window is the whole Cav week so far, so this means they were on
  the server that week. A practice with an entry shows the entry.
- Remove one: the same people. A game server sighting cannot be removed by hand. An entry on a section that had no
  practice that week creates one; removing the last such entry removes it again when nothing else is on it (no mark,
  signature, guest, capture, or posted or confirmed roll call), so it never keeps an Admin from deleting the section.
- Lock: once the HQ the staff member was placed on that week has sent its weekly report for it, that week's entries no
  longer change. It goes by where they were placed that week (the placement history), not where they are now, so a
  move afterwards neither opens nor locks a week; a week they spent in two units is locked by either. A staff
  department sends no weekly report, so its weeks stay open.
- Lock: once the staff member has sent their own staff report for a week (see below), that week's entries no longer
  change either, from anyone.
- An entry lists the staff member beside the host section's guests (see "Roll call"), shown only: it never puts them on
  the host's roster, never counts in its numbers, reports or JSAM counts, and gives no makeup. Adding and removing are
  audited.

### Staff report

Platoon, company and battalion HQ staff attend at least one section practice a week, preferably in their own unit,
and each files their own weekly staff report saying whether they did and which. "Staff" here is a serving trooper
placed on a platoon, company or battalion HQ node (the PL, PSG, CO, XO, 1SG, the battalion command team and anyone
else placed there, ACD included). Regiment HQ, staff departments, ELOA, Reservists and New Recruits file none. It is
not a line in the unit's report. It replaces the regiment's Weekly Staff Report and Weekly Battalion Report forms.

- Where: "Staff report" in the sidebar for platoon, company and battalion HQ staff, and the reminder on Home. Both open
  `/staff-report/<trooper>/<week>` for the last week to have ended, the one whose report is due; the arrows move
  between weeks. The running week opens read-only, saying from when it can be sent.
- Filling it in: the week, its dates and the deadline sit at the top. The form asks one question at a time, with a
  row of numbered steps above it and Back and Next below; a step opens once every step before it is answered. First
  "Did you attend a section practice this week?" as two large choices, Yes and No. Yes moves on to "What was it?":
  large choices for "Section practice", "FTX" or, for battalion HQ staff only, "Office hour". Then its details: for an
  SP or an FTX the sections, grouped nearest first (their own HQ, then its company, then its battalion, then the rest),
  each in order of battle (battalion, then company letter, then platoon, read from the callsign; units outside
  the regiment's numbered battalions, such as DEVCOM, last) under a platoon heading, with a search. When the list spans more than four platoons, every
  platoon but the staff member's own starts collapsed and opens on a click; a search opens them all. Only sections that play the HQ's game are offered (the unit's game, inherited down the tree): an HLL
  HQ sees HLL sections. A section, or an HQ, with no game on its chain is never left out for it. An SP needs one or
  more sections. An FTX needs its name (up to 120 characters) and its date, a day in that Cav week; its sections are
  optional, as an FTX may span several. An office hour is an hour of office hours held in place of the hour at a
  section practice: it needs its date, a day in that Cav week, and a short note (up to 200 characters), takes no
  section, and is self-reported, never checked against the game server. A platoon or company HQ staff member who sends
  one is refused. No goes straight to the last step, "Notes": free-text notes (up to 1000 characters) and other
  events attended (up to 500), optional for everyone. A summary card beside the steps (under them on a phone) reads the
  answer back as it will be sent, shows each picked section as a chip that removes it, says what is still missing, and
  holds "Send staff report". When the Notes step was never opened, Send first asks "Send without notes?" with
  "Send anyway" and "Go to Notes". Which HQ level the report is for (platoon, company or battalion) comes from the HQ and is
  never asked. The week's staff practice entries are ticked already, each saying who entered it, and the form then
  opens on the sections. A practice the game server saw them at says "Seen on the game server" and is never ticked
  for them: the capture reads the whole Cav week, so it only says they were on the server.
- Sending: only once the Cav week has ended (from Saturday 00:00 UTC), so the HQ it counts for is the one they ended
  the week on. Only the staff member, signed in as themselves, sends it. Nobody sends it for them, Admins included, and
  never through act as or view as. It is due when the unit reports are, 23:59 UTC on the Saturday after the week. A
  sent report is final, as a unit's report is: there is no edit and no second send. The type, the FTX's name and date,
  the office hour's date and note, other events and notes are frozen with it. The sent report reads back with a check,
  when it was sent, and that it is final. There is no draft: the picks stay
  on the page until it is sent.
- What sending writes: the staff report, and the week's staff practice entries set to exactly the practices picked
  (an entry for a section not picked, one a leader entered on the milpac say, is taken off, and the page warns before
  sending). "No" is a sent report with no practice, and takes off the week's entries. A guest entry from before they
  joined the HQ stays as it is. Each pick lists them on the host's roll call as a staff guest or a guest (see
  "Roll call"). Sending is audited as `staff_report.sent`, and each entry it takes off as `staff_practice.removed`,
  naming who had entered it.
- Game server check: sending a section practice report compares it with the game server's presence data and freezes
  the result on the report. When at least one picked practice was captured that week and none of the picked captures
  saw the staff member through a linked game identity, the report is still sent but carries a red "Self-report
  conflicts with CRCON data" (the capture system of the section's game: CRCON for Hell Let Loose, BattleMetrics or
  Nightwatch for others, "game server" when the game has none). When no picked practice was captured it reads "No
  CRCON data" and is never flagged. "No", an FTX and an office hour are not checked. The check shows on the sent
  report, on the staff reports overview row and on the unit report's staff panel, and later captures do not change it.
- The HQ's own weekly report does not lock it: a PSG still sends theirs after their platoon's report went. Once sent,
  that week's staff practice entries are locked.
- Past weeks: a week that has ended goes by who was on the HQ when it ended, from the placement history. A staff member
  who has since moved into a section still sends the past week's; someone who joined the HQ after it ended has none
  for it. The HQ staff panel on a past week's report keeps someone who has left the Cav since when they were still
  serving as the week ended, or sent their report for it. Weeks before the week floor open read-only.
- Who reads it: the staff member, whoever reads the unit above their HQ (its command and the chain above, a staff grant
  there, an overseer or the Regimental HQ read), and Admins. A peer on the same HQ (a PL and their PSG) does not. See
  [permissions.md](permissions.md#staff-report).
- Game server check: a reader (never the staff member about their own report) sees a small warning icon, with a
  tooltip, on the overview row, the HQ staff panel and the report page when the practice capture disagrees with a sent
  report: a section whose practice they reported was captured that week and the capture did not see them, or a captured
  practice that week saw them (through a linked game identity) at a section they did not report, all of them when they
  answered No. A practice with no capture says nothing, and an FTX or an office hour is never compared. The overview
  reads it for every row at once.
- Staff reports overview (`/staff-reports?week=<date>`): for anyone who reads some HQ staff's reports, the sidebar item
  reads "Staff reports" and opens this page instead of "Staff report". It opens the last week to have ended, with
  arrows between weeks, and lists every platoon, company and battalion HQ staff member whose report the viewer reads,
  grouped by HQ down the tree, each with billet, status ("Sent", "Not sent yet", or "Not due yet" for the running
  week), what they attended (SP and sections, the FTX's name and date, or an office hour), other events, notes,
  "1 SP met" or "Missed" (an FTX and an office hour count), and the sheet marker. The whole row opens their report
  (it tints on hover); by keyboard the name is the link, with a focus ring. Filters
  narrow it to "Not sent" or "Missed", each with its count. On a phone each line stacks into a card. A battalion HQ's staff are read by regiment
  command and Admins. Each HQ lists who was on it when the week ended,
  as the HQ staff panel does. The viewer's own report, when they file one that week, sits on top with "Send your staff
  report" while it is not sent. A PL or PSG, who reads only their own, keeps "Staff report" and gets a 404 here. Read
  only. In view as it shows what the target would see, without an own report.
- Unit health: the overview, and a reader's view of one report (never the staff member's own page), show a panel for
  the HQ's unit that week: its sections' SP attendance and roll call rate (the KPI window, the same numbers as Home and
  the roster), how many are in roll call of its headcount, the at-risk count, and how many of the HQ's staff sent a
  report that keeps to the one practice rule, each with a sparkline of the last 8 weeks (a week with no data is a gap).
  The overview shows one HQ at a time, the first listed to start with, with a link per listed HQ to switch
  (`?hq=<id>`); an HQ the viewer does not read falls back to the first. Its queries do not grow with the unit.
- Reports sheet: when the env names a Google Sheet (`GOOGLE_SHEETS_REPORTS_SPREADSHEET_ID`), each sent report is
  appended to it as one row by a queued job (`PushStaffReportToSheetJob`, up to 5 tries), in the column order the old
  Google Forms left: platoon and company reports to the staff tab, battalion reports (ACD's billet in "ACD Billet") to
  the battalion tab. The push never blocks or fails the send. The report page and the overview say "Sent to the sheet"
  once it is there, or "Not sent to the sheet yet" while the sheet is set up and the row is pending. Without the env
  key nothing is pushed and no marker shows. See [operations.md](operations.md#weekly-staff-reports-and-google-sheets).

## Changelog

- `/changelog`, under Help in the sidebar, for everyone signed in: what changed in the portal, written by hand, never
  pulled from git. Dates (Zulu days, shown as forum dates like 30Oct26) are listed newest first, each with its entries
  as a compact list. An entry is one line of text (up to 300 characters). One with additional information (Markdown, up
  to 2000 characters, shown with any raw HTML stripped) has a chevron that opens it; it starts closed.
- The page has two columns, stacked on a phone with Major changes first. Major changes lists only the entries flagged
  major. Full changelog lists every entry, major ones included with a Major badge, and starts collapsed unless the
  viewer opened it before; that choice is remembered in the viewer's browser only (localStorage), and the page works
  without it.
- The sidebar shows a dot on Changelog while an entry was written after the viewer last opened the page. Opening it
  clears the dot, but not during a view-as preview or while someone acts as the viewer.
- Only the changelog editors (`changelog_editors` in `config/portal.php`, matched without case), signed in as
  themselves, write it. **Add** takes one date (today in Zulu by default) and up to 50 rows at once, each with an
  **Major change** tickbox (off, so the entry is full changelog only) and an optional **Add additional information**
  tickbox; Enter in a row adds a row below it. Each entry can be edited or
  deleted on its own (delete asks to confirm, with a reason). Bulk import takes pasted Markdown: a `## YYYY-MM-DD`
  heading per date, a `- ` bullet per entry, and an indented block under a bullet as its additional information. A bullet that starts with `[major]` (any case) is
  flagged major and the marker is dropped (text
  after the date on the heading is taken as the first entry). It previews the result with any errors, can skip entries
  whose date and text already exist, and saves them in one batch. Writes are audited as `changelog.created` (one per
  added batch), `changelog.updated`, `changelog.deleted`, `changelog.imported` and `changelog.outdated_dismissed`.
- After each redeploy (a new asset build version) the editors see a bright blue "Changelog outdated" bar with "Add entry" and
  "Dismiss". Adding an entry, an import, or a dismiss clears it for both editors until the next deploy.

## Settings menu (Admins)

| Page | What it does |
| --- | --- |
| Org structure | Two cards: the chain of command (the regiment, its battalions including ACD and DEVCOM, and everything below) and the regimental staff (the portfolios, their departments and the holding groups, New Recruits behind "Show New Recruits"), each unit with its callsign (sister units' callsigns line up in one column; an HQ row keeps its own spacing), its lead (commanding billet holder, portfolio officer, DEVCOM's 1IC or acting lead, or vacant) and headcount. Line units with nobody in them or under them are hidden until "Show empty units" is ticked. ACD and DEVCOM also show under Reserve & Development Command in the staff card, and DEVCOM Staff under DEVCOM in the chain of command, as reference rows that open the unit where it really sits; nothing moves. Each battalion and the Reservists show who oversees them ("Oversight: vacant" for a battalion with nobody); the overseer reads that unit and everything under it, read only, while they serve. Admins can set, change or clear an overseer (reason required). Admins can add, rename, move and delete units (a unit with anything attached cannot be deleted), run Import from Cav roster, set the game a unit plays by picking it from the Games list (every unit under it inherits it unless it has its own; a game that is not the unit's own shows muted with the unit it comes from, such as "WARDOGS from 2/A/1-7", on reference rows too, and the details panel says the same), set a free-text team label on a unit (shown next to its name), assign or remove an acting lead (reason required; only on a unit whose commanding billet nobody serving holds, and never themselves; the form is hidden on a unit a serving billet holder or portfolio officer leads), picking the trooper from a search whose box then shows the picked name, and place a trooper the sync could not place. Staff groups list their members; ELOA shows each trooper's last line unit ("was"). |
| Staff grants | Grant a non-Admin user read access to a unit and everything under it, or revoke it, with a reason. Lapsed grants (holder no longer serving) are flagged. Every billet in the Regimental HQ Cav group, and the S6 1IC and S6 2IC billets by title (the lists are `admin_billet_groups` and `admin_billet_titles` in `config/portal.php`), makes its holders Admins by billet once an Admin approves it, while they serve and hold it, wherever they are placed. Admin via billet lists the approved billets with their holders and who approved them when, each with Revoke (a reason is required); Admin billets below lists the waiting and rejected ones to approve or reject, with a reason. A billet whose title or group Cav changes waits for approval again, shows the names it was approved as, and its holder is not an Admin meanwhile. A billet moved out of Regimental HQ, or an S6 billet renamed to another title, is marked and cannot be approved. Nobody decides on their own billet. Regimental HQ staff placed on the regiment read the whole regiment without a grant while their billet is still in Regimental HQ, named in a short note under Admin via billet. The sidebar shows Admins how many billets wait. |
| Templates | Roll call BBCode templates. A company keeps named templates of its own, and the regiment default (edited by Admins only) shows in every company's list. A company's command (CO, XO, 1SG, the battalion and regiment command above, an acting lead of the company) and Admins can add a template, edit it, roll it back, switch it on or off for the company's sections, and make a switched-on one the company default. A section can keep named templates of its own the same way: choose the section under "Templates of" to list them; its command (SL, ASL, the platoon, company, battalion and regiment command above, an acting lead) and Admins can add, edit and roll them back, and only that section's roll call can use them. A section uses its leader's pick (one of its own, a switched-on company template or the regiment default), else the company default while it is switched on, else the regiment default, so a section always has a post. A platoon moved to another company gets copies of the company templates its sections used, owned and managed by the new company (named like "Training (from A/1-7)"), so its posts read the same and the old company's later edits never reach them; if the new company later switches a copy off, the roll call says which template the post now uses. A section's own templates move with it and are not copied. A non-Admin sees only the companies in their scope, opening on their own, and only the sections in their scope. Each save is a new version with a reason, listed with who saved it ("Hale.S for Pike.S" when an Admin acted as them, "Hale.S acting as Company Commander A/1-7" when an Admin held the billet); any older version can be activated again. The preview fills itself from the company's first section in your scope (sample data when there is none), or any other section you pick. The template language is a small Mustache subset (`{{name}}`, repeated and conditional blocks, `upper` and `lower`); mistakes are refused on save. |
| Games | The games the regiment plays: name, the kinds of player ID it asks for (the first is the default), and which telemetry interface reads its attendance (CRCON, BattleMetrics, Nightwatch or none). Add, edit, retire or reinstate a game; delete one only while nothing uses it. The kinds whose ID shape is checked are marked. Admins also see "Identities to check": saved identities that break the ID shape or name rules, with what to check; nothing in it is changed, fix each on Game identities. |
| Audit log | Every audited change (and every view as start and stop): who, what, when, the reason, filterable by actor, action, target, date and unit (including everything under it). |
| Activity log | The pages each user opened and when, newest first, filterable by user and date range, to retrace a bug report. Shows when an Admin was acting as or viewing as someone. Addresses and times only (sensitive query values and every sign-in query are dropped), never form contents. Kept 30 days. |
| Demo data | Only when `DEMO_TOOLS=true`. Generate 1 to 12 completed weeks of demo signatures, marks and sent reports for a unit's real troopers, or purge them. Never touches a week that holds real records. A staff practice entry on a generated week is a real record: the purge clears the week's generated marks and keeps the entry. |

Non-Admins do not see the Settings menu. They can still open Org structure, Staff grants, Templates and Games by URL, read only (Templates only for the companies and sections in their scope; a company's command can manage its own company's templates there, and a section's command its section's own).

## Background jobs

All run on the queue; a queue worker must be running. Times are UTC.

| Job | When | What it does | Configured by |
| --- | --- | --- | --- |
| Roster sync | Hourly on the hour; Admin "Sync now"; leader unit sync | Reads the Cav roster, updates troopers (rank, billet, status), places each trooper by billet, marks leavers departed. A unit sync only writes that unit's troopers. | `CAV_API_KEY`, `CAV_API_BASE_URL` |
| LOA sync | Hourly at :15 (new threads), daily at 03:30 (full re-read of recent leave) | Reads LOA and ELOA threads from the configured forum sections. ELOA start and end dates come from the linked PAF thread. Posts it cannot read are stored as "needs review", never guessed, and so is a post whose tagged forum account and typed username name different troopers. First run reads a year back, newest first, a share each hourly run (40 pages a section, ten minutes in all), carrying on next run where it stopped; until it is done, the leave note on the pages says how far back it has got. A thread that fails to read is kept and read again by id next run, so the rest moves on. A manual sync asked for while one of the same kind is queued or running queues one follow-up run after it, and the page says so in a notice. When the daily full read finishes cleanly, a stored leave whose thread is gone (deleted, hidden or moved out of the leave sections) stops counting; the row is kept and counts again if the thread comes back. Before that, each such thread is asked for by id, so one the listing missed while it paged keeps counting, and an unclear answer withdraws nothing that run. | `SEVENCAV_FORUM_API_KEY`, `SEVENCAV_FORUM_LOA_NODES`, `SEVENCAV_FORUM_PAF_NODE`, `LOA_SYNC_REQUEST_DELAY_MS`. Skipped while the key or sections are unset. |
| Practice capture | When a leader clicks "Try again" on the roll call. That button only shows after a failed capture, and nothing in the code schedules a first capture. | Reads who was on the game server during the Cav week so far, pre-marks linked troopers in the section as Attended (a leader's mark always wins), lists unmatched players. | Each game's telemetry interface, picked on the Games page. CRCON (`CRCON_BASE_URL`, `CRCON_API_KEY`) falls back to BattleMetrics; BattleMetrics (`BATTLEMETRICS_API_KEY`, `BATTLEMETRICS_SERVER_ID`); Nightwatch (`NIGHTWATCH_API_KEY`, `NIGHTWATCH_SERVER_IDS`). A game reads the training servers an Admin selected for it on the Servers page. With none selected, Hell Let Loose (WW2) on CRCON, Arma Reforger on BattleMetrics and WARDOGS on Nightwatch read the server set in env; any other game, or one of those switched to another interface, reads nothing until a server is selected, and its roll call says so. A game with no keys, or interface "none", shows "not configured". |

The capture does not record other sections' troopers as guests or makeups for now: without set practice times the
window is the whole week and the servers are shared, so being online does not mean being at that practice.

Activity log pruning runs from the scheduler itself, not the queue: daily at 04:15 `portal:prune-page-visits` deletes
page visits older than 30 days, in chunks.

The org import (Settings, Org structure, Import from Cav roster) is not scheduled; an Admin runs it. It repoints a billet mapping it wrote when Cav now lists the billet under another unit, and keeps (and counts) a mapping corrected by hand.

## JSAM guests

The Joint Service Achievement Medal is for a trooper who attends several practices of sections they are not assigned to
while still attending their own. The portal shows the evidence; it never awards.

- A host section's leader adds a guest on their roll call. The guest must be serving, placed in a section, and not in
  the host section. The picker searches by username across the whole roster and shows only name, rank and section.
- One visit per trooper per host practice.
- The roster's "Guests at our practices" panel marks a candidate when, over the last `JSAM_WINDOW_WEEKS` (default 12)
  Cav weeks, they made at least `JSAM_MIN_VISITS` (default 3) visits to the viewed unit's practices and their own
  section SP is at least `JSAM_MIN_OWN_SP_PERCENT` (default 75).
- The commander of the unit that benefited recommends the award.

## Makeups

A trooper is on exactly one roll call, their own section's. Attending another section's practice counts as a makeup for
their own section's practice that Cav week. For a week that has ended, their own section is the one whose roll call
lists them that week (see "Roll call"), so a trooper who moved since gets the makeup on that roll call, and a section
cannot add one of its own troopers of that week as a guest.

- Their own leader can mark Make-up complete by hand.
- When a host leader adds them as a guest, the portal writes an automatic Make-up complete on their own section's roll
  call for the same week, shown with an "auto" source dot. It never overwrites Attended, LOA or any mark a leader set by
  hand, and is never written into a sent, confirmed or before-floor week, or for a trooper on leave. Adding a guest
  already added by hand tries the makeup again, so a makeup that failed after the guest was saved can be finished; the
  guest is not listed twice. After a reload, the guest list says so on a hand-added guest whose makeup is missing and
  offers "Retry make-up", which does the same. It is offered only while a retry could write it: not once their own week
  is sent or confirmed, their own leader has marked them, they are on leave or no longer serve there, or an automatic
  makeup is already on any section's practice that week.
- Removing the guest takes the automatic makeup back, unless something else supports it or a leader has since changed
  or confirmed it. It is taken back from the section it was written to, even if the guest has moved section since.
- A makeup counts for the trooper's SP. An automatic makeup alone does not make their own section's practice "held".

## KPIs

One definition, used by Home, the roster, the reports and the roll-ups. The window is the last 4 whole Cav weeks
including the current one. Only troopers placed in a section are measured: for the running week, those placed now; for
a week that has ended, those on that week's roll call (placed in the section when the week ended, departed ones
included).

| KPI | Definition |
| --- | --- |
| In roll call (`strength`) | Troopers placed in sections in the unit. (The Headcount on the org page and roster also counts HQ staff and staff departments; New Recruits, ELOA and Reservists count only on their own groups.) |
| RC % · 30d | Weeks with a signed roll call (Will attend, Will not attend or Make-up requested) divided by the weeks in the window that are not on leave or excused. |
| SP % · 30d | Practices marked Attended or Make-up complete, divided by the practices their own section held that are not on leave or excused. A practice is held once a leader put an SP mark other than Excused on anyone. A week the trooper attended always counts as eligible for them. |
| At-risk | Troopers with 2 or more practices explicitly marked Not attended in the window. Unmarked is never a miss. |
| On LOA | Troopers whose leave covers the current week. |
| Makeups | Makeup marks in the current week. |
| Own section SP (JSAM only) | SP as above, except a makeup never counts as attended, and a week their own section did not hold is left out. |

Leave covering a week takes that week out of both RC and SP for the trooper. Excused works the same way, one rate at a
time: an Excused signature leaves RC, an Excused practice mark leaves SP, and an automatic Excused (a first week after a
transfer into a section or after joining) leaves whichever of the two no leader recorded. A leave post that needs review takes its
weeks out too (no miss, not in either denominator) but does not count as On LOA: the weeks of the leave dates it gives
when they can be read, otherwise the week it was posted and the next. A non-leaf unit's reported KPIs are
the sum of its direct children's sent reports.

## Technical appendix

| Area | Code |
| --- | --- |
| Routes | `routes/web.php`, schedule and console commands in `routes/console.php` |
| Pages | `resources/js/Pages/*.vue`, navigation in `resources/js/Layouts/navigation.js` |
| Roll call logic, templates, guests | `app/Rollcall/`, `app/Commands/Attendance`, `app/Commands/Guests`, `resources/js/lib/rollcall.js`, `rollcallBbcode.js` |
| KPIs, JSAM | `app/Kpi/KpiService.php`, `PracticeGuests`, `JsamCriteria`, `config/portal.php` (`jsam`) |
| Leave | `app/Loa/LoaCoverage.php`, `app/Sync/SyncLoasHandler.php` |
| Roster sync and placement | `app/Sync/SyncRosterHandler.php`, `RosterSyncScope*`, `app/Jobs/SyncRosterJob.php` |
| Capture | `app/Commands/Capture/` (`CaptureSources` maps each telemetry interface to its sources), `app/Commands/Games/`, `app/Jobs/CaptureSectionPracticeJob.php`, `app/Integration/` |
| Reports | `app/Commands/WeeklyReports/`, `app/Kpi/RollupKpis.php`, `ReportChain.php`, `resources/js/lib/report.js` |
| Training plans | `app/Commands/TrainingPlans/`, `TrainingPlanController`, `resources/js/Pages/Reports/TrainingPlan.vue`, `resources/js/lib/trainingPlan.js` |
| Staff practices | `app/Commands/StaffPractices/`, `app/Authorization/StaffPracticeAccess.php`, `app/Milpac/StaffPracticeHistory.php`, `resources/js/components/milpac/StaffPracticesPanel.vue` (stored in `cross_section_sightings` with source `staff`) |
| Staff report | `app/Commands/StaffReports/`, `app/Authorization/StaffReportAccess.php`, `app/Milpac/StaffReportWeek.php`, `StaffReportController`, `resources/js/Pages/StaffReport.vue`, `resources/js/components/reports/StaffReportsPanel.vue` (`staff_reports`; the practices picked are `cross_section_sightings` rows with source `staff`) |
| Counseling | `app/Commands/Counseling/`, `app/Authorization/CounselingAccess.php`, `app/Milpac/CounselingHistory.php`, `app/Milpac/CounselingStanding.php`, `app/Milpac/CounselingRecordView.php`, `app/Integration/Forum/` (PMs), `resources/js/Pages/Counseling/`, `resources/js/lib/counselingPdf.js`, `resources/js/components/milpac/CounselingPanel.vue` |
| Units with no troopers | `app/Kpi/Headcount.php` (`withoutEmptyLineUnits` for the roster and Home, `reportingLineUnits` for the report chain) |
