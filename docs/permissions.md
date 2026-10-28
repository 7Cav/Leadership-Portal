# Permissions

## In short

- Who you are in the portal comes from your primary billet on the Cav roster, read fresh on every page load. A
  leadership billet gives you the unit it commands and everything under it. Nobody types in roles.
- Admin is a separate flag on a portal user, set from the server console. Admins see and change everything, and are the
  only ones who can edit the org structure, staff grants and the regiment default rollcall template, or read the audit
  log and the activity log.
- A company's command (and the command above it) manages that company's own rollcall templates, and a section's command
  (and the command above it) manages the section's own.
- A staff grant (given by an Admin) lets someone read a unit and everything under it. It never lets them change
  anything there.
- "View as" previews the portal as a unit (or, for Admins, as a person). It only narrows what you see, and every change
  is refused while it is on.
- Writes also stop at a few hard edges whatever your rank: weeks older than the week floor, weeks whose section report
  was sent, and practices a leader confirmed.

## The command layers

| Layer | Billets (exact titles on the Cav roster) | Unit commanded |
| --- | --- | --- |
| Admin | Not a billet: the `is_admin` flag, set with `php artisan portal:admin` | The whole tree |
| Regiment command | `Regimental Commanding Officer`, `Regimental Executive Officer`, `Regimental Command Sergeant Major` in the `Regimental HQ` group | The regiment (the whole tree) |
| Regimental staff portfolio officer | `Regimental Security Operations Officer` (SecOps), `Regimental Recruiting Oversight Officer`, `Regimental Information Management Officer` (IMO), `RDC Commander` | Their portfolio node and the staff groups under it |
| Battalion command | `<bn> Commanding Officer`, `<bn> Executive Officer`, `<bn> Sergeant Major` in a `<bn> Command` group (ACD also: `Auxiliary Commander`, `Auxiliary Executive Officer`, `Auxiliary Sergeant Major`); `DEVCOM 1IC` and `DEVCOM 2IC` in any group | The battalion (DEVCOM for its 1IC and 2IC) |
| Company command | `Commander`, `Executive Officer`, `First Sergeant` of a line company (`Commander A/1-7`) | The company |
| Platoon | `Platoon Leader`, `Platoon Sergeant` of a platoon (`Platoon Leader 1/A/1-7`) | The platoon |
| Acting lead | Not a billet: an Admin assigns a serving trooper to a line unit below the regiment | That unit (one or more), exactly like a Platoon Leader over it |
| Section | `Section Leader`, `Assistant Section Leader` of a section | The section |
| Trooper with no command billet | Any other billet | Nothing |
| Staff grant holder | Any billet, plus a grant on the Staff grants page | Read-only on the granted unit(s); write scope only from their own billet |

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
  over DEVCOM, D/DEVCOM and its platoons and sections, rollcall templates included. The org
  page names the 1IC as DEVCOM's lead; when that billet is vacant the lead shows vacant, and the 2IC keeps their rights.
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
| A trooper's milpac page | All, plus unplaced | All | Own | Own | Own | Own | Own | Own | No (2) | Read | Target |
| Find trooper (shell search) | All, plus unplaced | All | Own | Own | Own | Own | Own | Own | Nothing found | Read | Target |
| Rollcall (sections in scope) | All | All | None (1) | Own | Own | Own | Own | Own | Empty picker | Read | Target |
| Reports and roll-ups (line units) | All | All | None (1) | Own | Own | Own | Own | Own | None | Read | Target |
| Game identities (every serving trooper placed in scope: sections, HQ staff, groups) | All | All | Own | Own | Own | Own | Own | Own | None | Read | Target |
| Settings menu in the sidebar | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Org structure page (whole tree and unit leads) | Yes | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes |
| Org structure: members of staff groups, ELOA, Reservists, New Recruits | All | All | Own | Own | Own | Own | Own | Own | No | Read | Target |
| Org structure: troopers the sync could not place | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Staff grants page (every grant; the username picker only for an acting Admin) | Yes | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes |
| Templates page (a company's templates, the regiment default, a section's own, history) | All | All | No | Own (4) | Own (4) | Own (4) | Own (4) | Own (4) | No | Read (4) | Target (4) |
| Games page (the games, their ID kinds and telemetry interfaces) | Yes | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes (3) | Yes |
| Games page: identities to check (every saved identity that breaks the ID shape or name rules) | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Template preview filled from a real section | All | All | No | Own | Own | Own | Own | Own | No | Read | Target |
| Audit log | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Activity log (pages each user opened) | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |
| Demo data page (only when `DEMO_TOOLS=true`) | Yes | No | No | No | No | No | No | No | No | No | Only if target is an Admin person |

(1) Portfolio officers command staff groups. Groups have no rollcall, reports or KPIs, so these pages show nothing
for their portfolio. They see their staff on the roster and the org page.
(2) A trooper with no command billet and no grant has an empty scope, so even their own milpac page answers 404.
(3) Not in the sidebar for non-Admins, but any signed-in user can open it by URL and read it. Only Admins see the
editing controls.
(4) The companies your read scope reaches into, opening on your own, and the sections of those in your scope. Asking
for any other company or section by URL answers 403. The editing controls show only to those who may use them (see
"Rollcall templates" below).

### Rollcall writes

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Mark signatures and SP attendance (incl. Mark all present) | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Confirm practice | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Mark rollcall posted | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Capture "Try again" | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Unmatched player: link, dismiss, restore | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Guests (JSAM): search, add, remove | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Fireteams: mark FTLs, fill fireteams, section display options | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Company fireteam policy | All | All | No | Own | Own | No | If acting lead of the company or above | No | No | No | No |
| Pick the section's rollcall template (its own, the company's switched-on ones, the regiment default) | All | All | No | Own | Own | Own | Own | Own | No | No | No |

### Rollcall templates

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Company templates: add, edit (new version), roll back, switch on or off for sections, make the company default | All | All | No | Own companies | Own company | No | If acting lead of the company or above | No | No | No | No |
| A section's own templates: add, edit (new version), roll back | All | All | No | Own sections | Own sections | Own sections | If acting lead of the section or above | Own section | No | No | No |
| Regiment default template: edit, roll back | Yes | No | No | No | No | No | No | No | No | No | No |

"Own company" is `commandsUnit()` on the company: its Commander, Executive Officer and First Sergeant, plus the
battalion and regiment command above. "Own section" is `commandsUnit()` on the section: its Section Leader and
Assistant Section Leader, plus the platoon, company, battalion and regiment command above. Another company's or
section's command gets 403. A section's own templates are only offered to and used by that section's rollcall; they
cannot be switched off or made a company default. The regiment default is always available to every section and cannot
be switched off. A section uses its pick, else the company default if it is switched on, else the regiment default.
Every one of these writes is in the audit log. Battalion command no longer has a switch over a company's own template
editing: the company's command always manages its own.

When an Admin moves a platoon or section to another company, each template its sections used (picked or the old
company's default) is copied into the new company as one of its own, named like "Training (from A/1-7)" with the
version that was in use, and the sections' picks move to the copies. From then on the new company's command manages
the copies like any of its templates, and the old company's command has no say over them: its later edits, rollbacks
and switches never reach the moved sections. If the new company later switches a copy off, the sections follow the new
company and the rollcall says which template the post now uses. A section's own templates belong to the section, so
they move with it and are not copied. Fireteam settings, labels and acting leads stay on the units. The move and what was copied are in the audit log (`unit.moved`, `unit.settings_carried`).

### Reports

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Save a report draft | All | All | No | Own | Own | Own | Own | Own | No | No | No |
| Send (freeze) a report, incl. "Send anyway" | All | All | No | Own | Own | Own | Own | Own | No | No | No |
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
| Org structure: create, rename or move a unit, delete an empty unit | Yes | No | No |
| Org structure: Import from Cav roster | Yes | No | No |
| Org structure: place a trooper the sync could not place | Yes | No | No |
| Org structure: set or clear a unit's game or team label | Yes | No | No |
| Org structure: assign or remove an acting lead | Yes | No | No |
| Staff grants: grant or revoke | Yes | No | No |
| Games: add, edit, retire, reinstate or delete a game (a game in use can only be retired) | Yes | No | No |
| Demo data: generate or purge (only when `DEMO_TOOLS=true`) | Yes | No | No |
| Whole-roster sync ("Sync now" on the roster) | Yes | No | No |
| Manual LOA sync (route only, no button in the UI) | Yes | No | No |

### Game identities and syncs

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Set or remove a trooper's game identity | All | All | Own | Own | Own | Own | Own | Own | No | No | No |
| Sync my unit's troopers from Cav (roster page) | Any unit | Own | Own | Own | Own | Own | Own | Own | No | No | No |
| Save a unit's guidon (companies, battalions and the regiment only) | Any | Own | No | Own | Own | No | If acting lead of the unit or above | No | No | No | No |
| View as a unit | Any | Own | Own | Own | Own | Own | Own | Own | No | Granted units | Switch target |
| View as a person | Yes | No | No | No | No | No | No | No | No | No | Switch target |

### Counseling (DCS and LOR)

"Own" here is your command (billet or acting lead), never a staff grant. A command the trooper holds too does not
count: an ASL does not counsel their Section Leader, a Platoon Sergeant does not counsel their Platoon Leader, and
nobody files on themselves. Neither does a command inside the trooper's own: if a rifleman is made acting Platoon
Leader of their own platoon, their Section Leader no longer files on them, sees their DCSs or changes their records.
The echelon above does. Acting as a billet or a person changes none of this for the person really signed in: they
still cannot file on themselves, see their own DCSs or change their own records.

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| File a Developmental Counseling Statement (DCS) | All | All | Own | Own | Own | Own | Own | Own | No | No | No |
| File a Letter of Reprimand (LOR) | All | All | No (5) | Own | Own | Own | If acting lead of a platoon or above | No | No | No | No |
| See a DCS and download its document | All | All | Own | Own | Own | Own | Own | Own section | No | No | Target (6) |
| See an LOR and download its document | All | All | Own | Own | Own | Own | Own | Own | No | Read | Target |
| Correct the date or reason, or delete (reason required) | All | The issuer's chain (7) | The issuer's chain | The issuer's chain | The issuer's chain | The issuer's chain | The issuer's chain | The issuer's chain | No | No | No |

(5) An LOR needs command from platoon level up on the line. A portfolio officer files a DCS on their staff; an LOR on
staff comes from regiment command or an Admin.
(6) An Admin's preview shows every DCS in the previewed scope.
(7) The leaders who command the unit the record was filed through: the issuer, anyone sharing that billet's unit, and
everyone above, as long as they still counsel the trooper by the rules above. A record an Admin filed (no unit) is the
Admins' alone.

A trooper who has left the Cav (no longer serving) has read-only counseling: nobody may file a DCS or LOR on them, or
correct or delete one, Admins included, and the panel says so. Whoever could see their records still sees and downloads
them. If they serve again, the rules above apply again.

A record the viewer may not see is left out of the page entirely, and its download answers 404 like a record that does
not exist, also on a departed trooper. The flags and the filing form's forecast are worked out from the records the
viewer sees, so they never give a DCS away.

### Staff practices

A staff member is a trooper placed on an HQ node or in a staff department. Their staff leader is whoever commands the
HQ or department they are placed in, and the chain above it (`commandsUnit()`): a billet, an acting lead or a held
billet, never a staff grant. The staff member records their own practices on their milpac page when they can open it
(their command or a grant must reach their own unit); nobody gains read access for this.

| Action | Adm | Rgt | Pf | Bn | Co | Plt | Act | Sec | None | Grant | VA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See a staff member's attended practices (milpac, roster sheet) | All | All | Own | Own | Own | Own | Own | Own | No | Read | Target |
| Add or remove one | All | Own staff | Own staff | Own staff | Own staff | Own staff | Own staff | No | Self (8) | Self (8) | No |

(8) A staff member may add and remove their own entries, which read as self-reported.

## How scope is worked out

1. Admin: if your user has the Admin flag, your scope is the whole tree. Stop here.
2. Billet: the portal takes your linked milpac, reads your primary billet from the last roster sync and looks up which
   unit that billet commands. The Import from Cav roster step writes that billet to unit mapping (see
   [operations.md](operations.md)). Your scope is that unit and everything under it.
3. Acting leads: every unit an Admin made you acting lead of is added, each with its subtree.
4. Staff grants: every unit you were granted is added to your read scope, each with its subtree.
5. View as: if a preview is on, your scope is cut down to the target. It is checked again on every request, so a
   preview stops on its own when you lose the authority it relied on.

Read scope is all of the above. Write scope leaves out staff grants: only Admin, your billet and your acting leads let
you change anything. While view as is on, every write is refused.

Because the billet is read on every request, a roster sync that moves you to a new billet moves your scope with it on
your next click. Nothing has to be revoked by hand.

### Departed users

- Signing in needs the Admin flag or a serving milpac (combat, reserve or ELOA roster). Cav's live roster is asked at
  sign-in; if Cav cannot answer, the last roster sync decides.
- Every page load checks again. A non-Admin whose milpac shows as departed is signed out. Someone admitted on Cav's
  live roster ahead of the cache stays signed in until the next clean whole-roster sync.
- A departed trooper's billet, acting leads and staff grants stop counting on the next request. The rows stay, so an
  Admin sees a lapsed grant flagged on the Staff grants page and can revoke it on the record. If the trooper returns
  to a serving roster, they count again.
- An Admin is never signed out by roster data.

### Where everyone stops: locks

| Lock | What it stops |
| --- | --- |
| Week floor (`PORTAL_WEEK_FLOOR_WEEKS`, default 20 Cav weeks back) | Marking, confirming, marking posted, capture, linking unmatched players, guests, staff practices, report drafts and sends, the platoon topic, a training plan's weeks. Older reports open read-only. |
| Future weeks | Marking, confirming, posting, capture and staff practices for a week that has not started. |
| HQ report sent for the week | That week's staff practice entries of the staff placed on that HQ. |
| Section report sent for the week | That section's rollcall marks, confirming, marking posted, capture "Try again", guests at that practice and automatic makeups into that week. The sent report itself is frozen. A platoon's topic, and that week's subject in its training plan, are locked once the platoon's report is sent. |
| Practice confirmed by a leader | Capture re-runs and linked players no longer change its marks. A leader's own hand edit still saves and clears the confirmation. |
| Leave covering the week | That trooper's practice mark (the row posts as LOA). Their signature can still be saved. |

Copying the forum post stays possible on a locked week; marking it posted does not.

### Rate limits

All per signed-in user (sign-in is per IP address), per minute. Every write the audit log records has one of these;
only sign out has none, since it ends the session and signing back in is limited.

| Limit | Applies to |
| --- | --- |
| 10 per IP, per route | The two sign-in steps |
| 60 | Find trooper and the guest picker search |
| 240 | Rollcall mark autosave. The screen waits 0.7 seconds after a change and sends one batch at a time, so marking a whole section in seconds stays far below this. |
| 120 | Report draft autosave |
| 60, shared | Every other audited write: saving a guidon, fireteam changes, company fireteam policy, linking, dismissing and restoring unmatched players, confirming a practice, marking a rollcall posted, guest add and remove, staff practice add and remove, setting and removing a game identity, platoon topic set and clear, training plan save, approve and send back, every template write (a company's or a section's) and a section's template pick, starting and leaving view as and act as, correcting and deleting a DCS or LOR, and the Admin settings writes (org structure, acting leads, trooper placement, staff grants, games, servers, demo data) |
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
| View as is read-only | `app/Http/Middleware/ReadOnlyWhileViewingAs.php` (every non-GET except `view-as.store`, `view-as.destroy`, `logout`) |
| Sign-in and session standing | `app/Commands/Auth/AdmitServingMember.php`, `app/Authorization/ServingSession.php`, `EnsureServingMember` middleware |
| Admin-only checks | In the controllers (`OrgStructureController`, `StaffGrantsController`, `TrooperPlacementController`, `RosterSyncController`, `LoaSyncController`, `AuditLogController`, `DemoDataController`) and again in some handlers. There are no Laravel policies. |
| Write gates | Each handler under `app/Commands/*` checks `writeScope()` (attendance, capture, guests, game identities, reports, a section's template pick) or `commandsUnit()` (fireteams, company policy, platoon topic, guidon, training plans, the review on the owning company in `TrainingPlanRules`, company and section templates through `Commands/Templates/TemplateAuthority`) |
| Staff practices | `app/Authorization/StaffPracticeAccess.php` on `commandsUnit()`; checked in each `app/Commands/StaffPractices` handler |
| Counseling (DCS, LOR) | `app/Authorization/CounselingAccess.php` on `ScopeResolver::commandingUnitsOver()`, `writeScope()` and `canSee()`; checked again in each `app/Commands/Counseling` handler and on the download |
| Week floor | `app/Support/Time/WeekOf.php` (`floor()`, `isBeforeFloor()`), `config/portal.php` |
| Rate limiters | `app/Providers/AppServiceProvider.php`, unit sync cooldown in `UnitRosterSyncController` |

`actsAsAdmin()` is the Admin check for reads: it is false while an Admin previews a non-Admin, so Admin-only pages
follow the preview. Writes check the real `is_admin` flag, and the middleware refuses them during a preview anyway.
