# `Loa/` — read-only LOA logic (Epic 4)

Everything the portal needs to *read* about leave lives here, so no screen re-derives it.

- `LoaProvenance::current()` → `LoaSyncState` (4.3, AD-20): the source label every LOA value is
  shown with (`Forum thread`) and the time of the last **successful** LOA sync. `stale` is set
  when the latest finished run did not succeed, so the page says it is showing the last good
  cache (AD-4). With no successful sync yet, `neverSynced()` is true and `lastSyncedAt` is null:
  an explicit state, never a blank or an invented time.

**May depend on:** `Repositories/` (the facts, never the DB directly, AD-2), `Models/`,
`Support/Time/` (the week window, AD-11).
**Reached from:** `Http/Controllers/`. Read-only: the LOA sync (`Sync/SyncLoasHandler`) is the one
writer of `loas` and `sync_runs`.
