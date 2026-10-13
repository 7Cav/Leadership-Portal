<?php

namespace App\Commands\Templates;

use App\Models\Unit;
use RuntimeException;

/**
 * A template edit asks for something the portal will not store. Thrown before anything is written, and lives in the
 * CommandHandler layer so the settings controller can turn it into a form error on the right field (Controller →
 * CommandHandler). `$field` names that field: the company, or the body.
 */
class InvalidTemplateException extends RuntimeException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function notACompany(Unit $unit): self
    {
        return new self("Unit {$unit->id} is a {$unit->type->value}, not a company; a template belongs to a company.", 'company_id');
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
}
