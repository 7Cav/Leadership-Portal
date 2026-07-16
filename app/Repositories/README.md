# `Repositories/` — the sole DB access layer (AD-2)

**The only layer that may touch the database.** No Eloquent query, raw SQL, or
`DB::` call lives anywhere else in `app/` — Deptrac fails the build if it does.
Every read and write goes through a Repository method.

**May depend on:** `Models/` and the database (Eloquent / query builder / `DB`).
Repositories return fully-loaded models or DTOs; `preventLazyLoading()` is on in
dev so a query escaping this layer via lazy-loading throws loudly.
