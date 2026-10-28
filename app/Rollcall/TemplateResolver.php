<?php

namespace App\Rollcall;

use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * Which template applies to a unit (Story 3.6, #375, AD-17). The rule walks up the org tree to the owning company and
 * takes, in order:
 *
 *  1. the section's pick, while it is still available to it ({@see isAvailable()}): one of the section's own
 *     templates, one of the company's switched-on templates (a platoon moved here from another company picks the
 *     copies the move made for this company, #378) or the regiment default;
 *  2. else the company's active template, if the company has it switched on;
 *  3. else the regiment-wide default, so a company with nothing switched on still gets a post, never a blank one.
 *
 * A section's own templates are used only when its leader picks one, never by another section. A unit under one
 * company never borrows another company's template: a pick or choice naming one is skipped.
 *
 * Read-only: it reads the org tree's materialized path (AD-18) and the template rows through their Repositories, and
 * writes nothing. Resolution happens on every call, never from a cached copy, so a company's change shows on the next
 * render (3.11).
 */
final class TemplateResolver
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TemplateVersionRepository $versions,
    ) {}

    public function forUnit(Unit $unit, ArtifactType $type = ArtifactType::RollcallBbcode): ResolvedTemplate
    {
        $company = $this->units->owningCompany($unit);

        if ($company === null) {
            return ResolvedTemplate::noOwningCompany();
        }

        if ($unit->type === UnitType::Section) {
            $picked = $this->versions->choiceFor($unit->id, $type)?->template;

            if ($picked !== null && $picked->activeVersion !== null && $this->isAvailable($picked, $unit, $company)) {
                return ResolvedTemplate::resolved($company->id, $picked, $picked->activeVersion, TemplateSource::Picked);
            }
        }

        $active = $this->versions->choiceFor($company->id, $type)?->template;

        if ($active !== null && $active->company_id === $company->id && $active->enabled && $active->activeVersion !== null) {
            return ResolvedTemplate::resolved($company->id, $active, $active->activeVersion, TemplateSource::Company);
        }

        $default = $this->versions->regimentDefault($type);

        return $default?->activeVersion === null
            ? ResolvedTemplate::noActiveTemplate($company->id)
            : ResolvedTemplate::resolved($company->id, $default, $default->activeVersion, TemplateSource::RegimentDefault);
    }

    /**
     * The templates a section leader may pick from: the regiment default first, then the section's own templates, then
     * the company's templates that its command has switched on, each oldest first. Empty for a unit under no company.
     *
     * @return Collection<int, Template>
     */
    public function optionsFor(Unit $section, ArtifactType $type = ArtifactType::RollcallBbcode): Collection
    {
        $company = $this->units->owningCompany($section);

        if ($company === null) {
            return collect();
        }

        return collect([$this->versions->regimentDefault($type)])
            ->filter()
            ->merge($this->versions->templatesOf($section->id, $type))
            ->merge($this->versions->templatesOf($company->id, $type))
            ->filter(fn (Template $template) => $this->isAvailable($template, $section, $company))
            ->filter(fn (Template $template) => $template->activeVersion !== null)
            ->values();
    }

    /**
     * What the rollcall's template picker shows for a section: the templates it may pick from (the regiment default
     * first), and the one it picked, or null when it renders from the company's choice. A pick that is no longer
     * available reads as none, as the resolver then ignores it. `moved` is what a section moved here from another
     * company is told when its copied template is switched off ({@see movedNotice()}).
     *
     * @return array{options: list<array{id: int, name: string, isRegimentDefault: bool, isSection: bool, version: int}>, picked: ?int, moved: ?array{from: ?string, copy: string, using: ?string}}
     */
    public function pickerFor(Unit $section, ArtifactType $type = ArtifactType::RollcallBbcode): array
    {
        $options = $this->optionsFor($section, $type);
        $choice = $this->versions->choiceFor($section->id, $type);
        $picked = $choice?->template_id;

        return [
            'options' => $options->map(fn (Template $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'isRegimentDefault' => $template->isRegimentDefault(),
                'isSection' => $template->isSectionTemplate(),
                'version' => $template->activeVersion->version,
            ])->all(),
            'picked' => $options->contains('id', $picked) ? $picked : null,
            'moved' => $this->movedNotice($section, $choice?->template, $type),
        ];
    }

    /**
     * What a section is told when it moved here from another company and the copy of its template made then has been
     * switched off since (#378): the section follows its new company. Says where the template came from, the copy's
     * name, and the template its post uses now. Null otherwise: a switched-on copy is just one of the company's
     * templates.
     *
     * @return array{from: ?string, copy: string, using: ?string}|null
     */
    private function movedNotice(Unit $section, ?Template $picked, ArtifactType $type): ?array
    {
        $company = $this->units->owningCompany($section);

        if ($company === null || $picked === null || $picked->copied_from_id === null || $picked->enabled || $picked->company_id !== $company->id) {
            return null;
        }

        $source = $this->versions->findTemplate($picked->copied_from_id);
        $from = $source?->company_id === null ? null : $this->units->find($source->company_id);

        return [
            'from' => $from?->callsign ?? $from?->name,
            'copy' => $picked->name,
            'using' => $this->forUnit($section, $type)->template?->name,
        ];
    }

    /**
     * Whether a unit under `$company` may render from `$template`: the regiment default always, a section's own
     * template only for that section, otherwise one of the company's own templates while it is switched on. A platoon
     * moved here from another company renders from copies the move made for this company (#378), never from its old
     * company's templates.
     */
    public function isAvailable(Template $template, Unit $unit, Unit $company): bool
    {
        return match (true) {
            $template->isRegimentDefault() => true,
            $template->isSectionTemplate() => $template->section_id === $unit->id,
            default => $template->company_id === $company->id && $template->enabled,
        };
    }
}
