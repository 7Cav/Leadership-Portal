<?php

namespace App\Commands\Units;

use App\Models\UnitType;
use RuntimeException;

/**
 * The requested node would break the chain of command's echelon ordering (AD-18): a root must
 * be a Regiment, and every other node must sit exactly one rank below its parent. Lives in the
 * CommandHandler layer so the controller can catch it and turn it into a form validation error
 * (Controller → CommandHandler is allowed).
 */
class InvalidUnitPlacementException extends RuntimeException
{
    public static function rootMustBeRegiment(UnitType $given): self
    {
        return new self("A unit with no parent must be a regiment, not a {$given->value}.");
    }

    public static function wrongRankUnderParent(UnitType $given, UnitType $parent): self
    {
        return new self(
            $given === UnitType::Group || $parent === UnitType::Group
                ? "A {$given->value} cannot be placed under a {$parent->value}: a group sits under the regiment or a staff group, and nothing on the line sits under a group."
                : "A {$given->value} cannot be placed under a {$parent->value}: it must sit exactly one level below its parent.",
        );
    }

    public static function cyclicParent(string $unitName, string $newParentName): self
    {
        return new self(
            "Cannot move \"{$unitName}\" under \"{$newParentName}\": that would place it under one of its own units.",
        );
    }
}
