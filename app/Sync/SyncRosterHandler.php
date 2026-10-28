<?php

namespace App\Sync;

use App\Integration\Cav\CavApi;
use App\Integration\Cav\Data\CavRosterEntry;
use App\Integration\Cav\Data\MilpacId;
use App\Integration\Cav\Data\RosterRowError;
use App\Integration\Cav\Data\RosterType;
use App\Models\SyncRun;
use App\Models\TrooperStatus;
use App\Models\UnitKind;
use App\Repositories\AdminBilletRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
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
 * Since 2026-10-24 the mapping places every trooper whose billet resolves, not only section billets: a billet mapping
 * of any unit first (a section, a non-command HQ billet's HQ node, a staff department, ELOA, Reservists, New Recruits),
 * then the command mapping (a Platoon Leader on their platoon, a Commander on their company, the RCO on the regiment).
 * A trooper moved from a line unit into ELOA keeps it as their "was" ({@see TrooperRepository::updatePrimarySections()}).
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
 *
 * A serving roster that comes back empty while the cache still holds troopers on it is refused
 * the same way a fetch failure is: the run is recorded as failed and nothing is written (see
 * {@see refuseEmptyRosterExodus()}).
 *
 * An echelon leader's manual sync passes `$unitId`: Cav only serves whole rosters, so the fetch and the
 * empty-roster guard are the same, but the write is cut down to that unit's subtree by a {@see RosterSyncScope} —
 * troopers placed in it, plus any whose billet now maps into it. Departures are only considered among troopers
 * placed in the subtree, a trooper whose billet moved them out is written through to their new section, and the run
 * is recorded under its own source so the global "last synced" stamp never claims a roster-wide refresh an
 * echelon sync did not do.
 *
 * A Regimental HQ billet that makes its holder an Admin (`admin_billets`, decided by the S6, 2026-10-28) is checked
 * against the title Cav shows now: renamed, it goes back to pending and its holder stops being an Admin until an Admin
 * confirms the new name. The roster carries no group title; the org import records that.
 */
class SyncRosterHandler
{
    /** How many troopers an empty roster may depart before the run is refused as a broken response. */
    private const EMPTY_ROSTER_TOLERANCE = 3;

    public function __construct(
        private readonly CavApi $cavApi,
        private readonly TrooperRepository $troopers,
        private readonly SyncRunRepository $syncRuns,
        private readonly UnitBilletMappingRepository $billetMappings,
        private readonly RosterSyncScopeFactory $scopes,
        private readonly UnitCommandMappingRepository $commandMappings,
        private readonly UnitRepository $units,
        private readonly AdminBilletRepository $adminBillets,
    ) {}

    /**
     * @throws UnitNoLongerExistsException before any run is opened, when `$unitId` has been deleted
     */
    public function handle(int|string $triggeredBy = 'system', ?int $unitId = null): void
    {
        // Looked up before the run is opened: a unit deleted since the click is permanent, not a failed run.
        $sectionIds = $unitId === null ? null : $this->scopes->sectionIdsOf($unitId);

        // The job's lock serialises every roster run, whole or echelon, so any run still `in_progress` now was killed
        // before it could record itself. Closing it keeps the sync's state honest.
        $this->syncRuns->failOrphaned(SyncRun::SOURCE_ROSTER);
        $this->syncRuns->failOrphaned(SyncRun::SOURCE_ROSTER_UNIT);

        $run = $this->syncRuns->open($unitId === null ? SyncRun::SOURCE_ROSTER : SyncRun::SOURCE_ROSTER_UNIT, $triggeredBy, unitId: $unitId);

        try {
            $result = $this->cavApi->fetchRoster();

            // A row Cav reported but the seam could not parse (2.1, AC #6) is a trooper who is
            // still present, just unusably described — it must not read as "disappeared" and be
            // marked departed alongside troopers genuinely absent from every roster. The row's
            // *key* is still the milpac id the sweep found them under, so it counts toward the
            // active set even without the fields to upsert.
            $unreadableCavIds = array_values(array_filter(array_map(
                fn ($error) => MilpacId::parse($error->key),
                $result->errors,
            )));
            $parsedCavIds = array_map(fn (CavRosterEntry $entry) => $entry->cavId, $result->troopers);
            $activeCavIds = array_values(array_unique([...$parsedCavIds, ...$unreadableCavIds]));

            // Judged on the whole response whatever the scope: whether Cav's answer is believable does not depend
            // on which echelon asked, and counting against an echelon's slice alone would refuse a good sync.
            $this->refuseEmptyRosterExodus($result->emptyRosters, $activeCavIds);

            $sections = $this->resolveSections($result->troopers);
            $scope = $this->scopes->build($sectionIds, $sections);

            $rows = array_map(
                fn (CavRosterEntry $entry) => [
                    'cav_id' => $entry->cavId,
                    'rank' => $entry->rankFull,
                    'rank_short' => $entry->rankShort,
                    'rank_image_url' => $entry->rankImageUrl,
                    'username' => $entry->username,
                    'forum_user_id' => $entry->forumUserId,
                    'billet' => $entry->billet,
                    'mos' => $entry->mos,
                    'tis' => $entry->tis,
                    'tig' => $entry->tig,
                    'status' => $this->statusFor($entry->status)->value,
                    'cav_position_id' => $entry->positionId,
                ],
                $scope->filterEntries($result->troopers),
            );

            // An unreadable row's billet is unknown, so it is cleared rather than left conferring
            // the last-known command scope (1.16) — unless the same trooper also parsed on another
            // roster this run, whose billet is then current and kept.
            $counts = $this->troopers->sync(
                $rows,
                $activeCavIds,
                $scope->filterCavIds(array_values(array_diff(array_unique($unreadableCavIds), $parsedCavIds))),
                $scope->sectionIds,
            );

            $this->troopers->updatePrimarySections($scope->filterSections($sections), $this->units->idsOfKind(UnitKind::Eloa)->all());

            // Only billets that already have a row: the import creates them, as it is what sees the group.
            $this->adminBillets->observe($this->titlesByPosition($scope->filterEntries($result->troopers)), createMissing: false);

            // A unit run reports only its own troopers' bad rows: one elsewhere is not this unit's partial sync.
            $errors = $scope->filterErrors($result->errors);

            $this->syncRuns->complete(
                $run,
                inserted: $counts['inserted'],
                updated: $counts['updated'],
                inactivated: $counts['inactivated'],
                errored: count($errors),
                error: $this->errorSummary($errors),
            );
        } catch (Throwable $e) {
            $this->recordFailure($run, $e);

            throw $e;
        }
    }

    /**
     * Close the run as failed without letting a second failure (the database that broke the sync is often the one
     * recording it) replace the exception that is about to be rethrown.
     */
    private function recordFailure(SyncRun $run, Throwable $e): void
    {
        try {
            $this->syncRuns->fail($run, $e::class.': '.$e->getMessage());
        } catch (Throwable $recording) {
            report($recording);
        }
    }

    /**
     * Refuse a run in which a serving roster came back empty while the cache still holds
     * troopers on it who are on no other roster this run. Cav answering one roster with `{}` is
     * a well-formed response, so nothing upstream catches it, and believing it would mark that
     * whole roster departed: every leader on it would lose their scope until a later sync
     * restored them. The position-groups fetch refuses an empty list outright for the same
     * reason (2.9).
     *
     * Narrower than refusing every empty roster on purpose. A small roster such as ELOA can
     * really empty out, and a guard with no way through would wedge the sync for good, refusing
     * every other trooper's departures and billet changes until someone edited the database. So
     * an empty roster is let through while it would depart at most `EMPTY_ROSTER_TOLERANCE`
     * troopers (the last member or two being discharged), and so is one whose last members all
     * moved to another roster this run. What is refused is an empty roster that would depart a
     * crowd, and any run where every serving roster came back empty, which no real roster change
     * produces.
     *
     * Thrown before any write, so `handle()` records the run as failed and the cache is untouched.
     *
     * @param  list<RosterType>  $emptyRosters
     * @param  list<int>  $activeCavIds
     *
     * @throws EmptyRosterException
     */
    private function refuseEmptyRosterExodus(array $emptyRosters, array $activeCavIds): void
    {
        $everyRosterEmpty = count($emptyRosters) === count(RosterType::serving());

        foreach ($emptyRosters as $roster) {
            $departing = $this->troopers->countDepartingWithStatus($this->statusFor($roster), $activeCavIds);

            if ($departing > ($everyRosterEmpty ? 0 : self::EMPTY_ROSTER_TOLERANCE)) {
                throw EmptyRosterException::wouldDepart($roster->label(), $departing);
            }
        }
    }

    /**
     * The per-row detail for a partial run's `sync_runs.error` column — otherwise a partial run's
     * `errored` count says *how many* rows were bad with no way to find out *which*. A mass parse
     * failure makes this long; the repository keeps its first 2,000 characters, which still name
     * the first rows to look at.
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
     * Resolve each fetched trooper's billet to their primary unit id: the curated `unit_billet_mappings` table (2.5),
     * whatever unit it points at, and else the billet's command mapping (1.16), so HQ staff land on the HQ they lead
     * (2026-10-24). A trooper with no `positionId` (2.1, AC #6's per-field degrade) or whose billet maps nowhere is
     * simply absent from the returned map rather than carrying a null, so
     * {@see TrooperRepository::updatePrimarySections()} leaves their existing value untouched instead of nulling it.
     *
     * @param  list<CavRosterEntry>  $entries
     * @return array<int, int> cav_id => units.id
     */
    private function resolveSections(array $entries): array
    {
        $placed = $this->billetMappings->unitIdsByPositionId();
        $commanded = $this->commandMappings->unitIdsByPositionId();

        $resolved = [];

        foreach ($entries as $entry) {
            $unitId = $entry->positionId === null ? null : ($placed->get($entry->positionId) ?? $commanded->get($entry->positionId));

            if ($unitId !== null) {
                $resolved[$entry->cavId] = (int) $unitId;
            }
        }

        return $resolved;
    }

    /**
     * The billet title each position carries on this roster, `position id => [title, null]` (the roster has no group
     * title), for {@see AdminBilletRepository::observe()}. A row with no position or no readable title says nothing.
     *
     * @param  list<CavRosterEntry>  $entries
     * @return array<int, array{0: string, 1: null}>
     */
    private function titlesByPosition(array $entries): array
    {
        $titles = [];

        foreach ($entries as $entry) {
            if ($entry->positionId !== null && $entry->billet !== null) {
                $titles[$entry->positionId] ??= [$entry->billet, null];
            }
        }

        return $titles;
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
