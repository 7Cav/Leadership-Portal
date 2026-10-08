<?php

namespace App\Commands\Units;

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavPositionGroup;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Facades\Log;

/**
 * Build the chain of command and the billet → section mapping from the Cav position groups
 * (story 2.9, AD-18). Admin-only — enforced by the controller (AD-6), same as {@see CreateUnit}.
 *
 * Every line-company group (`A/1-7`) yields its Battalion and Company; every billet title in it
 * yields the Platoon / Section it names (see {@see CavDesignation}), and every section-level
 * billet is mapped to its section by `cav_position_id`. Non-line groups (`Regimental HQ`,
 * `1-7 Command`, `Retired`, …) are ignored.
 *
 * **Additive and idempotent.** An existing node is reused when its type + callsign match the
 * designation (the regiment root also falls back to any existing root regiment, so the tree
 * keeps a single root), and is never renamed, moved or deleted; an existing mapping is never
 * repointed — one that now disagrees with Cav is counted in the result instead, so a reorg is
 * visible without the import overriding a deliberate correction. A re-run with unchanged input
 * therefore creates no unit or mapping — but still records an audit row, so the log shows the
 * import ran.
 *
 * **All or nothing.** The Cav fetch and the parsing happen before any write; the writes happen
 * inside the audited envelope's single transaction (AD-16), so a failure part-way leaves no
 * partial tree. The audit row targets the regiment root — the import's one stable subject.
 */
class ImportOrgStructure
{
    public function __construct(
        private readonly CavApi $cav,
        private readonly UnitRepository $units,
        private readonly UnitBilletMappingRepository $mappings,
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

        [$designations, $sectionByPositionId, $skippedTitles, $ignoredGroups] = $this->plan($groups);

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
        $mappingsCreated = 0;
        $mappingsDiffering = 0;

        $this->audit->record(
            actorId: $actor->id,
            action: 'org_structure.imported',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($designations, $sectionByPositionId, &$unitsCreated, &$mappingsCreated, &$mappingsDiffering): Unit {
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

                $existing = $this->mappings->unitIdsByPositionId();

                foreach ($sectionByPositionId as $positionId => $sectionKey) {
                    $sectionId = $index[$sectionKey]->id;

                    if ($existing->has($positionId)) {
                        if ($existing->get($positionId) !== $sectionId) {
                            $mappingsDiffering++;
                        }

                        continue;
                    }

                    $this->mappings->create($sectionId, $positionId);
                    $mappingsCreated++;
                }

                return $root;
            },
        );

        return new OrgStructureImportResult($unitsCreated, $mappingsCreated, $mappingsDiffering, $skippedTitles);
    }

    /**
     * Turn the groups into the ordered set of nodes to ensure and the billets to map — pure, no
     * DB access, so everything the import will write is decided before the transaction opens;
     * an unrecognised title is skipped and reported, never fatal. Returns the designations to
     * ensure (keyed, each after its parent), the section key per mapped position id, the titles
     * skipped as unparseable, and the titles of groups ignored as not line companies.
     *
     * @param  list<CavPositionGroup>  $groups  in Cav display order
     * @return array{0: array<string, CavDesignation>, 1: array<int, string>, 2: list<string>, 3: list<string>}
     */
    private function plan(array $groups): array
    {
        $designations = [];
        $sectionByPositionId = [];
        $skippedTitles = [];
        $ignoredGroups = [];

        foreach ($groups as $group) {
            $company = CavDesignation::companyGroup($group->title);

            if ($company === null) {
                $ignoredGroups[] = $group->title;

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
            }
        }

        return [$designations, $sectionByPositionId, $skippedTitles, $ignoredGroups];
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
