# `Authorization/` — ScopeResolver, policies, view-as (AD-6, AD-7, AD-8, AD-9)

The single `ScopeResolver` that maps a user's effective scope → the set of units
they may read (AD-8). Every echelon-scoped read filters through it — no controller
hand-rolls visibility. Also holds policies and the view-as control (view-as narrows,
never widens — AD-9).

**Reached from** `Controllers/` (and any CommandHandler that must scope a write).
The ScopeResolver reads the org tree and `portal_role_grants` through `Repositories/`
methods only (AD-2) — never the DB directly. Enforced in `deptrac.yaml` since Story 1.11.

**Roster-derived scope is not yet computed.** AD-7 names a default command subtree
derived from synced Cav roster context (billet, section/platoon), but nothing yet
correlates a trooper's billet to a `units` node — that's Epic 2's `troopers` cache.
Until it lands, `ScopeResolver` resolves Admin (full tree) and `portal_role_grants`
(explicit staff scope) only; everyone else is scopeless (deny-by-default, AD-8).
