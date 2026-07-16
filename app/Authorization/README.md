# `Authorization/` — ScopeResolver, policies, view-as (AD-6, AD-7, AD-8, AD-9)

The single `ScopeResolver` that maps a user's effective scope → the set of units
they may read (AD-8). Every echelon-scoped read filters through it — no controller
or ReadService hand-rolls visibility. Also holds policies and the view-as control
(view-as narrows, never widens — AD-9).

**Reached from** `Controllers/` and `ReadServices/`. When the ScopeResolver needs
to read the org tree it will do so through a `Repositories/` method (AD-2); add that
edge to `deptrac.yaml` in the story that builds it — the graph evolves consciously.
