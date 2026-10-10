<?php

namespace App\Commands\Templates;

use App\Models\Unit;
use RuntimeException;

/**
 * A template edit asks for something the portal will not store. Thrown before anything is written, and lives in the
 * CommandHandler layer so the settings controller can turn it into a form error (Controller → CommandHandler).
 */
class InvalidTemplateException extends RuntimeException
{
    public static function notACompany(Unit $unit): self
    {
        return new self("Unit {$unit->id} is a {$unit->type->value}, not a company; a template belongs to a company.");
    }

    public static function emptyBody(): self
    {
        return new self('A template needs some text.');
    }

    public static function bodyTooLong(int $max): self
    {
        return new self("A template can be at most {$max} characters.");
    }
}
