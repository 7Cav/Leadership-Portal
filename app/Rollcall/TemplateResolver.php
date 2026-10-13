<?php

namespace App\Rollcall;

use App\Models\ArtifactType;
use App\Models\Unit;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;

/**
 * Which template version applies to a unit (Story 3.6, AD-17). A section, platoon or any echelon below a company has no
 * template of its own, so the rule is fixed: walk up the org tree to the owning company and use that company's active
 * version. Every unit under one company therefore resolves to the same version, and a unit under another company never
 * borrows it.
 *
 * Read-only: it reads the org tree's materialized path (AD-18) and the active-version pointer through their
 * Repositories, and writes nothing. Resolution happens on every call, never from a cached copy, so an Admin's
 * activation shows on the next render (3.11).
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

        $version = $this->versions->activeFor($company->id, $type);

        return $version === null
            ? ResolvedTemplate::noActiveTemplate($company->id)
            : ResolvedTemplate::resolved($company->id, $version);
    }
}
