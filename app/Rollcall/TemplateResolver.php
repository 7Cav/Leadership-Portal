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
 * Which template applies to a unit (Story 3.6, #375, AD-17). A section, platoon or any echelon below a company has no
 * template of its own, so the rule walks up the org tree to the owning company and takes, in order:
 *
 *  1. the section's own pick, while it is still available to it ({@see isAvailable()}): the regiment default or one
 *     of the company's switched-on templates (a platoon moved here from another company picks the copies the move
 *     made for this company, #378);
 *  2. else the company's active template, if the company has it switched on;
 *  3. else the regiment-wide default, so a company with nothing switched on still gets a post, never a blank one.
 *
 * A unit under one company never borrows another company's template: a pick or choice naming one is skipped.
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
     * The templates a section leader may pick from: the regiment default first, then the company's templates that its
     * command has switched on, oldest first. Empty for a unit under no company.
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
            ->merge($this->versions->templatesOf($company->id, $type)->filter(fn (Template $template) => $this->isAvailable($template, $section, $company)))
            ->filter(fn (Template $template) => $template->activeVersion !== null)
            ->values();
    }

    /**
     * What the rollcall's template picker shows for a section: the templates it may pick from (the regiment default
     * first), and the one it picked, or null when it renders from the company's choice. A pick that is no longer
     * available reads as none, as the resolver then ignores it. `moved` is what a section moved here from another
     * company is told when its copied template is switched off ({@see movedNotice()}).
     *
     * @return array{options: list<array{id: int, name: string, isRegimentDefault: bool, version: int}>, picked: ?int, moved: ?array{from: ?string, copy: string, locked: bool, using: ?string}}
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
                'version' => $template->activeVersion->version,
            ])->all(),
            'picked' => $options->contains('id', $picked) ? $picked : null,
            'moved' => $this->movedNotice($section, $choice?->template, $type),
        ];
    }

    /**
     * What a section is told when it moved here from another company and the copy of its template made then is
     * switched off (#378): a move into a company whose battalion sets its templates (#376) makes the copies switched
     * off, so the section follows its new company. Says where the template came from, the copy's name, whether the
     * battalion holds the company's templates, and the template its post uses now. Null otherwise: a switched-on copy
     * is just one of the company's templates.
     *
     * @return array{from: ?string, copy: string, locked: bool, using: ?string}|null
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
            'locked' => ! $this->versions->companyEditsTemplates($company->id),
            'using' => $this->forUnit($section, $type)->template?->name,
        ];
    }

    /**
     * Whether a unit under `$company` may render from `$template`: the regiment default always, otherwise one of the
     * company's own templates while it is switched on. A platoon moved here from another company renders from copies
     * the move made for this company (#378), never from its old company's templates.
     */
    public function isAvailable(Template $template, Unit $unit, Unit $company): bool
    {
        return $template->isRegimentDefault() || ($template->company_id === $company->id && $template->enabled);
    }
}
