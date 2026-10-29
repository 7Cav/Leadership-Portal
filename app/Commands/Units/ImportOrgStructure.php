<?php

namespace App\Commands\Units;

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavPositionGroup;
use App\Models\AdminBillet;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AdminBilletRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Build the chain of command and the billet → section mapping from the Cav position groups
 * (story 2.9, AD-18). Admin-only — enforced by the controller (AD-6), same as {@see CreateUnit}.
 *
 * Every line-company group (`A/1-7`) yields its Battalion and Company; every billet title in it
 * yields the Platoon / Section it names (see {@see CavDesignation}), and every section-level
 * billet is mapped to its section by `cav_position_id`. Non-line groups (`Regimental HQ`,
 * `1-7 Command`, `Retired`, …) create no line node.
 *
 * **Staff and holding groups (2026-10-24).** Every billet outside the line that places its holder gets a billet mapping
 * too: a non-command `Regimental HQ` billet → the regiment, a non-command `<bn> Command` billet → that battalion, a
 * non-command platoon or company billet → that node, and a staff, ELOA, Reservist or New Recruit billet → its group
 * ({@see StaffBillet}). Those groups (`UnitType::Group`) are created on first sight, a department or holding group
 * under its default portfolio (portfolios nest), the rest under the regiment, and found again by callsign on every
 * later run, never moved, so an Admin's move of a department to another portfolio stands. Re-running the import never
 * duplicates a group. Each portfolio's `lead_position_id` (its officer's billet) is kept current on every run.
 *
 * Every leadership billet — in a line company, a `<bn> Command` group or Regimental HQ — is also
 * mapped to the unit it commands (story 1.16, {@see CommandBillet}), the input to the
 * roster-derived half of `ScopeResolver`. A command billet whose unit the import has no node for
 * (a battalion command group with no line company) is not mapped — commands never create nodes —
 * and is logged as unplaced.
 *
 * **Additive and idempotent.** An existing node is reused when its type + callsign match the
 * designation (the regiment root also falls back to any existing root regiment, so the tree
 * keeps a single root), and is never renamed, moved or deleted. An existing billet placement the import wrote
 * (and nobody has changed since) is repointed when Cav now lists the billet elsewhere, so a reorg reaches the roster
 * sync; one corrected by hand, or never confirmed by the import, is kept and counted in the result instead, so the
 * import never overrides a deliberate correction (2026-10-29). A re-run with unchanged input
 * therefore creates no unit or mapping — but still records an audit row, so the log shows the
 * import ran.
 *
 * **Command mappings are the exception: reconciled, not additive.** They are pure derived data
 * with no hand curation to protect, and a stale one keeps granting scope (AD-8 deny-by-default),
 * so every run makes `unit_command_mappings` exactly what Cav publishes — new billets added,
 * moved ones repointed, billets no longer listed as a command removed. Still idempotent.
 *
 * **Admin billets.** Every billet in a group `portal.admin_billet_groups` names
 * (`Regimental HQ`, `S6 HQ`) gets an `admin_billets` row, `pending` until an Admin approves it, and every billet that
 * has one is checked against the title and group Cav lists it under now, wherever that is: renamed or moved, it goes
 * back to pending and its holder stops being an Admin until an Admin confirms the new names. Moved out of those groups,
 * it can no longer be approved. The import never approves one.
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
        private readonly AdminBilletRepository $adminBillets,
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

        [$designations, $sectionByPositionId, $commandByPositionId, $skippedTitles, $ignoredGroups, $groupNodes, $leadPositions] = $this->plan($groups);
        [$adminGroupBillets, $otherBillets] = $this->billetNames($groups);

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
        $sectionCounts = [0, 0, 0];
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
                mutation: function () use ($designations, $groupNodes, $leadPositions, $sectionByPositionId, $commandByPositionId, $adminGroupBillets, $otherBillets, &$unitsCreated, &$sectionCounts, &$commandCounts): Unit {
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

                    // The groups off the line (2026-10-24), each after its portfolio. Found again by callsign, never
                    // moved: an Admin's move of a department to another portfolio stands.
                    foreach ($groupNodes as $key => $group) {
                        if (isset($index[$key])) {
                            continue;
                        }

                        $parent = $index[$group->parentKey()];
                        $index[$key] = $this->units->create(UnitType::Group, $group->name, $group->callsign, $parent->id, $group->kind);
                        $unitsCreated++;
                    }

                    // Each portfolio's lead billet, derived data kept current on every run (2026-10-25).
                    foreach ($leadPositions as $key => $positionId) {
                        if (isset($index[$key]) && $index[$key]->lead_position_id !== $positionId) {
                            $this->units->setLeadPosition($index[$key], $positionId);
                        }
                    }

                    $sectionCounts = $this->reconcilePlacements($sectionByPositionId, $index);

                    $commandCounts = $this->reconcileCommands($commandByPositionId, $index);

                    // A billet in an Admin billet group gets a pending row if it has none; any billet with a row is checked against
                    // the names it carries now. Nothing is approved here.
                    $this->adminBillets->observe($adminGroupBillets, createMissing: true);
                    $this->adminBillets->observe($otherBillets, createMissing: false);

                    return $root;
                },
            );
        } finally {
            $lock->release();
        }

        [$commandsCreated, $commandsRepointed, $commandsRemoved, $unplacedCommands] = $commandCounts;

        if ($sectionCounts[2] > 0) {
            Log::info('Org-structure import: billet placements repointed to where Cav now lists them.', [
                'actor_id' => $actor->id,
                'repointed' => $sectionCounts[2],
            ]);
        }

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
            $commandsCreated, $commandsRepointed, $commandsRemoved, $sectionCounts[2],
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
     * Bring the billet placements in line with Cav, without undoing a correction made by hand. For each planned
     * `position id => unit key`:
     *
     * - no row yet: create it, owned by the import;
     * - a row the import owns (it still points where the import last put it) that Cav now places elsewhere: repoint it,
     *   so a reorg reaches the roster sync instead of the sync putting the holder back in the old unit every hour;
     * - a row the import does not own yet (stored before ownership was recorded) that already points where Cav says:
     *   adopt it, so the next reorg can repoint it;
     * - any other row that disagrees with Cav (corrected by hand, or never confirmed): keep it and count it.
     *
     * A billet Cav no longer lists is left as it is. Returns `[created, differing, repointed]`.
     *
     * @param  array<int, string>  $unitKeyByPositionId
     * @param  array<string, Unit>  $index
     * @return array{0: int, 1: int, 2: int}
     */
    private function reconcilePlacements(array $unitKeyByPositionId, array $index): array
    {
        $existing = $this->mappings->forImportByPositionId();
        $created = 0;
        $differing = 0;
        $repointed = 0;

        foreach ($unitKeyByPositionId as $positionId => $unitKey) {
            // An HQ billet of a `<bn> Command` group with no line company has no node to place into, as its commands.
            $unit = $index[$unitKey] ?? null;

            if ($unit === null) {
                continue;
            }

            $row = $existing->get($positionId);

            if ($row === null) {
                $this->mappings->create($unit->id, $positionId);
                $created++;
            } elseif ($row['unitId'] === $unit->id) {
                if ($row['importedUnitId'] !== $unit->id) {
                    $this->mappings->placeFromImport($positionId, $unit->id);
                }
            } elseif ($row['importedUnitId'] === $row['unitId']) {
                $this->mappings->placeFromImport($positionId, $unit->id);
                $repointed++;
            } else {
                $differing++;
            }
        }

        return [$created, $differing, $repointed];
    }

    /**
     * Turn the groups into the ordered set of nodes to ensure and the billets to map — pure, no
     * DB access, so everything the import will write is decided before the transaction opens;
     * an unrecognised title is skipped and reported, never fatal. Returns the designations to
     * ensure (keyed, each after its parent), the section key per mapped position id, the
     * commanded unit's key per leadership position id, the titles skipped as unparseable, and the
     * titles of groups ignored as not line companies.
     *
     * Since 2026-10-24 the placement map (the second element) holds every billet that places its holder, not only
     * sections: a non-command HQ billet → its company, platoon, battalion or the regiment, and a holding or staff
     * billet → its group ({@see StaffBillet}). The groups to ensure come next, each after its portfolio, and last each
     * portfolio's lead billet by group key.
     *
     * @param  list<CavPositionGroup>  $groups  in Cav display order
     * @return array{0: array<string, CavDesignation>, 1: array<int, string>, 2: array<int, string>, 3: list<string>, 4: list<string>, 5: array<string, GroupDesignation>, 6: array<string, int>}
     */
    private function plan(array $groups): array
    {
        $designations = [];
        $sectionByPositionId = [];
        $commandByPositionId = [];
        $skippedTitles = [];
        $ignoredGroups = [];
        $groupNodes = [];
        $leadPositions = [];

        foreach ($groups as $group) {
            $company = CavDesignation::companyGroup($group->title);

            if ($company === null) {
                $ignoredGroups[] = $group->title;
                $headquarters = CommandBillet::headquartersOf($group->title);

                foreach ($group->positions as $position) {
                    if (CommandBillet::devcomCommand($position->title) !== null) {
                        // DEVCOM's command team (#377): placed by its command mapping, as a battalion's is, never in
                        // the DEVCOM staff group its title would otherwise name; the 1IC's billet names the lead.
                        $this->addDevcomCommand($commandByPositionId, $leadPositions, $position->id, $position->title);

                        continue;
                    }

                    $commanded = CommandBillet::inCommandGroup($group->title, $position->title);
                    $portfolio = StaffBillet::portfolioFor($position->title);

                    if ($portfolio !== null) {
                        // The portfolio's officer leads it. One who commands nothing above (SecOps, Recruiting
                        // Oversight, IMO, RDC Commander) commands the portfolio; the XO keeps commanding the regiment.
                        $this->addGroup($groupNodes, $portfolio);
                        $leadPositions[$portfolio->key()] ??= $position->id;
                    }

                    if ($commanded !== null) {
                        $commandByPositionId[$position->id] ??= $commanded->key();
                    } elseif ($portfolio !== null) {
                        $commandByPositionId[$position->id] ??= $portfolio->key();
                    }

                    $placedIn = StaffBillet::groupFor($position->title, $group->title);

                    if ($placedIn !== null) {
                        $this->addGroup($groupNodes, $placedIn);
                        $sectionByPositionId[$position->id] ??= $placedIn->key();
                    } elseif ($headquarters !== null && ($commanded === null || $portfolio !== null)) {
                        $sectionByPositionId[$position->id] ??= $headquarters->key();
                    } elseif ($portfolio !== null && $commanded === null) {
                        // A portfolio officer outside Regimental HQ (the RDC Commander) sits on their portfolio.
                        $sectionByPositionId[$position->id] ??= $portfolio->key();
                    }
                }

                continue;
            }

            $this->addWithAncestors($designations, $company);

            foreach ($group->positions as $position) {
                if (CommandBillet::devcomCommand($position->title) !== null) {
                    // Listed in a line group (D/DEVCOM) it still commands the DEVCOM battalion, not the company (#377).
                    $this->addDevcomCommand($commandByPositionId, $leadPositions, $position->id, $position->title);

                    continue;
                }

                $designation = CavDesignation::fromPositionTitle($position->title, $company);

                if ($designation === null) {
                    $skippedTitles[] = $position->title;

                    continue;
                }

                $this->addWithAncestors($designations, $designation);

                $commands = CommandBillet::inLineGroup($position->title, $designation) !== null;

                // First listing wins should Cav ever list one position id twice — the mapping table holds one row per
                // billet. A section billet places its holder in the section; a non-command billet at a platoon or
                // company (2026-10-24) on that HQ node; a command billet above a section by its command mapping.
                if ($designation->type === UnitType::Section || ! $commands) {
                    $sectionByPositionId[$position->id] ??= $designation->key();
                }

                if ($commands) {
                    $commandByPositionId[$position->id] ??= $designation->key();
                }
            }
        }

        return [$designations, $sectionByPositionId, $commandByPositionId, $skippedTitles, $ignoredGroups, $groupNodes, $leadPositions];
    }

    /**
     * The title and group title of every billet Cav lists, `position id => [title, group title]`, split into those in a
     * group whose billets may make an Admin ({@see AdminBillet::isAdminGroup()}) and all the others. First listing
     * wins, as in {@see plan()}. Pure, no DB access.
     *
     * @param  list<CavPositionGroup>  $groups
     * @return array{0: array<int, array{0: string, 1: string}>, 1: array<int, array{0: string, 1: string}>}
     */
    private function billetNames(array $groups): array
    {
        $eligible = [];
        $other = [];

        foreach ($groups as $group) {
            $isAdminGroup = AdminBillet::isAdminGroup($group->title);

            foreach ($group->positions as $position) {
                if (isset($eligible[$position->id]) || isset($other[$position->id])) {
                    continue;
                }

                if ($isAdminGroup) {
                    $eligible[$position->id] = [$position->title, $group->title];
                } else {
                    $other[$position->id] = [$position->title, $group->title];
                }
            }
        }

        return [$eligible, $other];
    }

    /**
     * Map a DEVCOM command billet to the DEVCOM battalion, and the 1IC's billet as the battalion's named lead, so its
     * holder is shown as the lead (or the lead as vacant) and never the 2IC (#377). Commands never create nodes: with no
     * DEVCOM line company in the groups there is no battalion, and the billet is logged as unplaced.
     *
     * @param  array<int, string>  $commandByPositionId
     * @param  array<string, int>  $leadPositions
     */
    private function addDevcomCommand(array &$commandByPositionId, array &$leadPositions, int $positionId, string $title): void
    {
        $battalion = CommandBillet::devcomCommand($title);
        $commandByPositionId[$positionId] ??= $battalion->key();

        if (trim($title) === CommandBillet::DEVCOM_LEAD_TITLE) {
            $leadPositions[$battalion->key()] ??= $positionId;
        }
    }

    /**
     * @param  array<string, GroupDesignation>  $groupNodes
     */
    private function addGroup(array &$groupNodes, GroupDesignation $group): void
    {
        if (isset($groupNodes[$group->key()])) {
            return;
        }

        if ($group->parentCallsign !== null) {
            $this->addGroup($groupNodes, StaffBillet::portfolio($group->parentCallsign));
        }

        $groupNodes[$group->key()] = $group;
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
