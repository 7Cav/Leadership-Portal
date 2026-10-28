<?php

namespace App\Commands\Templates;

use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateNameTakenException;
use App\Repositories\TemplateVersionRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;

/**
 * A company's or a section's command adds a named template to that unit (#375, AD-17), with its first version. A
 * company's template starts switched on, and becomes the company's active template when it has none, so a company's
 * first template is used as soon as it is saved. A section's template is only for that section: its leader picks it on
 * the rollcall like any other, and it moves with the section. Only whoever commands the unit may
 * ({@see TemplateAuthority}); the regiment default is the one template no one creates. Audited with a reason always
 * required (AD-16), as an edit is.
 */
class CreateTemplate
{
    public const MAX_NAME_LENGTH = 60;

    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly TemplateAuthority $authority,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @param  Unit  $owner  the company or section the template is for
     *
     * @throws TemplateForbiddenException when the actor does not command the unit.
     * @throws InvalidTemplateException when the unit is not a company or a section, or the name or body is not acceptable.
     */
    public function handle(User $actor, Unit $owner, ArtifactType $type, string $name, string $body, ?string $reason): Template
    {
        // Authority first, so someone who commands nothing here cannot tell a company or section from any other unit.
        if (! $this->authority->commands($actor, $owner)) {
            throw $owner->type === UnitType::Section ? TemplateForbiddenException::sectionTemplates() : TemplateForbiddenException::company();
        }

        if (! $this->authority->managesUnit($actor, $owner)) {
            throw InvalidTemplateException::notAnOwner($owner);
        }

        $whose = $owner->type === UnitType::Section ? 'section' : 'company';

        $name = trim($name);

        if ($name === '') {
            throw InvalidTemplateException::emptyName();
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw InvalidTemplateException::nameTooLong(self::MAX_NAME_LENGTH);
        }

        // Two templates of one unit with the same name could not be told apart in the section leader's list.
        $taken = $this->versions->templatesOf($owner->id, $type)
            ->contains(fn (Template $template) => mb_strtolower($template->name) === mb_strtolower($name));

        if ($taken) {
            throw InvalidTemplateException::duplicateName($name, $whose);
        }

        AuthorTemplateVersion::checkBody($body);

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'template.created',
                reason: $reason,
                reasonRequired: true,
                mutation: fn () => $this->versions->createTemplate($owner->id, $type, $name, $body, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id)),
            );
        } catch (TemplateNameTakenException) {
            // A twin submit that raced past the check above: the repository refused it under its lock.
            throw InvalidTemplateException::duplicateName($name, $whose);
        }
    }
}
