<?php

namespace App\Commands\Templates;

use App\Models\Unit;
use RuntimeException;

/**
 * A template change asks for something the portal will not store. Thrown before anything is written, and lives in the
 * CommandHandler layer so the controllers can turn it into a form error on the right field (Controller →
 * CommandHandler). `$field` names that field: the company, the name, the body or the template.
 */
class InvalidTemplateException extends RuntimeException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function notACompany(Unit $unit): self
    {
        return new self("{$unit->name} is a {$unit->type->value}, not a company; a template belongs to a company.", 'company_id');
    }

    public static function emptyBody(): self
    {
        return new self('A template needs some text.', 'body');
    }

    public static function bodyTooLong(int $max): self
    {
        return new self("A template can be at most {$max} characters.", 'body');
    }

    /** The template's tags are not all known and balanced (Story 3.10): `$problem` says which one is wrong. */
    public static function badSyntax(string $problem): self
    {
        return new self($problem, 'body');
    }

    public static function emptyName(): self
    {
        return new self('A template needs a name.', 'name');
    }

    public static function nameTooLong(int $max): self
    {
        return new self("A template name can be at most {$max} characters.", 'name');
    }

    public static function duplicateName(string $name): self
    {
        return new self("This company already has a template called \"{$name}\".", 'name');
    }

    public static function regimentDefaultAlwaysOn(): self
    {
        return new self('The regiment default is always available and cannot be switched off.', 'template_id');
    }

    public static function notThisCompanys(): self
    {
        return new self("That template is not one of this company's own.", 'template_id');
    }

    public static function notASection(Unit $unit): self
    {
        return new self("{$unit->name} is not a section; only a section chooses its template.", 'unit');
    }

    public static function notAvailable(): self
    {
        return new self('That template is not switched on for this section. Choose another.', 'template_id');
    }
}
