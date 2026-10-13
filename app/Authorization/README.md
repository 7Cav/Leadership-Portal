# `Authorization/` — ScopeResolver, policies, view-as (AD-6, AD-7, AD-8, AD-9)

The single `ScopeResolver` that maps a user's effective scope → the set of units
they may read (AD-8). Every echelon-scoped read filters through it — no controller
hand-rolls visibility. Also holds policies and the view-as control (view-as narrows,
never widens — AD-9).

**Reached from** `Controllers/` (and any CommandHandler that must scope a write).
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

**View-as** (AD-9, Story 1.13) narrows, never widens. `ViewAs` keeps the viewer's chosen target
(a unit in their scope, or — Admins only — another user) in the server-side session, bound to the
viewer. `ScopeResolver::resolve()` returns the *effective* scope, `real ∩ target`; `realScope()` is
the ceiling. The target is re-checked by `evaluate()` on every call, so one that falls outside
current real authority stops applying at once. While a preview is active, the
`ReadOnlyWhileViewingAs` middleware refuses every write except leaving view-as and signing out.
