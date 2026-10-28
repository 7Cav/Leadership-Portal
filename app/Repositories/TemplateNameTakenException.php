<?php

namespace App\Repositories;

use RuntimeException;

/**
 * Raised by {@see TemplateVersionRepository::createTemplate()} from inside its locked section when the company already
 * has a template of that name, so a second quick submit cannot add a twin the Command's own check let through. Thrown
 * rather than returned so it rolls the audited transaction back; the Command turns it into a form error on the name.
 */
final class TemplateNameTakenException extends RuntimeException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct("The company already has a template called \"{$name}\".");
    }
}
