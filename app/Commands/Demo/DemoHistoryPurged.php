<?php

namespace App\Commands\Demo;

use App\Models\Unit;
use App\Repositories\DemoHistoryCounts;

/**
 * What one demo-history purge deleted (#115) for a unit's subtree.
 */
final readonly class DemoHistoryPurged
{
    public function __construct(
        public Unit $unit,
        public DemoHistoryCounts $deleted,
    ) {}

    /** One line for the console and the page's toast. */
    public function summary(): string
    {
        $unit = $this->unit->callsign ?? $this->unit->name;

        if ($this->deleted->isEmpty()) {
            return "There is no demo history for {$unit} to purge.";
        }

        return sprintf(
            'Purged demo history for %s: %d practices, %d attendance rows and %d reports across %d %s.',
            $unit,
            $this->deleted->practices,
            $this->deleted->attendance,
            $this->deleted->reports,
            $this->deleted->weeks,
            $this->deleted->weeks === 1 ? 'week' : 'weeks',
        );
    }

    /**
     * @return array{unit: string, practices: int, attendance: int, reports: int, weeks: int, summary: string}
     */
    public function toArray(): array
    {
        return [
            'unit' => $this->unit->callsign ?? $this->unit->name,
            ...$this->deleted->toArray(),
            'summary' => $this->summary(),
        ];
    }
}
