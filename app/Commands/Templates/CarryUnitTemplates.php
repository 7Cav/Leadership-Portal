<?php

namespace App\Commands\Templates;

use App\Commands\Units\MoveUnit;
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
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;

/**
 * A platoon or section moved to another company keeps its settings, and what its rollcall posts render (#378, AD-16,
 * AD-17). Called by {@see MoveUnit} inside its audited move:
 *
 *  - {@see usedBy()} before the move: the company template each of its sections renders from now (a pick, or the
 *    company's default), read while the unit still sits under the old company;
 *  - {@see afterMove()} after it: each of those templates is copied into the new company as a template of its own,
 *    switched on, named after the old one and the company it came from, holding the version that was active, and each
 *    section's pick moves to its copy. The new company owns the copies, so its command manages them like any of its
 *    templates, and the old company's later edits or switches never reach the moved sections.
 *
 * A section's own templates (and its pick of one) are keyed to the section, so they move with it and are neither
 * copied nor re-picked. Fireteam settings, labels and acting leads are keyed to the units themselves too, so they move
 * with them unchanged. One
 * audited batch (`unit.settings_carried`) records what came along: the copies and their versions, the sections' new
 * picks, and the fireteam settings and acting leads of the moved units, so the log shows what the platoon kept.
 */
class CarryUnitTemplates
{
    /** A template name's limit (`templates.name`). */
    private const NAME_LIMIT = 60;

    /** Room kept for the repository's ` (n)` when the copy's name is taken already. */
    private const NUMBER_ROOM = 5;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TemplateVersionRepository $versions,
        private readonly TemplateResolver $resolver,
        private readonly FireteamRepository $fireteams,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * The unit's company before it moves, and the company template each of its sections renders from, keyed by section
     * id. A section on the regiment default is left out: that one is everyone's. So is a section on a template of its
     * own: that one moves with it.
     *
     * @return array{0: ?Unit, 1: array<int, Template>}
     */
    public function usedBy(Unit $unit): array
    {
        if (! in_array($unit->type, [UnitType::Platoon, UnitType::Section], true)) {
            return [null, []];
        }

        $company = $this->units->owningCompany($unit);

        $used = [];

        foreach ($this->sectionsOf($unit) as $section) {
            $template = $this->resolver->forUnit($section)->template;

            if ($template !== null && $template->company_id !== null) {
                $used[$section->id] = $template;
            }
        }

        return [$company, $used];
    }

    /**
     * Copy the templates into the new company and move the sections' picks to the copies when the unit landed under
     * another company, and record what it kept. Nothing happens for a move within one company, or for a unit that was
     * or is now under none.
     *
     * @param  array<int, Template>  $used  from {@see usedBy()}
     */
    public function afterMove(User $actor, Unit $unit, ?Unit $from, array $used): void
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
            mutation: function () use ($actor, $unit, $from, $to, $used) {
                $copies = [];
                $picks = [];
                $who = [$actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id)];

                foreach ($used as $sectionId => $template) {
                    $copies[$template->id] ??= $this->versions->copyTemplate($template, $to->id, $this->copyName($template, $from), true, ...$who);

                    $section = $this->units->find($sectionId);

                    if ($section !== null) {
                        $picks[] = $this->versions->choose($section, $copies[$template->id], ...$who);
                    }
                }

                $kept = $this->units->subtree($unit)->flatMap(fn (Unit $member) => array_filter([
                    $this->fireteams->settingFor($member->id),
                    $this->actingLeads->forUnit($member->id),
                ]))->all();

                $copied = collect($copies)->flatMap(fn (Template $copy) => [$copy, $copy->activeVersion])->all();

                return new AuditedBatch($unit, ...$copied, ...$picks, ...$kept);
            },
        );
    }

    /** `Training (from A/1-7)`, the old name shortened when it would not fit. */
    private function copyName(Template $template, Unit $from): string
    {
        // A callsign is short; a company with only a long name gives at most half the name's room.
        $suffix = ' (from '.mb_substr($from->callsign ?? $from->name, 0, intdiv(self::NAME_LIMIT, 2)).')';
        $room = max(1, self::NAME_LIMIT - self::NUMBER_ROOM - mb_strlen($suffix));

        return rtrim(mb_substr($template->name, 0, $room)).$suffix;
    }

    /**
     * @return Collection<int, Unit>
     */
    private function sectionsOf(Unit $unit): Collection
    {
        return $unit->type === UnitType::Section ? collect([$unit]) : $this->units->sectionsAmong($this->units->subtreeIds($unit));
    }
}
