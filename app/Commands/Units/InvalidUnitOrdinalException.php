<?php

namespace App\Commands\Units;

use App\Models\UnitType;
use RuntimeException;

/**
 * The ordinal picked for a hand-made unit cannot name one: it is out of range, already used under the same parent, or
 * the parent has no designation to build on. The controller turns it into a form error on `ordinal`.
 */
class InvalidUnitOrdinalException extends RuntimeException
{
    public static function invalid(UnitType $type): self
    {
        return new self("Pick which {$type->value} this is from the list.");
    }

    public static function taken(string $name): self
    {
        return new self("{$name} already exists here. Pick another.");
    }

    public static function parentHasNoDesignation(): self
    {
        return new self('The parent unit has no callsign to build this one from. Give it one first.');
    }

    public static function nameRequired(): self
    {
        return new self('Give the group a name.');
    }
}
