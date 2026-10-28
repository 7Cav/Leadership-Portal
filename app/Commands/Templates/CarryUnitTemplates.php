<?php

namespace App\Commands\Templates;

use App\Commands\Units\MoveUnit;
use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\FireteamRepository;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateResolver;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;

/**
 * A platoon or section moved to another company keeps its settings and templates (#378, AD-16, AD-17). Called by
 * {@see MoveUnit} inside its audited move:
 *
 *  - {@see usedBy()} before the move: the company templates its sections render from now (a pick, or the company's
 *    default), so they are known while the unit still sits under the old company;
 *  - {@see afterMove()} after it: those templates are carried along (`template_carries`), so its sections may go on
 *    using them in the new company and their picks stay valid. With the new company's own template editing switched off
 *    by its battalion (#376) they are kept but not used, and the rollcall tells the sections which template they now
 *    use ({@see TemplateResolver::pickerFor()}).
 *
 * Fireteam settings, labels, acting leads and the sections' picks are keyed to the units themselves, so they move with
 * them unchanged. One audited batch (`unit.settings_carried`) records what came along: the carried templates, and the
 * fireteam settings, acting leads and picks of the moved units, so the log shows what the platoon kept.
 */
class CarryUnitTemplates
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TemplateVersionRepository $versions,
        private readonly TemplateResolver $resolver,
        private readonly FireteamRepository $fireteams,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * The unit's company before it moves, and the company templates its sections render from.
     *
     * @return array{0: ?Unit, 1: Collection<int, Template>}
     */
    public function usedBy(Unit $unit): array
    {
        if (! in_array($unit->type, [UnitType::Platoon, UnitType::Section], true)) {
            return [null, collect()];
        }

        $company = $this->units->owningCompany($unit);

        $templates = $this->sectionsOf($unit)
            ->map(fn (Unit $section) => $this->resolver->forUnit($section)->template)
            ->filter(fn (?Template $template) => $template !== null && ! $template->isRegimentDefault())
            ->unique('id')
            ->values();

        return [$company, $templates];
    }

    /**
     * Carry the templates along when the unit landed under another company, and record what it kept. Nothing happens
     * for a move within one company, or for a unit that was or is now under none.
     *
     * @param  Collection<int, Template>  $templates  from {@see usedBy()}
     */
    public function afterMove(User $actor, Unit $unit, ?Unit $from, Collection $templates): void
    {
        $to = $this->units->owningCompany($unit);

        if ($from === null || $to === null || $from->id === $to->id) {
            return;
        }

        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'unit.settings_carried',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $unit, $from, $templates) {
                $carried = $this->versions->carry($unit, $templates, $from->id, $actor->id);
                $units = $this->units->subtree($unit);

                $kept = $units->flatMap(fn (Unit $member) => array_filter([
                    $this->fireteams->settingFor($member->id),
                    $this->actingLeads->forUnit($member->id),
                    $this->versions->choiceFor($member->id, ArtifactType::RollcallBbcode),
                ]))->all();

                return new AuditedBatch($unit, ...$carried, ...$kept);
            },
        );
    }

    /**
     * @return Collection<int, Unit>
     */
    private function sectionsOf(Unit $unit): Collection
    {
        return $unit->type === UnitType::Section ? collect([$unit]) : $this->units->sectionsAmong($this->units->subtreeIds($unit));
    }
}
