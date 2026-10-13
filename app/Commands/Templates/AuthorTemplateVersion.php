<?php

namespace App\Commands\Templates;

use App\Commands\Units\CreateUnit;
use App\Models\ArtifactType;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Rollcall\TemplateSyntax;
use App\Support\Audit\AuditEnvelope;

/**
 * An Admin edits a company's template (Story 3.5, AD-17): the edit writes a new version, never changes an existing
 * one, and the active pointer advances to it. Admin-only, enforced by the controller (AD-6) as for
 * {@see CreateUnit}. The write goes through {@see AuditEnvelope} with a reason always required (AD-16), since a
 * template change reaches every section under the company.
 *
 * The body is stored as given, but only if it is a well-formed template (Story 3.10): the target is a company, the body
 * has some text and a bounded length, and its tags are all known and balanced, so a typo is refused here instead of
 * reaching every section's rollcall. Saving text identical to the active version is a no-op that is not logged, as for the other commands: work
 * that did not happen does not get a version number.
 */
class AuthorTemplateVersion
{
    /** The longest body an Admin may save. A BBCode post is far shorter; this stops a paste of the wrong thing. */
    public const MAX_BODY_LENGTH = 20000;

    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidTemplateException when the unit is not a company, or the body is empty, too long or not a
     *                                  well-formed template.
     */
    public function handle(User $actor, Unit $company, ArtifactType $type, string $body, ?string $reason): TemplateVersion
    {
        if ($company->type !== UnitType::Company) {
            throw InvalidTemplateException::notACompany($company);
        }

        if (trim($body) === '') {
            throw InvalidTemplateException::emptyBody();
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw InvalidTemplateException::bodyTooLong(self::MAX_BODY_LENGTH);
        }

        if (($problem = TemplateSyntax::check($body)) !== null) {
            throw InvalidTemplateException::badSyntax($problem);
        }

        $active = $this->versions->activeFor($company->id, $type);

        if ($active !== null && $active->body === $body) {
            return $active;
        }

        // The check above is the cheap path. The one that holds is made by the repository under its lock, inside the
        // audited transaction: a second submit of the same text that raced past this one is refused there, which
        // rolls the transaction back, so it gets neither a version number nor an audit entry.
        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'template_version.authored',
                reason: $reason,
                reasonRequired: true,
                mutation: fn () => $this->versions->author($company->id, $type, $body, $actor->id),
            );
        } catch (TemplateUnchangedException $unchanged) {
            return $unchanged->version;
        }
    }
}
