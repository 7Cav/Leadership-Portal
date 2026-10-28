<?php

namespace App\Commands\Templates;

use App\Authorization\ScopeResolver;
use App\Models\Template;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

/**
 * Who may manage rollcall templates (#375, AD-8, AD-17), the one rule every template Command and the Templates page
 * read:
 *
 *  - a company's templates: whoever commands the company ({@see ScopeResolver::commandsUnit()}), that is its CO, XO
 *    and 1SG, the battalion and regiment command above it, an acting lead of the company, and Admins. Never another
 *    company's command, and never a staff grant, which only reads;
 *  - the regiment-wide default: Admins only.
 *
 * Real authority, not a view-as preview: writes never follow a preview (AD-9), and the middleware refuses them anyway.
 */
class TemplateAuthority
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
    ) {}

    /** Whether the user may create, edit, activate and switch this company's templates. */
    public function managesCompany(User $user, Unit $company): bool
    {
        return $company->type === UnitType::Company && $this->commands($user, $company);
    }

    /** Whether the user commands this unit at all, before asking whether it is a company. */
    public function commands(User $user, Unit $unit): bool
    {
        return $this->scope->commandsUnit($user, $unit);
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
}
