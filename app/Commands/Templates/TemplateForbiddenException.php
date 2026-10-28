<?php

namespace App\Commands\Templates;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor may not make this template change ({@see TemplateAuthority}): the company or section is not one they
 * command, the template is the regiment default and they are not an Admin, or the section is outside their write scope
 * for a pick. An `AuthorizationException`, so any HTTP caller that lets it propagate answers 403.
 */
class TemplateForbiddenException extends AuthorizationException
{
    public static function company(): self
    {
        return new self("Only the company's command or an Admin may change its templates.");
    }

    /** A section's own templates: its command (SL, ASL and the command above) or an Admin. */
    public static function sectionTemplates(): self
    {
        return new self("Only the section's command or an Admin may change its templates.");
    }

    public static function regimentDefault(): self
    {
        return new self('Only an Admin may change the regiment default template.');
    }

    public static function section(): self
    {
        return new self("Only the section's leaders may pick its template.");
    }
}
