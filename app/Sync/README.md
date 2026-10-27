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

**Placement (2026-10-24).** The mapping step places every trooper whose primary billet resolves: a
`unit_billet_mappings` row of any unit first (a section, a non-command HQ billet's platoon, company, battalion or the
regiment, a staff department, ELOA, Reservists, New Recruits), then the billet's `unit_command_mappings` row (a Platoon
Leader on their platoon, the RCO on the regiment). `troopers.primary_section_id` keeps its name but holds the primary
unit. A trooper the sync moves from a line unit into the ELOA group keeps that unit in `last_line_unit_id` (the "was");
one first seen on ELOA has none, and any later placement elsewhere clears it. A manual placement still stands only
until the billet resolves.
