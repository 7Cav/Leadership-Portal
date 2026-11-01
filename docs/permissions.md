# Permissions

## In short

- Who you are in the portal comes from your primary billet on the Cav roster, read fresh on every page load. A
  leadership billet gives you the unit it commands and everything under it. Nobody types in roles.
- Admin comes from one of two places: the Admin flag on a portal user, set from the server console, or a Regimental HQ
  or S6 1IC or S6 2IC billet an existing Admin has approved (see [Admin by billet](#admin-by-billet)). Admins see and change everything, and
  are the only ones who can edit the org structure (leaders may add units below their own, see (13)), staff grants and the regiment default roll call template, or read the
  audit log and the activity log.
- A company's command (and the command above it) manages that company's own roll call templates, and a section's command
  (and the command above it) manages the section's own.
- A staff grant (given by an Admin) lets someone read a unit and everything under it. It never lets them change
  anything there.
- Regimental HQ staff (the Chief of Staff, the Adjutants General, the Finance Officer and any other billet in the
  `Regimental HQ` group) read the whole regiment, as a staff grant on the regiment would, while they are placed on the
  regiment and their billet is still in that group. No Admin has to give it.
  Once an Admin approves their billet, they are Admins, for as long as they hold it. The S6 1IC and S6 2IC become Admins the same
  way.
- A regimental staff officer an Admin names as a unit's overseer (on the Org structure page: an Adjutant General over a
  battalion, the RDC Commander over ACD, DEVCOM and the Reservists) reads that unit and everything under it, as a staff
  grant on it would. It never lets them change anything there.
- Every trooper may open their own milpac page, read only, with their own DCSs and LORs, whatever their billet. It opens
  nobody else's, and only for the person really signed in as themselves: never through act as or view as.
- "View as" previews the portal as a unit (or, for Admins, as a person). It only narrows what you see, and every change
  is refused while it is on.
- Platoon, company and battalion HQ staff (ACD included) send their own weekly staff report. Nobody sends it for them, Admins included.
- Writes also stop at a few hard edges whatever your rank: weeks older than the week floor, weeks whose section report
  was sent, and practices a leader confirmed.

## The command layers

| Layer | Billets (exact titles on the Cav roster) | Unit commanded |
| --- | --- | --- |
| Admin | The `is_admin` flag, set with `php artisan portal:admin`, or an approved Regimental HQ, S6 1IC or S6 2IC billet ([Admin by billet](#admin-by-billet)) | The whole tree |
| Regiment command | `Regimental Commanding Officer`, `Regimental Executive Officer`, `Regimental Command Sergeant Major` in the `Regimental HQ` group | The regiment (the whole tree) |
| Regimental staff portfolio officer | `Regimental Security Operations Officer` (SecOps), `Regimental Recruiting Oversight Officer`, `Regimental Information Management Officer` (IMO), `RDC Commander` | Their portfolio node and the staff groups under it |
| Battalion command | `<bn> Commanding Officer`, `<bn> Executive Officer`, `<bn> Sergeant Major` in a `<bn> Command` group (ACD also: `Auxiliary Commander`, `Auxiliary Executive Officer`, `Auxiliary Sergeant Major`); `DEVCOM 1IC` and `DEVCOM 2IC` in any group | The battalion (DEVCOM for its 1IC and 2IC) |
| Company command | `Commander`, `Executive Officer`, `First Sergeant` of a line company (`Commander A/1-7`) | The company |
| Platoon | `Platoon Leader`, `Platoon Sergeant` of a platoon (`Platoon Leader 1/A/1-7`) | The platoon |
| Acting lead | Not a billet: an Admin assigns a serving trooper to a line unit below the regiment | That unit (one or more), exactly like a Platoon Leader over it |
| Section | `Section Leader`, `Assistant Section Leader` of a section | The section |
| Trooper with no command billet | Any other billet | Nothing |
| Staff grant holder | Any billet, plus a grant on the Staff grants page | Read-only on the granted unit(s); write scope only from their own billet |
| Overseer | Not a billet: an Admin names a serving trooper as the overseer of a battalion or the Reservists on the Org structure page | Read-only on that unit and everything under it, exactly like a staff grant; write scope only from their own billet |
| Regimental HQ staff | Any billet the import places on the regiment: every billet in the `Regimental HQ` group (`Regimental Chief of Staff`, the Adjutants General, the Finance Officer, new ones Cav adds) | Admin once an Admin approves the billet; until then read-only on the whole regiment, with write scope only from their own billet |
| S6 1IC and S6 2IC | The `S6 1IC` and `S6 2IC` billets, by title, in Cav's `Support Attachment` group (placed in the S6 department by its `S6` code). The rest of that group (S1, S2, aides) is not Admin-eligible | Admin once an Admin approves the billet; until then what their billet in the S6 department gives them |

Notes:

- Matching is exact. A leadership title at the wrong echelon (`Section Leader 1/A/1-7` names a platoon) or any title
  not in the list gives nothing.
- The Regimental Executive Officer leads the "Executive Officer" portfolio (which holds Reserve & Development Command)
  but already commands the whole regiment.
- The default portfolio tree: Reserve & Development Command (RDC Commander) sits under the Executive Officer portfolio
  and holds DEVCOM staff and the Reservists. Recruiting Oversight holds RRD, RTC and S5. Security Operations holds S2,
  MP and JAG. Information Management holds S6. S1, S3, S7 and WAG sit directly under the regiment. An Admin can move a
  department to another portfolio; the import never moves it back.
- `DEVCOM 1IC` commands DEVCOM as its battalion commander and `DEVCOM 2IC` as its XO, so both have battalion command
  over DEVCOM, D/DEVCOM and its platoons and sections, roll call templates included. The org
  page names the 1IC as DEVCOM's lead; when that billet is vacant the lead shows vacant, and the 2IC keeps their rights.
- Regimental HQ staff read the regiment because they are placed on the regiment node itself, not because of their
  title: the org import places every `Regimental HQ` billet there, and a trooper an Admin places on the regiment by
  hand counts the same. Holding groups and staff departments (ELOA, Reservists, New Recruits, S1, S6 and the rest) are
  units of their own under the regiment, so their members do not get it. In the matrix below they read like the Grant
  column, over the whole regiment. Their own command billet, if any (the regiment command, a portfolio officer), keeps
  its write rights. It is not a grant and leaves no audit entry, so the Staff grants page names everyone who has it
  (name and billet) in a short note under Admin via billet, for Admins. The note and the scope come from the same rule
  in `ScopeResolver`. Once their billet is approved they are Admins as well ([Admin by billet](#admin-by-billet)),
  which covers this read. This placement rule is only for the read: being an Admin by billet does not look at
  placement.

- Oversight is portal-owned: Cav's roster does not carry it. One overseer per unit, a battalion (1-7, 2-7, 3-7, ACD,
  DEVCOM) or the Reservists, and nothing else for now. It is kept by trooper, so it holds from the overseer's first
  sign-in. It counts only while the overseer serves; cleared, or the overseer off every roster, it is gone on the next
  request. In the matrix below an overseer reads like the Grant column, over the unit they oversee. Setting, changing
  and clearing it are Admin only, with a reason, in the audit log (`unit.oversight_set`, `unit.oversight_cleared`).
  None is set by the portal itself: an Admin enters each one.

### Admin by billet

Every billet in the `Regimental HQ` Cav group, and the `S6 1IC` and `S6 2IC` billets by title, can carry full Admin
rights, tied to the billet, not the person. An existing Admin confirms each billet first. The group and titles are listed in
`config/portal.php` (`admin_billet_groups`, `admin_billet_titles`); changing either list changes who can be an Admin, so it goes through review
like code.

- **Who.** A trooper is an Admin by billet while all of these hold: they serve (combat, reserve or ELOA roster), an
  Admin has approved their billet, the billet still has the title and the group that Admin confirmed, that group is
  still one of the Admin billet groups or that title one of the Admin billet titles, and the trooper's own synced billet title is that title. Where they are placed
  does not matter: the S6 1IC and 2IC sit in the S6 department, and a Regimental HQ billet holder placed elsewhere counts too.
  A billet can have more than one holder (two Adjutants General); each is an Admin.
- **Confirmation.** The org import lists every billet in those groups under Settings, Staff grants, Admin billets, as
  waiting for an Admin. Nothing is ever approved on its own, and every billet starts out waiting. Any Admin, by flag or
  by an approved billet, can approve or reject one, with a reason that goes to the audit log (`admin_billet.approved`,
  `admin_billet.rejected`). Approved billets move up to Admin via billet, with their holders and who approved them
  when; revoking one there is the same audited rejection. Nobody can decide on the billet they hold themselves. Admins
  see a count beside Staff grants in the sidebar while any billet waits.
- **Rename.** Billets are tracked by Cav's position id, not by name. When the org import or the roster sync sees a
  billet's title or group change, it goes back to waiting and its holder stops being an Admin on the next request,
  until an Admin approves the new names. The page shows the names it was approved as under the new ones. A rejected
  billet stays rejected until a name changes, then waits again. A billet moved out of the Admin billet groups cannot
  be approved there and is marked as such; an approved billet whose group is taken off the list stops counting.
- **Losing it.** Out of the billet, no longer serving, the billet renamed or moved, or revoked: Admin stops on the next
  request. Nothing is stored on the user: the `is_admin` flag stays as it is and only holds console Admins.
- **Exactly an Admin.** A billet Admin is treated as any Admin everywhere: pages, writes, view as and act as. Their
  audit entries name them as the actor, as for any Admin. A leader cannot act as them, and an Admin acting as them gets
  their non-Admin view only, as for an Admin by flag.
- `DEVCOM Lead` commands nothing by billet. An Admin makes them acting lead of the DEVCOM starter platoons they run.
- Every billet of a command team has the same scope: the XO, 1SG, PSG, ASL, SGM and CSM command their unit exactly as
  its CO, PL or SL does. Only the commanding billet is shown as the unit's lead (#444); that is display, and a vacant
  commanding billet takes nothing from the rest of the team.
- Every layer's scope is the commanded unit plus everything under it. A company commander therefore has the same
  rights as the platoon and section leaders in that company, on every unit in it.

## Permission matrix

Column key: Adm = Admin, Rgt = Regiment command, Pf = portfolio officer, Bn = Battalion command, Co = Company
command, Plt = Platoon Leader or Platoon Sergeant, Act = acting lead, Sec = Section Leader or Assistant Section
Leader, None = trooper with no command billet, Grant = staff grant (on the granted units), VA = Admin in view as.

Cell key: All = the whole tree. Own = the unit you command and everything under it. Read = can look, cannot change.
No = refused (the page answers 403 or 404, or the control is not shown).

### Pages you can open

| Page or read | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Home dashboard (line units in scope) | All | All | Empty (1) | Own | Own | Own | Own | Own | Empty | Read | Target |
| Roster, trooper sheet, readiness matrix | All | All | Own | Own | Own | Own | Own | Own | Empty | Read | Target |
| A trooper's milpac page | All, plus unplaced | All | Own | Own | Own | Own | Own | Own | Their own only (2) | Read | Target |
| Find trooper (shell search) | All, plus unplaced | All | Own | Own | Own | Own | Own | Own | Themselves only (2) | Read | Target |
| Roll call (sections in scope) | All | All | None (1) | Own | Own | Own | Own | Own | Empty picker | Read | Target |
| Reports and roll-ups (line units) | All | All | None (1) | Own | Own | Own | Own | Own | None | Read | Target |
| Game identities (every serving trooper placed in scope: sections, HQ staff, groups) | All | All | Own | Own | Own | Own | Own | Own | None | Read | Target |
| Settings menu in the sidebar | Yes | Templates only | No | Templates only | Templates only | Templates only | Templates only | Templates only | No | No | Only if target is an Admin person |
| Org structure page (whole tree and unit leads) | Yes | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes |
| Org structure: members of staff groups, ELOA, Reservists, New Recruits | All | All | Own | Own | Own | Own | Own | Own | No | Read | Target |
| Org structure: troopers the sync could not place | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Staff grants page (every grant; the username picker, the Admin billet lists and the Regimental HQ staff note only for an acting Admin) | Yes | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes |
| Templates page (a company's templates, the regiment default, a section's own, history) | All | All | No | Own (4) | Own (4) | Own (4) | Own (4) | Own (4) | No | Read (4) | Target (4) |
| Games page (the games, their ID kinds and telemetry interfaces) | Yes | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes |
| Games page: identities to check (every saved identity that breaks the ID shape or name rules) | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Template preview filled from a real section | All | All | No | Own | Own | Own | Own | Own | No | Read | Target |
| Audit log | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Activity log (pages each user opened) | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Demo data page (only when `DEMO_TOOLS=true`) | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |

(1) Portfolio officers command staff groups. Groups have no roll call, reports or KPIs, so these pages show nothing
for their portfolio. They see their staff on the roster and the org page.
(2) Every signed-in trooper may open their own milpac page, read only, whatever their scope (decided 2026-10-28), and
the search finds them. It shows their own DCSs and LORs, and a staff member records their own practices there. It opens
no other trooper, and leaves out the links into their unit (section roster, roll call, readiness matrix, makeup PM)
unless their scope reaches it. During a view-as preview only the preview's scope counts, and while acting as someone
only the scope of the person acted as: acting as someone never opens their page through this rule, so it shows only
what the acting identity's command shows (no DCSs from a shared command, nothing outside its scope).
(3) Not in the sidebar for non-Admins, but any signed-in user can open it by URL and read it. Only Admins see the
editing controls.
(4) The companies your read scope reaches into, opening on your own, and the sections of those in your scope. Asking
for any other company or section by URL answers 403. The editing controls show only to those who may use them (see
"Roll call templates" below).

### Roll call writes

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Mark signatures and SP attendance (incl. Mark all attended, Excused, Make-up requested and Make-up complete) | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Confirm practice | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Mark roll call posted | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Set, change or remove the section's practice slot; move or cancel one week's practice | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Capture "Try again" | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Unmatched player: link, dismiss, restore | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Guests (JSAM): search, add, remove | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Fireteams: mark FTLs, fill fireteams, section display options | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Company fireteam policy | All | All | No | Own | Own | No | If acting lead of the company or above | No | No | No | No |
| Pick the section's roll call template (its own, the company's switched-on ones, the regiment default) | All | All | No | Own | Own | Own | Own | Own | No | No | No |

### Roll call templates

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Company templates: add, edit (new version), roll back, switch on or off for sections, make the company default | All | All | No | Own companies | Own company | No | If acting lead of the company or above | No | No | No | No |
| A section's own templates: add, edit (new version), roll back | All | All | No | Own sections | Own sections | Own sections | If acting lead of the section or above | Own section | No | No | No |
| Regiment default template: edit, roll back | Yes | No | No | No | No | No | No | No | No | No | No |

"Own company" is `commandsUnit()` on the company: its Commander, Executive Officer and First Sergeant, plus the
battalion and regiment command above. "Own section" is `commandsUnit()` on the section: its Section Leader and
Assistant Section Leader, plus the platoon, company, battalion and regiment command above. Another company's or
section's command gets 403. A section's own templates are only offered to and used by that section's roll call; they
cannot be switched off or made a company default. The regiment default is always available to every section and cannot
be switched off. A section uses its pick, else the company default if it is switched on, else the regiment default.
Every one of these writes is in the audit log. Battalion command no longer has a switch over a company's own template
editing: the company's command always manages its own.

The sidebar shows Settings > Templates to anyone who may manage at least one company's or section's templates by these
rules (`ScopeResolver::managesAnyTemplates()`, shared as `canManageTemplates`), not during a view-as preview unless
the target is an Admin. Any signed-in user can still open the page by URL and read what their scope reaches.

When an Admin moves a platoon or section to another company, each template its sections used (picked or the old
company's default) is copied into the new company as one of its own, named like "Training (from A/1-7)" with the
version that was in use, and the sections' picks move to the copies. From then on the new company's command manages
the copies like any of its templates, and the old company's command has no say over them: its later edits, rollbacks
and switches never reach the moved sections. If the new company later switches a copy off, the sections follow the new
company and the roll call says which template the post now uses. A section's own templates belong to the section, so
they move with it and are not copied. Fireteam settings, labels, unit games and acting leads stay on the units; a moved unit with no game of its own plays its new parent's. The move and what was copied are in the audit log (`unit.moved`, `unit.settings_carried`).

### Reports

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Save a report draft | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Send (freeze) a report, incl. "Send anyway" and answering "Make-ups still owed" (a "Made it up" marks Make-up complete) | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Set or clear the platoon training topic | All | All | No | Own | Own | Own | Own (if it covers the platoon) | No | No | No | No |
| Read a platoon's monthly training plan | All | All | No | Own | Own | Own | Own | No | No | Read | Target |
| Write a platoon's monthly training plan | All | All | No | Own | Own | Own | Own (if it covers the platoon) | No | No | No | No |
| Approve or send back a training plan | All | All | No | Own | Own | No (5) | If acting lead of the company or above | No | No | No | No |

"Own" includes every report below you: a company commander can draft and send a section's report in their company.

(5) The review belongs to the command of the company the platoon is in, or above it. A platoon leader cannot approve
their own plan unless they also command the company.

### Settings and admin actions

| Action | Adm | Everyone else | VA |
| --- | --- | --- | --- |
| Org structure: add a unit (13) | Yes | A leader or overseer, inside the unit they command or oversee and at least two echelons below it (13) | No |
| Org structure: rename or move a unit, delete an empty unit | Yes | No | No |
| Org structure: Import from Cav roster | Yes | No | No |
| Org structure: place a trooper the sync could not place | Yes | No | No |
| Org structure: set or clear the game a unit plays (from the Games list, not a retired game; only while no unit above has one) (12) | Yes | Regimental HQ staff on any unit; an overseer on the units they oversee and those under them; DEVCOM's 1IC and 2IC on DEVCOM and the units under it (12) | No |
| Org structure: set or clear a unit's nickname | Yes | No | No |
| Org structure: assign or remove an acting lead (10) | Yes | No | No |
| Org structure: set, change or clear a unit's overseer | Yes | No | No |
| Staff grants: grant or revoke | Yes | No | No |
| Games: add, edit, retire, reinstate or delete a game (a game in use can only be retired) | Yes | No | No |
| Changelog: add (a date's batch), edit, delete (reason required) or bulk import entries, delete all entries and restore them within 72 hours, dismiss the "Changelog outdated" bar (only the usernames in `changelog_editors` in `config/portal.php`, signed in as themselves, never while acted as; reading it is open to everyone) | Editors only (11) | Editors only (11) | No |
| Changelog: make technical and security items available to everyone, or to the changelog editors only (signed in as yourself, never while acting as someone or during View as) | Yes | No | No |
| Demo data: generate or purge (only when `DEMO_TOOLS=true`) | Yes | No | No |
| Whole-roster sync ("Sync now" on the roster) | Yes | No | No |
| Manual LOA sync (route only, no button in the UI; during a running sync it queues one follow-up) | Yes | No | No |
| See what the latest LOA sync could not read (roster page, read-only) | Yes | No | No |

(10) Only for a line unit below the regiment whose commanding billet (CO, PL or SL) no serving trooper holds, and never
yourself: another Admin assigns an Admin. A unit with only its XO, PSG or ASL serving still takes one.

(11) Being an Admin gives nothing here: only the changelog editors write the changelog, whatever their billet. The
one changelog setting, whether technical entries are sent to everyone, is the other way round: Admins only, and an
editor who is not an Admin cannot change it. The editors always see technical entries.

(12) Any unit, at any echelon, may pick a game of its own while no unit above it has one. Under a unit that has one,
it plays that game, shown locked; a game set there before stays until it is cleared, and clearing it back to the
inherited game is the only change allowed there. A unit with units under it that have games of their own is refused,
naming them ("Clear the game on 1/A/1-7 and 2/A/1-7 first"), until those are cleared. Who may: an Admin and Regimental
HQ staff (a serving trooper placed on the regiment node whose position is still a Regimental HQ billet, the same people
who read the whole regiment) on any unit; a serving overseer (Org structure's "Oversight") on the units they oversee and
every unit under them; the serving holders of DEVCOM 1IC and DEVCOM 2IC on DEVCOM and every unit under it. Anyone else
gets a 403 and sees no game control. Act as may change it as the person acted as would; view as is read only.

(13) Admins add any unit. Anyone else adds a line unit only inside a unit they command (billet or acting lead) or
oversee, and only at least two echelons below that unit: a section needs a company or above, a platoon a battalion or
above, a company the regiment. An overseer counts one echelon higher than the unit they oversee, so the overseer of a
battalion may add its companies. Battalions, the regiment and groups are Admin only. A section or platoon leader cannot
add units. The parent must sit inside that unit. The unit's name and callsign are derived from the parent and the
picked number or letter, never typed. Anyone else gets a 403 and sees no Add unit button. Every add is in the audit
log. Act as may add as the person acted as would; view as is read only.

### Game identities and syncs

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Set or remove a trooper's game identity | All | All | Own | Own | Own | Own | Own | Own | No | No | No |
| Sync my unit's troopers from Cav (roster page) | Any unit | Own | Own | Own | Own | Own | Own | Own | No | No | No |
| Save a unit's guidon (companies, battalions and the regiment only) | Any | Own | No | Own | Own | No | If acting lead of the unit or above | No | No | No | No |
| Set a battalion's practice presence minimum (roster page) (14) | Any battalion | HQ staff | HQ staff | HQ staff | No | No | No | No | HQ staff | No | No |
| View as a unit | Any | Own | Own | Own | Own | Own | Own | Own | No | Granted units | Switch target |
| View as a person | Yes | No | No | No | No | No | No | No | No | No | Switch target |

### Act as

Act as runs the portal as another person, writes included, and every entry it writes names you acting for them ("Hale.S
for Vasquez.R"). It is not read only: the buttons and saves the identity would get show and work (the roster's Edit
guidon among them), and only view as is a read-only preview. Starting and stopping act as, and view as, keep the page you were on. A page the
identity you act or view as may not open answers with its usual 403 or 404 status, but renders the shell with a short
blocked note and the bar to leave instead of an error page; nothing of the page is sent. Who you may act as:

- An Admin: anyone who could sign in, never themselves; another Admin only without their Admin rights. Only an Admin
  acts as a billet (a unit's leader, held or vacant).
- A leader (billet or acting lead, never a staff grant): a serving billet holder strictly below the unit they command,
  as long as nothing that person holds (a grant, an acting lead) reaches outside the leader's command or over the
  leader's own placement.
- Inside the leader's own command team, the billets junior to theirs in that unit (decided 2026-10-28): the regiment's
  CO as its XO and CSM, its XO as its CSM; a battalion's CO as its XO and SGM, its XO as its SGM (DEVCOM: the 1IC as the
  2IC); a company's CO as its XO and 1SG, its XO as its 1SG; a PL as their PSG; an SL as their ASL. Never upward and
  never a peer, and only while everything the junior holds stays inside that unit. An acting lead stands where the
  unit's commanding billet does, so an acting PL may act as the PSG. The order is the roster's command team order
  (`CommandRole::seniority()`).
- Nobody while acting as someone, except an Admin acting as a leader, who may then act as whoever that leader may (one
  step only).

### Counseling (DCS and LOR)

"Own" here is your command (billet or acting lead), never a staff grant. A command the trooper holds too does not
count: an ASL does not counsel their Section Leader, a Platoon Sergeant does not counsel their Platoon Leader, and
nobody files on themselves. Neither does a command inside the trooper's own: if a rifleman is made acting Platoon
Leader of their own platoon, their Section Leader no longer files on them, sees their DCSs or changes their records.
The echelon above does. Acting as a billet or a person changes none of this for the person really signed in: they
still cannot file on themselves or change their own records, and they see their own DCSs only as themselves, not
through the identity they act as.

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| File a Developmental Counseling Statement (DCS) | All | All | Own | Own | Own | Own | Own | Own | No | No | No |
| File a Letter of Reprimand (LOR) | All | All | No (5) | Own | Own | Own | If acting lead of a platoon or above | No | No | No | No |
| See a DCS and download its document | All | All | Own | Own | Own | Own | Own | Own section | Their own (8) | Their own (8) | Target (6) |
| See an LOR and download its document | All | All | Own | Own | Own | Own | Own | Own | Their own (8) | Read | Target |
| Correct the date or reason, or delete (reason required) | All (9) | The issuer's chain (7) | The issuer's chain | The issuer's chain | The issuer's chain | The issuer's chain | The issuer's chain | The issuer's chain | No | No | No |
| Appeal (LOR) or comment or dispute (DCS) within 72 hours (statement required) | Their own (11) | Their own (11) | Their own (11) | Their own (11) | Their own (11) | Their own (11) | Their own (11) | Their own (11) | Their own (11) | Their own (11) | No |
| Overturn a frozen LOR (reason required) | No (10) | The RXO only (10) | No | No | No | No | No | No | No | No | No |

(5) An LOR needs command from platoon level up on the line. A portfolio officer files a DCS on their staff; an LOR on
staff comes from regiment command or an Admin.
(6) An Admin's preview of another Admin shows every DCS in the previewed scope. Any other preview (a unit, a leader,
a grant holder) shows a DCS only where the Admin's own command (a billet or an acting lead) reaches the trooper, not
every DCS.
(7) The leaders who command the unit the record was filed through: the issuer, anyone sharing that billet's unit, and
everyone above, as long as they still counsel the trooper by the rules above. A record an Admin filed (no unit) is the
Admins' alone.
(8) Every trooper, in every column, sees their own DCSs and LORs on their own milpac page and downloads their documents
(decided 2026-10-28), signed in as themselves (not while acting as or viewing as anyone). It is read only: nobody files
on, corrects or deletes their own records, except that an Admin by the `is_admin` flag still corrects and deletes as an
Admin. An Admin by billet does not (2026-10-29).
(9) Not a frozen record: 72 hours after a DCS or LOR is filed nobody corrects or deletes it, Admins by flag or by
billet included. A DCS has no overturn.
(11) Only the trooper the record is on, signed in as themselves, within the 72 hours after it was filed, once per
record, and not once they have left the Cav. Nobody files it for them: not their chain, not an Admin, and not anyone
through act as or a view-as preview. Whoever sees the record sees the statement.
(10) Only the serving holder of the Regimental Executive Officer billet (the org import maps it to the regiment)
overturns a frozen LOR, signed in as themselves: never one filed on themselves, never in a view-as preview, and never
through act as (S6, 2026-10-29: "only the RXO for now"). An Admin acting as the RXO, the RCO acting as the RXO under the
command team rule, and the RXO while acting as someone else are all refused. The overturned LOR stays on file, is marked so, and counts
toward no flag.

A DCS expires 90 days after the date it was counseled (from day 91). An expired DCS is seen by Admins only, marked
expired, and its document downloads for them only: leaders and the trooper get the same 404 as for a record that does
not exist. Leaders cannot correct or delete it; Admins keep their rights. It is never deleted. An LOR never expires.

A trooper who has left the Cav (no longer serving) has read-only counseling: nobody may file a DCS or LOR on them, or
correct or delete one, Admins included, and the panel says so. Whoever could see their records still sees and downloads
them. If they serve again, the rules above apply again. The RXO may still overturn a frozen LOR of theirs: that corrects
the record and adds nothing to it.

Filing from the portal's form, and its preview, follow exactly the filing rules above: whoever may file the type may
generate it, nobody else (a 403, or a 404 for a trooper they cannot see), never in a view-as preview. Section 1 of the
form is read from the roster, never taken from the request.

A record the viewer may not see is left out of the page entirely, and its download answers 404 like a record that does
not exist, also on a departed trooper. The flags and the filing form's forecast are worked out from the records the
viewer sees, so they never give a DCS away.

### Staff practices

A staff member is a trooper placed on an HQ node or in a staff department. Their staff leader is whoever commands the
HQ or department they are placed in, and the chain above it (`commandsUnit()`): a billet, an acting lead or a held
billet, never a staff grant. The staff member records their own practices on their own milpac page, which every
trooper may open (decided 2026-10-28); that opens no other trooper's page.

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See a staff member's attended practices (milpac, roster sheet) | All | All | Own | Own | Own | Own | Own | Own | Their own (milpac) | Read | Target |
| Add or remove one | All | Own staff | Own staff | Own staff | Own staff | Own staff | Own staff | No | Self (9) | Self (9) | No |

(9) A staff member may add and remove their own entries, which read as self-reported, from their own milpac page.

### Staff report

A platoon, company or battalion HQ staff member (a serving trooper placed on that HQ node when the week ended, ACD
included) files their own weekly staff report, once that week has ended. Regiment HQ, staff departments, ELOA,
Reservists and New Recruits file none. "Above their HQ" is the unit the HQ sits in: the company for a platoon HQ, the
battalion for a company HQ, the regiment for a battalion HQ (so regiment command and Admins read those). Reading follows the read scope there, as unit reports do, so a staff grant, an overseer
and the Regimental HQ read count.

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Read a staff member's staff report, and their row on the HQ's report | All | All | No | Above their HQ | Above their HQ | Their own only (11) | Above their HQ | Their own only (11) | Their own only (11) | Above their HQ | Target |
| Open the staff reports overview (`/staff-reports`) (13) | All | All | No | Yes | Yes | No | If above an HQ | No | No | If above an HQ | Target |
| Send one (final, no edit) | Their own only (12) | Their own only | Their own only | Their own only | Their own only | Their own only | Their own only | Their own only | Their own only | Their own only | No |

(11) Everyone reads their own. A peer on the same HQ (a PL and their PSG, a CO and their XO) does not read the other's:
reading starts at the unit above the HQ, so a Platoon Leader reads no other staff report.
Only battalion HQ staff (ACD included) may report an office hour in place of a section practice; a platoon or company HQ
staff member's office hour is refused. The game server warning on a sent report is shown to its readers only, never
to the staff member about their own.

(12) Only the staff member, signed in as themselves: never someone acting as them (an Admin included), and never in a
view-as preview. A page for another trooper, or a week they file none for, answers 404 to anyone who may not read it.
(13) Anyone whose read scope holds the unit above at least one platoon, company or battalion HQ (`StaffReportAccess::readableHqs()`),
and Admins. The sidebar item then reads "Staff reports" (the shared `features.staffReports` is `overview`); for HQ
staff who read only their own it stays "Staff report" (`own`). Everyone else gets a 404.

(14) Only a serving trooper placed on that battalion's own node (its HQ staff, ACD included), whatever billet they hold,
and Admins. Acting as one of them writes as they would; view as never writes. The minimum (1 to 60 minutes, default
40 from `portal.presence_minimum_minutes`) is how long a trooper must be on a practice's server during its hour to
count as attending it. Every change is audited (`presence_minimum.set`). Everyone who can open the battalion's roster
reads it.

## How scope is worked out

1. Admin: if your user has the Admin flag or an approved Admin billet (Regimental HQ, S6 1IC or S6 2IC), your scope is the whole
   tree. Stop here.
2. Billet: the portal takes your linked milpac, reads your primary billet from the last roster sync and looks up which
   unit that billet commands. The Import from Cav roster step writes that billet to unit mapping (see
   [operations.md](operations.md)). Your scope is that unit and everything under it.
3. Acting leads: every unit an Admin made you acting lead of is added, each with its subtree.
4. Staff grants: every unit you were granted is added to your read scope, each with its subtree.
5. Regimental HQ staff: if your milpac is placed directly on the regiment and your billet is one Cav lists under
   Regimental HQ now (the org import records each in `admin_billets`, approved or not), the whole regiment is added to
   your read scope. A move to a billet the sync cannot place leaves you on the regiment, but the read ends with the
   billet.
6. Oversight: every unit an Admin named you overseer of is added to your read scope, each with its subtree.
7. View as: if a preview is on, your scope is cut down to the target. It is checked again on every request, so a
   preview stops on its own when you lose the authority it relied on.

Read scope is all of the above. Write scope leaves out staff grants, oversight and the Regimental HQ read: only Admin, your billet
and your acting leads let you change anything. While view as is on, every write is refused.

Because the billet is read on every request, a roster sync that moves you to a new billet moves your scope with it on
your next click. Nothing has to be revoked by hand.

### Departed users

- Signing in needs the Admin flag or a serving milpac (an Admin by billet always has one) (combat, reserve or ELOA roster). Cav's live roster is asked at
  sign-in; if Cav cannot answer, the last roster sync decides.
- Every page load checks again. A non-Admin whose milpac shows as departed is signed out. Someone admitted on Cav's
  live roster ahead of the cache stays signed in until the next clean whole-roster sync.
- A departed trooper's billet, acting leads, staff grants, oversight and Regimental HQ read stop counting on the next request. The
  rows stay, so an Admin sees a lapsed grant flagged on the Staff grants page and can revoke it on the record. If the trooper returns
  to a serving roster, they count again.
- An Admin by flag is never signed out by roster standing. An Admin by billet is a serving trooper like any other: departed,
  they are signed out and lose Admin with it.
- Anyone linked to a milpac, an Admin by flag too, is signed out when the cached trooper for that milpac carries a different
  forum account or none. Cav can give a milpac id to someone else, and the old link must not pass on the new holder's billet.
  Someone the roster sync has not caught up with yet can sign in again after the next sync.

### Where everyone stops: locks

| Lock | What it stops |
| --- | --- |
| Week floor (`PORTAL_WEEK_FLOOR_WEEKS`, default 20 Cav weeks back) | Marking, confirming, marking posted, capture, linking unmatched players, guests, staff practices, report drafts and sends, the platoon topic, a training plan's weeks. Older reports open read-only. |
| Future weeks | Marking, confirming, posting, capture and staff practices for a week that has not started. |
| HQ report sent for the week | That week's staff practice entries of the staff placed on that HQ during that week, wherever they are placed now, entered on the milpac. The staff member's own staff report is not locked by it. |
| Staff report sent for the week | The staff report itself (no edit, no second send), and that week's staff practice entries of that staff member. |
| Section report sent for the week | That section's roll call marks, confirming, marking posted, capture "Try again", guests at that practice and automatic makeups into that week. The sent report itself is frozen. A platoon's topic, and that week's subject in its training plan, are locked once the platoon's report is sent. |
| Practice confirmed by a leader | Capture re-runs and linked players no longer change its marks. A leader's own hand edit still saves and clears the confirmation. |
| Leave covering the week | That trooper's practice mark (the row posts as LOA). Their signature can still be saved. |

Copying the forum post stays possible on a locked week; marking it posted does not.

### Rate limits

All per signed-in user (sign-in is per IP address), per minute. While acting as someone, the limits still count
against the person really signed in, so switching people does not reset them. Every write the audit log records has one of these;
only sign out has none, since it ends the session and signing back in is limited.

| Limit | Applies to |
| --- | --- |
| 10 per IP, per route | The two sign-in steps |
| 60 | Find trooper and the guest picker search |
| 240 | Roll call mark autosave. The screen waits 0.7 seconds after a change and sends one batch at a time, so marking a whole section in seconds stays far below this. |
| 120 | Report draft autosave |
| 60, shared | Every other audited write: saving a guidon, fireteam changes, company fireteam policy, linking, dismissing and restoring unmatched players, confirming a practice, marking a roll call posted, guest add and remove, staff practice add and remove, sending a staff report, setting and removing a game identity, platoon topic set and clear, training plan save, approve and send back, every template write (a company's or a section's) and a section's template pick, starting and leaving view as and act as, correcting and deleting a DCS or LOR, overturning an LOR, and the Admin settings writes (org structure, acting leads, oversight, trooper placement, staff grants, games, servers, demo data) |
| 10 | Sending a report |
| 20 | Loading the act as picker's list |
| 30 | Loading the sidebar unit switcher's list (the units in your scope; read only, it changes nothing) |
| 10 | Capture "Try again"; the same practice also only once a minute |
| 10 | Filing a DCS or LOR (each one uploads a PDF of up to 10 MB) |
| 5, shared | Whole-roster sync and the org-structure import, which both read Cav on the request (Admins) |
| 5 | Manual LOA sync, fetching game servers (Admins) |
| 3 | Unit roster sync; also a 5 minute cooldown on that unit and every unit above and below it (Admins skip the cooldown) |

## Technical appendix

| Rule | Where in the code |
| --- | --- |
| Read scope, write scope, `commandsUnit()`, `canSee()`, view as | `app/Authorization/ScopeResolver.php` |
| Billet to unit mapping rules | `app/Commands/Units/CommandBillet.php`, `StaffBillet.php` (portfolios), written by `ImportOrgStructure.php` into `unit_command_mappings` |
| Acting leads | `unit_acting_leads`, `AssignActingLeadHandler`, `RemoveActingLeadHandler` |
| Oversight (read only) | `unit_oversights`, `SetUnitOversightHandler`; read by `ScopeResolver::realScope()` and `realScopes()` |
| Who is an Admin (flag or approved Regimental HQ, S6 1IC or S6 2IC billet) | `ScopeResolver::isAdmin()` and `adminIdsAmong()`; the group and titles in `config/portal.php` (`admin_billet_groups`, `admin_billet_titles`); billets in `admin_billets`, decided by `app/Commands/AdminBillets/DecideAdminBilletHandler.php`, names recorded by `ImportOrgStructure` and `SyncRosterHandler` |
| Act as (who may act as whom, the picker, the command team order) | `app/Authorization/ActAs.php`, `app/Models/CommandRole.php` (`seniority()`) |
| View as is read-only | `app/Http/Middleware/ReadOnlyWhileViewingAs.php` (every non-GET except `view-as.store`, `view-as.destroy`, `logout`) |
| Sign-in and session standing | `app/Commands/Auth/AdmitServingMember.php`, `app/Authorization/ServingSession.php`, `EnsureServingMember` middleware |
| Admin-only checks | In the controllers (`OrgStructureController`, `StaffGrantsController`, `TrooperPlacementController`, `RosterSyncController`, `LoaSyncController`, `AuditLogController`, `DemoDataController`) and again in some handlers. There are no Laravel policies. |
| Write gates | Each handler under `app/Commands/*` checks `writeScope()` (attendance, capture, guests, game identities, reports, a section's template pick) or `commandsUnit()` (fireteams, company policy, platoon topic, guidon, training plans, the review on the owning company in `TrainingPlanRules`, company and section templates through `Commands/Templates/TemplateAuthority`) |
| Staff practices | `app/Authorization/StaffPracticeAccess.php` on `commandsUnit()`; checked in each `app/Commands/StaffPractices` handler |
| Staff report | `app/Authorization/StaffReportAccess.php` (`maySend()` on `isOwnTrooper()`, `mayRead()` on `resolve()` of the unit above the HQ); checked in `SendStaffReportHandler` and `StaffReportController` |
| Counseling (DCS, LOR) | `app/Authorization/CounselingAccess.php` on `ScopeResolver::commandingUnitsOver()`, `writeScope()` and `canSee()`; the 72-hour freeze, who overturns and who appeals in `CounselingPermissions::frozen()`, `overturns()` and `appeals()`; checked again in each `app/Commands/Counseling` handler and on the download |
| Week floor | `app/Support/Time/WeekOf.php` (`floor()`, `isBeforeFloor()`), `config/portal.php` |
| Rate limiters | `app/Providers/AppServiceProvider.php`, unit sync cooldown in `UnitRosterSyncController` |

`isAdmin()` is the one Admin rule: the `is_admin` flag or an approved Admin billet, worked out on every request
and never written to the user. `actsAsAdmin()` is the Admin check for reads: it is false while an Admin previews a
non-Admin, so Admin-only pages follow the preview. Writes check `isAdmin()`, and the middleware refuses them during a
preview anyway. A copy of a user that act as runs a request as (`ActAs::standIn()`, `withoutAdmin()`) is never an Admin
by billet.
