<?php

namespace App\Commands\Templates;

use App\Models\Unit;
use RuntimeException;

/**
 * A template change asks for something the portal will not store. Thrown before anything is written, and lives in the
 * CommandHandler layer so the controllers can turn it into a form error on the right field (Controller →
 * CommandHandler). `$field` names that field: the company or section, the name, the body or the template.
 */
class InvalidTemplateException extends RuntimeException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    /** `$field` is the form field that named the unit: `company_id`, or `section_id` for a section's own. */
    public static function notAnOwner(Unit $unit, string $field = 'company_id'): self
    {
        return new self("{$unit->name} is a {$unit->type->value}; a template belongs to a company or a section.", $field);
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

    /** `$owner` says whose: "company" or "section". */
    public static function duplicateName(string $name, string $owner = 'company'): self
    {
        return new self("This {$owner} already has a template called \"{$name}\".", 'name');
    }

    public static function regimentDefaultAlwaysOn(): self
    {
        return new self('The regiment default is always available and cannot be switched off.', 'template_id');
    }

    public static function notThisCompanys(): self
    {
        return new self("That template is not one of this company's own.", 'template_id');
    }

    /**
     * A company default that is switched off would be passed over: its sections would render from the regiment default
     * without anyone being told. It is switched on first.
     */
    public static function switchedOffDefault(): self
    {
        return new self('That template is switched off, so the sections would still use the regiment default. Switch it on first, then make it the company default.', 'template_id');
    }

    /** A section's own template is only for that section, which may always use it: there is nothing to switch. */
    public static function sectionTemplateAlwaysOn(): self
    {
        return new self("A section's own template is always available to that section and cannot be switched off.", 'template_id');
    }

    public static function notASection(Unit $unit): self
    {
        return new self("{$unit->name} is not a section; only a section chooses its template.", 'unit');
    }

    public static function notAvailable(): self
    {
        return new self('That template is not available to this section. Choose another.', 'template_id');
    }
}
