<?php

namespace App\Commands\Templates;

use App\Authorization\ScopeResolver;
use App\Models\Template;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitRepository;

/**
 * Who may manage rollcall templates (#375, AD-8, AD-17), the one rule every template Command and the Templates page
 * read:
 *
 *  - a company's templates: whoever commands the company ({@see ScopeResolver::commandsUnit()}), that is its CO, XO
 *    and 1SG, the battalion and regiment command above it, an acting lead of the company, and Admins;
 *  - a section's own templates: whoever commands the section, the same way: its SL and ASL, the platoon, company,
 *    battalion and regiment command above it, an acting lead of the section or above, and Admins;
 *  - the regiment-wide default: Admins only.
 *
 * Never another unit's command, and never a staff grant, which only reads. Real authority, not a view-as preview:
 * writes never follow a preview (AD-9), and the middleware refuses them anyway.
 */
class TemplateAuthority
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
    ) {}

    /**
     * Whether the user may create, edit and activate this unit's own templates: a company's (and switch them and pick
     * its default) or a section's.
     */
    public function managesUnit(User $user, Unit $unit): bool
    {
        return in_array($unit->type, ScopeResolver::TEMPLATE_OWNERS, true) && $this->commands($user, $unit);
    }

    /** Whether the user commands this unit at all, before asking whether it is a company or a section. */
    public function commands(User $user, Unit $unit): bool
    {
        return $this->scope->commandsUnit($user, $unit);
    }

    /** Whether the user may edit and activate versions of this template. */
    public function manages(User $user, Template $template): bool
    {
        if ($template->isRegimentDefault()) {
            return $this->scope->isAdmin($user);
        }

        $owner = $this->units->find($template->ownerId());

        return $owner !== null && $this->managesUnit($user, $owner);
    }

    /** The refusal for a template the user may not manage. */
    public function refusal(Template $template): TemplateForbiddenException
    {
        return match (true) {
            $template->isRegimentDefault() => TemplateForbiddenException::regimentDefault(),
            $template->isSectionTemplate() => TemplateForbiddenException::sectionTemplates(),
            default => TemplateForbiddenException::company(),
        };
    }
}
