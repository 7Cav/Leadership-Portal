<?php

namespace App\Commands\Demo;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Generate demo history (#115) for `$unitId`'s whole subtree over the last `$weeks` completed Cav
 * weeks before `$now` (default: the current moment). Handled by {@see GenerateDemoHistoryHandler}.
 */
final readonly class GenerateDemoHistory
{
    public const DEFAULT_WEEKS = 8;

    public const MAX_WEEKS = 12;

    /**
     * @throws InvalidArgumentException when `$weeks` is outside 1–{@see MAX_WEEKS}.
     */
    public function __construct(
        public int $unitId,
        public int $weeks = self::DEFAULT_WEEKS,
        public ?DateTimeImmutable $now = null,
    ) {
        if ($weeks < 1 || $weeks > self::MAX_WEEKS) {
            throw new InvalidArgumentException('Demo history covers 1 to '.self::MAX_WEEKS." weeks; got {$weeks}.");
        }
    }
}
