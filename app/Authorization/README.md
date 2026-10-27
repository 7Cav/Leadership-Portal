# `Authorization/` — ScopeResolver, view-as (AD-6, AD-7, AD-8, AD-9)

The single `ScopeResolver` that maps a user's effective scope → the set of units
they may read (AD-8). Every echelon-scoped read filters through it — no controller
hand-rolls visibility. Also holds the view-as control (view-as narrows, never widens —
AD-9). There are no Laravel policies; access checks go through the `ScopeResolver`.

**Reached from** `Controllers/`, `Http/Middleware/` (view-as sharing, the read-only guard and the serving-member check),
and any CommandHandler that must scope a write.
The ScopeResolver reads the org tree, `portal_role_grants` and the billet → command mapping through `Repositories/`
methods only (AD-2) — never the DB directly. Enforced in `deptrac.yaml` since Story 1.11.

**Effective scope** is the full tree for an Admin; otherwise the union of the roster-derived
subtree and the `portal_role_grants` subtrees. Roster-derived scope (AD-7, Story 1.16) is the unit the user's
synced primary Cav billet commands: `users.cav_id` → `troopers.cav_position_id` →
`unit_command_mappings` (written by the org import, 2.9) → that unit's subtree. It is read on
every request, so a roster sync that changes the billet changes the scope; a departed trooper
or an unmapped billet derives nothing (deny-by-default, AD-8). A grant counts only while its holder
is a serving trooper (`users.cav_id` → a cached trooper on combat, reserve or ELOA, the same
`TrooperStatus::serving()` allow-list the billet half uses): a holder who leaves keeps the row, shown
as lapsed on the staff grants page, but none of its scope.

Placement (2026-10-24) does not change who commands what, but it does change who a leader sees: `canSee()` asks
whether the trooper's primary unit is in scope, so a platoon's PL and PSG (placed on the platoon), a company's HQ and a
battalion's command team are visible to the leaders above them, and a staff department to the portfolio officer whose
billet commands its portfolio node. ELOA and New Recruits sit under the regiment, so only regimental scope and Admins
see them; the Reservists sit under Reserve & Development Command.

**Acting leads** (2026-10-25). Some units' leads are not in the roster data: a `DEVCOM Lead` is rostered to DEVCOM staff
and runs one or more DEVCOM starter platoons as their Platoon Leader. An Admin records that as an acting lead
(`unit_acting_leads`, one per unit, audited with a reason). The roster-derived scope is then the billet's commanded unit
plus every unit the user is acting lead of, each only while their trooper serves: `resolve()`, `writeScope()` and
`commandsUnit()` treat an acting lead exactly like a Platoon Leader's command over that unit. A trooper who stops
serving loses it on the next read; the row stays until an Admin removes it.

**Write scope** is narrower than read scope: `ScopeResolver::writeScope()` is the full tree for an Admin,
otherwise only the roster-derived subtree. A staff grant reads an echelon, it never writes to it, so a grant
holder sees the rollcall, reports and game identities of the granted units read-only. Every write handler
(mark attendance, confirm practice, mark rollcall posted, request capture, link or dismiss an unmatched
player, set or remove a game identity, save or send a weekly report, demo history) gates on `writeScope()`,
not `resolve()`, and the pages take a `canWrite` (or per-trooper `editable`) flag from it so they go
read-only instead of offering buttons that answer 403. `commandsUnit()` (the echelon roster sync) is the
same authority without the view-as narrowing.

**View-as** (AD-9, Story 1.13) narrows, never widens. `ViewAs` keeps the viewer's chosen target
(a unit in their scope, or — Admins only — another user) in the server-side session, bound to the
viewer. `ScopeResolver::resolve()` returns the *effective* scope, `real ∩ target`; `realScope()` is
the ceiling. The target is re-checked by `evaluate()` on every call, so one that falls outside
current real authority stops applying at once. While a preview is active, the
`ReadOnlyWhileViewingAs` middleware refuses every write except leaving view-as and signing out, and
`writeScope()` is narrowed to the target as well.

**Session standing.** `ServingSession` keeps the sign-in rule (an Admin, or a user linked to a
trooper not known to have left) true for the whole session; the `EnsureServingMember` middleware asks
it on every authenticated request. A non-Admin with no milpac link is signed out. A departed cache
record ends the session, except one admitted on Cav's live roster: that admission is noted in the
session, bound to the user, and stands until a clean roster sync that started after it, so a trooper
who re-enlisted since the last sync is not bounced in a loop.

**Act as** (#379) is not a preview: `ActAs` keeps an Admin's choice of person in the session, and the `ActAsUser`
middleware runs each request as that person, so every gate here answers for them, writes included. Only a real Admin
may act, never as themselves or another Admin, and only as someone who could sign in right now; the choice is checked
on every request and dropped when it no longer holds. Entries written meanwhile name the Admin acting on the person's
behalf (`Support/Audit`).
