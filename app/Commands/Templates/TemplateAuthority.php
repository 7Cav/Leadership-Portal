<?php

namespace App\Commands\Templates;

use App\Authorization\ScopeResolver;
use App\Models\Template;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;

/**
 * Who may manage rollcall templates (#375, #376, AD-8, AD-17), the one rule every template Command and the Templates
 * page read:
 *
 *  - a company's templates: whoever commands the company ({@see ScopeResolver::commandsUnit()}), that is its CO, XO
 *    and 1SG, the battalion and regiment command above it, an acting lead of the company, and Admins. Never another
 *    company's command, and never a staff grant, which only reads;
 *  - unless the battalion command switched the company's own template editing off (#376): then only the command above
 *    the company (battalion, regiment) and Admins manage them, exactly as the company could before;
 *  - that switch: the command above the company and Admins;
 *  - the regiment-wide default: Admins only.
 *
 * Real authority, not a view-as preview: writes never follow a preview (AD-9), and the middleware refuses them anyway.
 */
class TemplateAuthority
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TemplateVersionRepository $versions,
    ) {}

    /** Whether the user may create, edit, activate and switch this company's templates. */
    public function managesCompany(User $user, Unit $company): bool
    {
        if ($company->type !== UnitType::Company || ! $this->commands($user, $company)) {
            return false;
        }

        return $this->versions->companyEditsTemplates($company->id) || $this->commandsAbove($user, $company);
    }

    /** Whether the user commands this unit at all, before asking whether it is a company. */
    public function commands(User $user, Unit $unit): bool
    {
        return $this->scope->commandsUnit($user, $unit);
    }

    /**
     * Whether the user commands the unit above the company (its battalion, or the regiment for a company straight under
     * it), or is an Admin: who may switch the company's own template editing on or off (#376).
     */
    public function commandsAbove(User $user, Unit $company): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $parent = $company->parent_id === null ? null : $this->units->find($company->parent_id);

        return $parent !== null && $this->scope->commandsUnit($user, $parent);
    }

    /**
     * Whether the company commands its templates but its battalion switched that off, so the user is refused only for
     * that reason: what the Templates page and the refusal say.
     */
    public function lockedOut(User $user, Unit $company): bool
    {
        return $company->type === UnitType::Company
            && $this->commands($user, $company)
            && ! $this->managesCompany($user, $company);
    }

    /** Whether the user may edit and activate versions of this template. */
    public function manages(User $user, Template $template): bool
    {
        if ($template->isRegimentDefault()) {
            return (bool) $user->is_admin;
        }

        $company = $this->units->find($template->company_id);

        return $company !== null && $this->managesCompany($user, $company);
    }

    /** The refusal for a company template the user may not manage. */
    public function refusal(User $user, Template $template): TemplateForbiddenException
    {
        if ($template->isRegimentDefault()) {
            return TemplateForbiddenException::regimentDefault();
        }

        $company = $this->units->find($template->company_id);

        return $company !== null && $this->lockedOut($user, $company) ? TemplateForbiddenException::lockedByBattalion() : TemplateForbiddenException::company();
    }
}
