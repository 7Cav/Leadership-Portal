<?php

namespace App\Commands\Units;

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavPositionGroup;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Build the chain of command and the billet → section mapping from the Cav position groups
 * (story 2.9, AD-18). Admin-only — enforced by the controller (AD-6), same as {@see CreateUnit}.
 *
 * Every line-company group (`A/1-7`) yields its Battalion and Company; every billet title in it
 * yields the Platoon / Section it names (see {@see CavDesignation}), and every section-level
 * billet is mapped to its section by `cav_position_id`. Non-line groups (`Regimental HQ`,
 * `1-7 Command`, `Retired`, …) create no node.
 *
 * Every leadership billet — in a line company, a `<bn> Command` group or Regimental HQ — is also
 * mapped to the unit it commands (story 1.16, {@see CommandBillet}), the input to the
 * roster-derived half of `ScopeResolver`. A command billet whose unit the import has no node for
 * (a battalion command group with no line company) is not mapped — commands never create nodes —
 * and is logged as unplaced.
 *
 * **Additive and idempotent.** An existing node is reused when its type + callsign match the
 * designation (the regiment root also falls back to any existing root regiment, so the tree
 * keeps a single root), and is never renamed, moved or deleted; an existing section mapping is
 * never repointed — one that now disagrees with Cav is counted in the result instead, so a reorg is
 * visible without the import overriding a deliberate correction. A re-run with unchanged input
 * therefore creates no unit or mapping — but still records an audit row, so the log shows the
 * import ran.
 *
 * **Command mappings are the exception: reconciled, not additive.** They are pure derived data
 * with no hand curation to protect, and a stale one keeps granting scope (AD-8 deny-by-default),
 * so every run makes `unit_command_mappings` exactly what Cav publishes — new billets added,
 * moved ones repointed, billets no longer listed as a command removed. Still idempotent.
 *
 * **All or nothing.** The Cav fetch and the parsing happen before any write; the writes happen
 * inside the audited envelope's single transaction (AD-16), so a failure part-way leaves no
 * partial tree. The audit row targets the regiment root — the import's one stable subject.
 */
class ImportOrgStructure
{
    private const LOCK = 'org-structure-import';

    /** Long enough for the largest import to commit; a crashed run frees the lock after this. */
    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly CavApi $cav,
        private readonly UnitRepository $units,
        private readonly UnitBilletMappingRepository $mappings,
        private readonly UnitCommandMappingRepository $commands,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws OrgStructureImportFailedException when Cav could not be read or lists no line
     *                                           company — before anything is written.
     */
    public function handle(User $actor): OrgStructureImportResult
    {
        try {
            $groups = $this->cav->fetchPositionGroups()->groups;
        } catch (CavRequestException $e) {
            // The controller turns this into a form error, which Laravel never reports — so this
            // line is the only trace an outage or a missing key leaves.
            Log::warning('Org-structure import aborted: the Cav position groups could not be read.', [
                'actor_id' => $actor->id,
                'reason' => $e->reason->value,
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]);

            throw OrgStructureImportFailedException::cavUnavailable($e);
        }

        [$designations, $sectionByPositionId, $commandByPositionId, $skippedTitles, $ignoredGroups] = $this->plan($groups);

        if ($designations === []) {
            Log::warning('Org-structure import aborted: Cav listed no line companies.', [
                'actor_id' => $actor->id,
                'ignored_groups' => $ignoredGroups,
            ]);

            throw OrgStructureImportFailedException::noLineCompanies();
        }

        // Kept past the one-render flash, for whoever later asks why a billet is unmapped or a
        // company never appeared (a group renamed out of the `<letter>/<battalion>` shape).
        Log::info('Org-structure import: groups ignored and titles skipped.', [
            'actor_id' => $actor->id,
            'ignored_groups' => $ignoredGroups,
            'skipped_titles' => $skippedTitles,
        ]);

        $unitsCreated = 0;
        $sectionCounts = [0, 0];
        $commandCounts = [0, 0, 0, []];

        // One import at a time: the lookup of existing units below takes no lock and `units` has no
        // unique key, so two imports at once could both miss a unit and both create it. The lock is
        // held until the transaction commits; a second import is refused, not queued.
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw OrgStructureImportFailedException::alreadyRunning();
        }

        try {
            $this->audit->record(
                actorId: $actor->id,
                action: 'org_structure.imported',
                reason: null,
                reasonRequired: false,
                mutation: function () use ($designations, $sectionByPositionId, $commandByPositionId, &$unitsCreated, &$sectionCounts, &$commandCounts): Unit {
                    $index = $this->existingUnitsByKey();
                    $regimentKey = CavDesignation::regiment()->key();
                    $root = $index[$regimentKey] ?? null;

                    if ($root === null) {
                        $root = $this->units->create(UnitType::Regiment, CavDesignation::REGIMENT_NAME, CavDesignation::REGIMENT_CALLSIGN, null);
                        $index[$regimentKey] = $root;
                        $unitsCreated++;
                    }

                    // `plan()` lists every designation after its parent, so the parent is always
                    // already in the index by the time a child is reached.
                    foreach ($designations as $key => $designation) {
                        if (isset($index[$key])) {
                            continue;
                        }

                        $parent = $index[$designation->parent()->key()];
                        $index[$key] = $this->units->create($designation->type, $designation->name(), $designation->callsign(), $parent->id);
                        $unitsCreated++;
                    }

                    $sectionCounts = $this->addMappings(
                        $sectionByPositionId,
                        $index,
                        $this->mappings->unitIdsByPositionId(),
                        fn (int $unitId, int $positionId) => $this->mappings->create($unitId, $positionId),
                    );

                    $commandCounts = $this->reconcileCommands($commandByPositionId, $index);

                    return $root;
                },
            );
        } finally {
            $lock->release();
        }

        [$commandsCreated, $commandsRepointed, $commandsRemoved, $unplacedCommands] = $commandCounts;

        if ($unplacedCommands !== [] || $commandsRepointed > 0 || $commandsRemoved > 0) {
            // The scope-changing outcomes of a run: who gained a different command, who lost one,
            // and which command billets have no node to command (that leader derives no scope).
            Log::info('Org-structure import: command mappings changed or unplaced.', [
                'actor_id' => $actor->id,
                'repointed' => $commandsRepointed,
                'removed' => $commandsRemoved,
                'unplaced' => $unplacedCommands,
            ]);
        }

        return new OrgStructureImportResult(
            $unitsCreated, $sectionCounts[0], $sectionCounts[1], $skippedTitles,
            $commandsCreated, $commandsRepointed, $commandsRemoved,
        );
    }

    /**
     * Make `unit_command_mappings` exactly the planned commands: create new billets, repoint
     * moved ones, remove every billet not (or no longer) planned. A planned billet whose unit has
     * no node is not mapped — and an existing row for it is removed with the rest — and returned
     * as unplaced. Returns `[created, repointed, removed, unplaced position id => unit key]`.
     *
     * @param  array<int, string>  $unitKeyByPositionId
     * @param  array<string, Unit>  $index
     * @return array{0: int, 1: int, 2: int, 3: array<int, string>}
     */
    private function reconcileCommands(array $unitKeyByPositionId, array $index): array
    {
        $existing = $this->commands->unitIdsByPositionId();
        $created = 0;
        $repointed = 0;
        $placed = [];
        $unplaced = [];

        foreach ($unitKeyByPositionId as $positionId => $unitKey) {
            $unit = $index[$unitKey] ?? null;

            if ($unit === null) {
                $unplaced[$positionId] = $unitKey;

                continue;
            }

            $placed[] = $positionId;

            if (! $existing->has($positionId)) {
                $this->commands->create($unit->id, $positionId);
                $created++;
            } elseif ($existing->get($positionId) !== $unit->id) {
                $this->commands->repoint($positionId, $unit->id);
                $repointed++;
            }
        }

        $removed = $this->commands->deleteExcept($placed);

        return [$created, $repointed, $removed, $unplaced];
    }

    /**
     * Add every planned `position id => unit key` mapping that does not exist yet; never repoint
     * one that does — an existing row that now disagrees with Cav is only counted. Returns
     * `[created, differing]`.
     *
     * @param  array<int, string>  $unitKeyByPositionId
     * @param  array<string, Unit>  $index
     * @param  Collection<int, int>  $existing  position id => unit id
     * @param  callable(int, int): mixed  $create  (unit id, position id)
     * @return array{0: int, 1: int}
     */
    private function addMappings(array $unitKeyByPositionId, array $index, Collection $existing, callable $create): array
    {
        $created = 0;
        $differing = 0;

        foreach ($unitKeyByPositionId as $positionId => $unitKey) {
            $unit = $index[$unitKey];

            if ($existing->has($positionId)) {
                if ($existing->get($positionId) !== $unit->id) {
                    $differing++;
                }

                continue;
            }

            $create($unit->id, $positionId);
            $created++;
        }

        return [$created, $differing];
    }

    /**
     * Turn the groups into the ordered set of nodes to ensure and the billets to map — pure, no
     * DB access, so everything the import will write is decided before the transaction opens;
     * an unrecognised title is skipped and reported, never fatal. Returns the designations to
     * ensure (keyed, each after its parent), the section key per mapped position id, the
     * commanded unit's key per leadership position id, the titles skipped as unparseable, and the
     * titles of groups ignored as not line companies.
     *
     * @param  list<CavPositionGroup>  $groups  in Cav display order
     * @return array{0: array<string, CavDesignation>, 1: array<int, string>, 2: array<int, string>, 3: list<string>, 4: list<string>}
     */
    private function plan(array $groups): array
    {
        $designations = [];
        $sectionByPositionId = [];
        $commandByPositionId = [];
        $skippedTitles = [];
        $ignoredGroups = [];

        foreach ($groups as $group) {
            $company = CavDesignation::companyGroup($group->title);

            if ($company === null) {
                $ignoredGroups[] = $group->title;

                foreach ($group->positions as $position) {
                    $commanded = CommandBillet::inCommandGroup($group->title, $position->title);

                    if ($commanded !== null) {
                        $commandByPositionId[$position->id] ??= $commanded->key();
                    }
                }

                continue;
            }

            $this->addWithAncestors($designations, $company);

            foreach ($group->positions as $position) {
                $designation = CavDesignation::fromPositionTitle($position->title, $company);

                if ($designation === null) {
                    $skippedTitles[] = $position->title;

                    continue;
                }

                $this->addWithAncestors($designations, $designation);

                if ($designation->type === UnitType::Section) {
                    // First listing wins should Cav ever list one position id twice — the
                    // mapping table holds one row per billet.
                    $sectionByPositionId[$position->id] ??= $designation->key();
                }

                if (CommandBillet::inLineGroup($position->title, $designation) !== null) {
                    $commandByPositionId[$position->id] ??= $designation->key();
                }
            }
        }

        return [$designations, $sectionByPositionId, $commandByPositionId, $skippedTitles, $ignoredGroups];
    }

    /**
     * @param  array<string, CavDesignation>  $designations
     */
    private function addWithAncestors(array &$designations, CavDesignation $designation): void
    {
        $key = $designation->key();

        if ($designation->type === UnitType::Regiment || isset($designations[$key])) {
            return;
        }

        $this->addWithAncestors($designations, $designation->parent());
        $designations[$key] = $designation;
    }

    /**
     * Every existing unit with a callsign, keyed on type + callsign. Should two nodes share one
     * (the column is not unique; an Admin may have hand-authored a duplicate), the oldest wins,
     * so repeated imports always pick the same node. When no regiment carries the `7CAV`
     * callsign, the oldest root regiment stands in for it, so an import into a hand-authored tree
     * hangs off that tree's root rather than starting a second one.
     *
     * @return array<string, Unit>
     */
    private function existingUnitsByKey(): array
    {
        $index = [];
        $rootRegiment = null;

        foreach ($this->units->tree()->sortBy('id') as $unit) {
            if ($unit->type === UnitType::Regiment && $unit->parent_id === null) {
                $rootRegiment ??= $unit;
            }

            if ($unit->callsign !== null) {
                $index[CavDesignation::keyFor($unit->type, $unit->callsign)] ??= $unit;
            }
        }

        $regimentKey = CavDesignation::regiment()->key();

        if (! isset($index[$regimentKey]) && $rootRegiment !== null) {
            $index[$regimentKey] = $rootRegiment;
        }

        return $index;
    }
}
