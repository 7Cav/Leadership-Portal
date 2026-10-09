<?php

namespace App\Sync;

use App\Integration\Cav\CavApi;
use App\Integration\Cav\Data\CavRosterEntry;
use App\Integration\Cav\Data\MilpacId;
use App\Integration\Cav\Data\RosterRowError;
use App\Integration\Cav\Data\RosterType;
use App\Models\TrooperStatus;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitBilletMappingRepository;
use Throwable;

/**
 * The sole writer of the `troopers` cache (AD-4, AD-22). `CavApi::fetchRoster()` is called
 * first, in full, before any write — a thrown `CavRequestException` (2.1) therefore aborts here
 * with the cache completely untouched, never a partial wipe. Once fetching succeeds, the
 * two-part write (upsert the active rows, mark everyone else departed) runs as one transaction
 * via {@see TrooperRepository::sync()}, so a crash mid-write cannot leave the cache torn between
 * the two halves either.
 *
 * Every call opens and closes a `sync_runs` row (Story 2.3, AD-16's carve-out for system jobs —
 * this does not route through the per-Command audited-action envelope). `$triggeredBy` defaults
 * to `'system'` since the scheduler (2.4) is, for now, the only caller.
 *
 * Mapping each trooper into their primary section (2.5) runs after the upsert above, as a second
 * write rather than inside the same transaction: 2.2's `TrooperRepository::sync()` contract is
 * already shipped and tested, and the mapping step is idempotent — recomputed fresh from the
 * billet table on every run, so a crash between the two writes self-heals on the very next
 * scheduled sync rather than needing its own recovery path.
 *
 * The primary billet itself (`cav_position_id`) is written with the synced columns, so the
 * roster-derived command scope (1.16) follows a billet change on the very next sync. The forum
 * identity (`username`, `forum_user_id`) and short rank are written the same way (2.10), so a
 * forum rename follows on the next sync too.
 */
class SyncRosterHandler
{
    public function __construct(
        private readonly CavApi $cavApi,
        private readonly TrooperRepository $troopers,
        private readonly SyncRunRepository $syncRuns,
        private readonly UnitBilletMappingRepository $billetMappings,
    ) {}

    public function handle(int|string $triggeredBy = 'system'): void
    {
        $run = $this->syncRuns->open('roster', $triggeredBy);

        try {
            $result = $this->cavApi->fetchRoster();

            $rows = array_map(
                fn (CavRosterEntry $entry) => [
                    'cav_id' => $entry->cavId,
                    'rank' => $entry->rankFull,
                    'rank_short' => $entry->rankShort,
                    'username' => $entry->username,
                    'forum_user_id' => $entry->forumUserId,
                    'mos' => $entry->mos,
                    'tis' => $entry->tis,
                    'tig' => $entry->tig,
                    'status' => $this->statusFor($entry->status)->value,
                    'cav_position_id' => $entry->positionId,
                ],
                $result->troopers,
            );

            // A row Cav reported but the seam could not parse (2.1, AC #6) is a trooper who is
            // still present, just unusably described — it must not read as "disappeared" and be
            // marked departed alongside troopers genuinely absent from every roster. The row's
            // *key* is still the milpac id the sweep found them under, so it counts toward the
            // active set even without the fields to upsert.
            $unreadableCavIds = array_values(array_filter(array_map(
                fn ($error) => MilpacId::parse($error->key),
                $result->errors,
            )));
            $parsedCavIds = array_column($rows, 'cav_id');
            $activeCavIds = [...$parsedCavIds, ...$unreadableCavIds];

            // An unreadable row's billet is unknown, so it is cleared rather than left conferring
            // the last-known command scope (1.16) — unless the same trooper also parsed on another
            // roster this run, whose billet is then current and kept.
            $counts = $this->troopers->sync(
                $rows,
                array_values(array_unique($activeCavIds)),
                array_values(array_diff(array_unique($unreadableCavIds), $parsedCavIds)),
            );

            $this->troopers->updatePrimarySections($this->resolveSections($result->troopers));

            $this->syncRuns->complete(
                $run,
                inserted: $counts['inserted'],
                updated: $counts['updated'],
                inactivated: $counts['inactivated'],
                errored: count($result->errors),
                error: $this->errorSummary($result->errors),
            );
        } catch (Throwable $e) {
            $this->syncRuns->fail($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * The per-row detail for a partial run's `sync_runs.error` column — otherwise a partial run's
     * `errored` count says *how many* rows were bad with no way to find out *which*.
     *
     * @param  list<RosterRowError>  $errors
     */
    private function errorSummary(array $errors): ?string
    {
        if ($errors === []) {
            return null;
        }

        return implode('; ', array_map(
            fn (RosterRowError $error) => "{$error->key}: {$error->reason}",
            $errors,
        ));
    }

    /**
     * Resolve each fetched trooper's billet to a section unit id via the curated
     * `unit_billet_mappings` table (2.5) — a trooper with no `positionId` (2.1, AC #6's per-field
     * degrade) or whose billet has no mapping is simply absent from the returned map rather than
     * carrying a null, so {@see TrooperRepository::updatePrimarySections()} leaves their existing
     * value untouched instead of nulling it.
     *
     * @param  list<CavRosterEntry>  $entries
     * @return array<int, int> cav_id => units.id
     */
    private function resolveSections(array $entries): array
    {
        $sectionIdsByPosition = $this->billetMappings->sectionIdsByPositionId();

        $resolved = [];

        foreach ($entries as $entry) {
            if ($entry->positionId !== null && $sectionIdsByPosition->has($entry->positionId)) {
                $resolved[$entry->cavId] = $sectionIdsByPosition->get($entry->positionId);
            }
        }

        return $resolved;
    }

    /**
     * A roster type Cav didn't report (unrecognised or absent) carries no more standing than
     * departed does — {@see CavRosterEntry} already reduces the API's own `*_UNSPECIFIED` case
     * to null, so this is the only fallback this mapping needs.
     */
    private function statusFor(?RosterType $rosterType): TrooperStatus
    {
        return match ($rosterType) {
            RosterType::Combat => TrooperStatus::Combat,
            RosterType::Reserve => TrooperStatus::Reserve,
            RosterType::Eloa => TrooperStatus::Eloa,
            default => TrooperStatus::Inactive,
        };
    }
}
