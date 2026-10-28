<?php

namespace App\Commands\Templates;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor may not make this template change ({@see TemplateAuthority}): the company is not one they command, its
 * battalion switched its own template editing off, the template is the regiment default and they are not an Admin, or
 * the section is outside their write scope. An
 * `AuthorizationException`, so any HTTP caller that lets it propagate answers 403.
 */
class TemplateForbiddenException extends AuthorizationException
{
    public static function company(): self
    {
        return new self("Only the company's command or an Admin may change its templates.");
    }

    /** The company's command, while its battalion command has switched the company's own template editing off (#376). */
    public static function lockedByBattalion(): self
    {
        return new self("Battalion command has turned off this company's own template editing. The battalion or an Admin sets its templates.");
    }

    public static function policy(): self
    {
        return new self("Only the battalion command above the company or an Admin may switch the company's template editing.");
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
