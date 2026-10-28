<?php

namespace App\Commands\Templates;

use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Rollcall\TemplateSyntax;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;

/**
 * Edit a template (Story 3.5, #375, AD-17): the edit writes a new version, never changes an existing one, and the
 * template's active pointer advances to it. A company's command edits its own company's templates, a section's command
 * the section's own, and only an Admin the regiment default ({@see TemplateAuthority}), checked here. The write goes through {@see AuditEnvelope} with a
 * reason always required (AD-16), since a template change reaches every section that uses it.
 *
 * The body is stored as given, but only if it is a well-formed template (Story 3.10): it has some text and a bounded
 * length, and its tags are all known and balanced, so a typo is refused here instead of reaching every section's
 * rollcall. Saving text identical to the active version is a no-op that is not logged, as for the other commands: work
 * that did not happen does not get a version number.
 */
class AuthorTemplateVersion
{
    /** The longest body anyone may save. A BBCode post is far shorter; this stops a paste of the wrong thing. */
    public const MAX_BODY_LENGTH = 20000;

    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly TemplateAuthority $authority,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws TemplateForbiddenException when the actor may not manage the template.
     * @throws InvalidTemplateException when the body is empty, too long or not a well-formed template.
     */
    public function handle(User $actor, Template $template, string $body, ?string $reason): TemplateVersion
    {
        if (! $this->authority->manages($actor, $template)) {
            throw $this->authority->refusal($template);
        }

        self::checkBody($body);

        $active = $template->activeVersion;

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
                mutation: fn () => $this->versions->authorVersion($template, $body, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id)),
            );
        } catch (TemplateUnchangedException $unchanged) {
            return $unchanged->version();
        }
    }

    /**
     * The body rules every saved template meets (Story 3.10).
     *
     * @throws InvalidTemplateException when the body is empty, too long or not a well-formed template.
     */
    public static function checkBody(string $body): void
    {
        if (trim($body) === '') {
            throw InvalidTemplateException::emptyBody();
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw InvalidTemplateException::bodyTooLong(self::MAX_BODY_LENGTH);
        }

        if (($problem = TemplateSyntax::check($body)) !== null) {
            throw InvalidTemplateException::badSyntax($problem);
        }
    }
}
