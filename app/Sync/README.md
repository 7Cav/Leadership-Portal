# `Sync/` — one-writer cache sync handlers (AD-4, AD-22)

Handlers that refresh the read-mostly cache from the servers-of-record (forum /
Cav roster). **Exactly one** `Sync/` handler writes each cache table; display and
read code never writes synced truth.

**May depend on:** `Repositories/` (to upsert the cache), `Models/`, and `Integration/` (to
fetch from source — there is no `Services/` facade layer). Sync writes are upserts keyed by
the authoritative source key (`cav_id`, `forum_thread_id`) — never truncate-and-reinsert.
Every run is recorded in `sync_runs` (Story 2.3). Handlers are named `Sync{Thing}Handler`.

Enforced by Deptrac since Story 2.2 (`SyncRosterHandler`, the `troopers` cache's one writer of synced
columns). One audited exception: `Commands\Troopers\PlaceTrooperInSectionHandler` (Story 2.7) writes
`primary_section_id` and `placement_source` for a trooper the sync could not place, and nothing else. The
sync's mapping step takes that placement over once Cav can resolve the trooper's billet.
