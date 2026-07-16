# `ReadServices/` — read-side prop shaping (AD-15, AD-20)

Shapes the props a controller hands to `Inertia::render`. The read side is
pragmatic (AD-15): controllers call a ReadService (or a read-oriented Repository
method) directly — there are **no** QueryHandlers for simple reads.

**May depend on:** `Repositories/` and `Authorization/` (the ScopeResolver — every
echelon-scoped read filters through it, AD-8). **Must not** touch `Models/`/DB
directly (that skip edge is forbidden — go through a Repository, AD-2).

`Queries/` is reserved for the one genuinely complex read: the echelon roll-up read
model (AD-13). Introduce a dedicated Query object only there.
