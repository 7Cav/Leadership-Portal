# `Milpac/` — what the trooper detail page shows (Epic 6)

`TrooperMilpac::for($trooper)` returns the props of the one `TrooperDetail` page, whichever way a
leader reached the trooper:

- `trooper`: the ID block from the cached Cav roster (rank, billet, MOS, time in service and in grade, primary
  section). A trooper the sync could not place has `section: null`, shown as unmapped; one who has left every
  roster still renders, as departed, from their last cached identity.
- `sync`: where the ID block came from and how current it is (AD-20): the last successful roster sync, and a stale
  flag when a later run did not succeed.

Durations count on the server's UTC day (AD-11).

**May depend on:** `Repositories/` (never the DB directly, AD-2) and `Models/`.
**Reached from:** `Http/Controllers/TrooperDetailController`. Read-only. It never decides who may look: the
controller gates every request with the ScopeResolver (AD-8) before asking.
