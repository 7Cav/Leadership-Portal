# `Loa/` — read-only LOA logic (Epic 4)

Everything the portal needs to *read* about leave lives here, so no screen re-derives it.

- `LoaProvenance::current()` → `LoaSyncState` (4.3, AD-20): the source label every LOA value is
  shown with (`Forum thread`) and the time of the last **successful** LOA sync. `stale` is set
  when the latest finished run did not succeed, or when the last good sync is older than
  `STALE_AFTER_HOURS` (syncs that simply stopped must not look fresh), so the page says it is
  showing the last good cache (AD-4). With no successful sync yet, `neverSynced()` is true and
  `lastSyncedAt` is null: an explicit state, never a blank or an invented time.

**May depend on:** `Repositories/` (the facts, never the DB directly, AD-2) and `Models/`.
**Reached from:** the screens that show leave, once they exist. Read-only: the LOA sync
(`Sync/SyncLoasHandler`) is the one writer of `loas` and `sync_runs`.
