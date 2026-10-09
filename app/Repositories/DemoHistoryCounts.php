<?php

namespace App\Repositories;

/**
 * How much demo history (#115) a read or a purge covered: rows tagged `is_demo` in each of the
 * three tables the demo writer fills, and the number of distinct Cav weeks they span.
 */
final readonly class DemoHistoryCounts
{
    public function __construct(
        public int $practices,
        public int $attendance,
        public int $reports,
        public int $weeks,
    ) {}

    public function isEmpty(): bool
    {
        return $this->practices === 0 && $this->attendance === 0 && $this->reports === 0;
    }

    /**
     * @return array{practices: int, attendance: int, reports: int, weeks: int}
     */
    public function toArray(): array
    {
        return [
            'practices' => $this->practices,
            'attendance' => $this->attendance,
            'reports' => $this->reports,
            'weeks' => $this->weeks,
        ];
    }
}
