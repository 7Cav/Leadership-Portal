<?php

namespace App\Commands\Units;

use App\Models\AdminBillet;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\CavBilletRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Place a billet in a unit from Org structure (2026-11-13): a new billet nothing placed, a billet added to a unit's
 * Billets list, or a placed billet moved to another unit. Its holders land there on the next roster sync. The placement
 * is explicit and beats the rules: the import keeps it ({@see ImportOrgStructure}) and the sync places by it before any
 * command mapping. Moved back to where the import put it, it is the import's again. Assigning also confirms the name the
 * billet carries now.
 *
 * One name, one explicit unit: when an Admin already placed this billet, or another billet of the same name, in a
 * different unit, the assignment is refused ({@see InvalidBilletChangeException::placedElsewhere()}) until the Admin
 * confirms the move, which then moves every one of them to the new unit.
 *
 * A billet whose title names a unit level ({@see CavDesignation::levelOfTitle()}: a section for `Trooper 1/1/A/1-7`, a
 * platoon for `Platoon Leader 1/A/1-7`, a company for `Commander A/1-7`, a battalion for `1-7 Commanding Officer`)
 * placed in a unit of another level, higher or lower, the regiment or a group included, is refused the same way
 * ({@see InvalidBilletChangeException::otherLevel()}) until the Admin confirms it. A billet whose title names no level (a
 * start-up's, a regimental staff billet) goes anywhere without asking.
 *
 * Admin-only, enforced by the controller (AD-6), as {@see CreateUnit}. It changes where troopers are placed, so it is
 * audited with a required reason (AD-16) as `billet.assigned`, one entry for the billet and any moved with it.
 */
class AssignBillet
{
    public const ACTION = 'billet.assigned';

    public function __construct(
        private readonly CavBilletRepository $billets,
        private readonly UnitBilletMappingRepository $mappings,
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidBilletChangeException when the billet or unit is unknown, the billet is there already, or it (or one
     *                                      of its name) is placed elsewhere by an Admin and the move is not confirmed, or
     *                                      its title names another unit level than the unit's and that is not confirmed
     */
    public function handle(User $actor, int $positionId, int $unitId, string $reason, bool $confirmMove = false, bool $confirmLevel = false): AuditedBatch
    {
        $unit = $this->units->find($unitId) ?? throw InvalidBilletChangeException::noSuchUnit();

        return $this->audit->recordBatch(
            actorId: $actor->id,
            action: self::ACTION,
            reason: $reason,
            reasonRequired: true,
            mutation: function () use ($positionId, $unit, $confirmMove, $confirmLevel): AuditedBatch {
                // Read again under a lock: the import or a sync may have changed it since the page loaded.
                $billet = $this->billets->lockForPosition($positionId) ?? throw InvalidBilletChangeException::unknown();
                $placements = $this->mappings->lockAll();
                $placedIn = $placements->get($positionId)['unitId'] ?? null;

                if ($placedIn === $unit->id) {
                    throw InvalidBilletChangeException::alreadyThere($unit->callsign ?? $unit->name);
                }

                $level = CavDesignation::levelOfTitle($billet->title);

                if (! $confirmLevel && $level !== null && $level !== $unit->type) {
                    throw InvalidBilletChangeException::otherLevel($billet->title, $level, $unit->callsign ?? $unit->name, $this->describe($unit));
                }

                // Every explicit placement of this name in another unit, this billet's own included.
                $name = mb_strtolower((string) AdminBillet::name($billet->title));
                $sameName = $this->billets->all()
                    ->filter(fn ($row) => mb_strtolower((string) AdminBillet::name($row->title)) === $name)
                    ->keys();
                $elsewhere = $placements
                    ->only($sameName->all())
                    ->filter(fn (array $row) => $row['importedUnitId'] !== $row['unitId'] && $row['unitId'] !== $unit->id);

                if ($elsewhere->isNotEmpty() && ! $confirmMove) {
                    $from = $elsewhere->map(fn (array $row) => $this->units->find($row['unitId']))->filter()
                        ->map(fn ($found) => $found->callsign ?? $found->name)->unique()->values()->all();

                    throw InvalidBilletChangeException::placedElsewhere($billet->title, $from);
                }

                $mapping = $this->mappings->assign($positionId, $unit->id)->setRelation('billet', $billet);
                $this->billets->confirmName($billet);

                $moved = $elsewhere->keys()
                    ->reject(fn (int $other) => $other === $positionId)
                    ->map(fn (int $other) => $this->mappings->assign($other, $unit->id))
                    ->values()->all();

                return new AuditedBatch($mapping, ...$moved);
            },
        );
    }

    /** What `$unit` is, such as `a company`, for the confirm message. */
    private function describe(Unit $unit): string
    {
        return match ($unit->type) {
            UnitType::Section => 'a section',
            UnitType::Platoon => 'a platoon',
            UnitType::Company => 'a company',
            UnitType::Battalion => 'a battalion',
            UnitType::Regiment => 'the regiment',
            UnitType::Group => $unit->kind === UnitKind::Staff ? 'a staff group' : 'a holding group',
        };
    }
}
