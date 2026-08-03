# `Authorization/` — ScopeResolver, policies, view-as (AD-6, AD-7, AD-8, AD-9)

The single `ScopeResolver` that maps a user's effective scope → the set of units
they may read (AD-8). Every echelon-scoped read filters through it — no controller
hand-rolls visibility. Also holds policies and the view-as control (view-as narrows,
never widens — AD-9).

**Reached from** `Controllers/` (and any CommandHandler that must scope a write).
When the ScopeResolver needs to read the org tree it will do so through a
`Repositories/` method (AD-2).

> **Not yet an enforced layer.** This directory is a placeholder; `Authorization/`
> re-enters `deptrac.yaml` — with its edges — when its story lands (Story 1.11).
