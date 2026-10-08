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
 * repointed. A re-run with unchanged input therefore writes nothing — but is still audited, so
 * the log shows the import ran.
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
            throw OrgStructureImportFailedException::cavUnavailable($e);
        }

        [$designations, $sectionByPositionId, $skippedTitles] = $this->plan($groups);

        if ($designations === []) {
            throw OrgStructureImportFailedException::noLineCompanies();
        }

        $unitsCreated = 0;
        $mappingsCreated = 0;

        $this->audit->record(
            actorId: $actor->id,
            action: 'org_structure.imported',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($designations, $sectionByPositionId, &$unitsCreated, &$mappingsCreated): Unit {
                $index = $this->existingUnitsByKey();
                $root = $index[self::key(CavDesignation::regiment())] ?? null;

                if ($root === null) {
                    $root = $this->units->create(UnitType::Regiment, CavDesignation::REGIMENT_NAME, CavDesignation::REGIMENT_CALLSIGN, null);
                    $index[self::key(CavDesignation::regiment())] = $root;
                    $unitsCreated++;
                }

                // `plan()` lists every designation after its parent, so the parent is always
                // already in the index by the time a child is reached.
                foreach ($designations as $key => $designation) {
                    if (isset($index[$key])) {
                        continue;
                    }

                    $parent = $index[self::key($designation->parent())];
                    $index[$key] = $this->units->create($designation->type, $designation->name(), $designation->callsign(), $parent->id);
                    $unitsCreated++;
                }

                $alreadyMapped = $this->mappings->mappedPositionIds()->flip();

                foreach ($sectionByPositionId as $positionId => $sectionKey) {
                    if ($alreadyMapped->has($positionId)) {
                        continue;
                    }

                    $this->mappings->create($index[$sectionKey]->id, $positionId);
                    $mappingsCreated++;
                }

                return $root;
            },
        );

        return new OrgStructureImportResult($unitsCreated, $mappingsCreated, $skippedTitles);
    }

    /**
     * Turn the groups into the ordered set of nodes to ensure and the billets to map — pure, no
     * DB access, so a payload this cannot read fails before the transaction opens. Returns the
     * designations to ensure (keyed, each after its parent), the section key per mapped position
     * id, and the titles skipped as unparseable.
     *
     * @param  list<CavPositionGroup>  $groups  in Cav display order
     * @return array{0: array<string, CavDesignation>, 1: array<int, string>, 2: list<string>}
     */
    private function plan(array $groups): array
    {
        $designations = [];
        $sectionByPositionId = [];
        $skippedTitles = [];

        foreach ($groups as $group) {
            $company = CavDesignation::companyGroup($group->title);

            if ($company === null) {
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
                    $sectionByPositionId[$position->id] ??= self::key($designation);
                }
            }
        }

        return [$designations, $sectionByPositionId, $skippedTitles];
    }

    /**
     * @param  array<string, CavDesignation>  $designations
     */
    private function addWithAncestors(array &$designations, CavDesignation $designation): void
    {
        $key = self::key($designation);

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
                $index[$unit->type->value.'|'.$unit->callsign] ??= $unit;
            }
        }

        $regimentKey = self::key(CavDesignation::regiment());

        if (! isset($index[$regimentKey]) && $rootRegiment !== null) {
            $index[$regimentKey] = $rootRegiment;
        }

        return $index;
    }

    private static function key(CavDesignation $designation): string
    {
        return $designation->type->value.'|'.$designation->callsign();
    }
}
