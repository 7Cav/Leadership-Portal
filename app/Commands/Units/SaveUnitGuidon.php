<?php

namespace App\Commands\Units;

/**
 * Save a unit's guidon design (#446): the two colors of the diagonal split and the text on each half. Handled by
 * {@see SaveUnitGuidonHandler}, which checks every field again before anything is written.
 */
final readonly class SaveUnitGuidon
{
    public function __construct(
        public int $unitId,
        public string $primaryColor,
        public string $secondaryColor,
        public string $topLeftText,
        public string $bottomRightText,
    ) {}
}
