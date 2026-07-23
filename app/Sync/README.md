# `Sync/` — one-writer cache sync handlers (AD-4, AD-22)

Handlers that refresh the read-mostly cache from the servers-of-record (forum /
Cav roster). **Exactly one** `Sync/` handler writes each cache table; display and
read code never writes synced truth.

**May depend on:** `Repositories/` (to upsert the cache) and a `Services/` seam (to
fetch from source). Sync writes are upserts keyed by the authoritative source key
(`cav_id`, `forum_thread_id`) — never truncate-and-reinsert. Every run is recorded
in `sync_runs`. Handlers are named `Sync{Thing}Handler`.
