<?php

namespace App\Commands\Units;

/**
 * Save a unit's guidon design (#446): its format, the two colors, the text on each half
 * and where it sits, and the optional large character at the hoist. Handled by
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
        public string $format = 'diagonal',
        public string $topAlign = 'left',
        public string $bottomAlign = 'left',
        public string $largeText = '',
    ) {}
}
