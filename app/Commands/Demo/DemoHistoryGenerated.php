<?php

namespace App\Commands\Demo;

use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What one demo-history generation did (#115): the weeks it wrote, the weeks it skipped because
 * real history already holds them, and the rows it wrote. `troopers === 0` means the subtree has
 * nobody placed, so nothing was generated.
 */
final readonly class DemoHistoryGenerated
{
    /**
     * @param  list<CarbonImmutable>  $generatedWeeks  ascending
     * @param  list<CarbonImmutable>  $skippedWeeks  ascending — held real history
     */
    public function __construct(
        public Unit $unit,
        public array $generatedWeeks,
        public array $skippedWeeks,
        public int $troopers,
        public int $sections,
        public int $attendance,
        public int $reports,
    ) {}

    public function hasNoTroopers(): bool
    {
        return $this->troopers === 0;
    }

    /** One line for the console and the page's toast. */
    public function summary(): string
    {
        $unit = $this->unit->callsign ?? $this->unit->name;

        if ($this->hasNoTroopers()) {
            return "No troopers are placed in {$unit}'s sections, so no demo history was generated.";
        }

        $weeks = count($this->generatedWeeks);
        $line = $weeks === 0
            ? "No demo history was generated for {$unit}."
            : sprintf(
                'Generated %d %s of demo history for %s: %d %s in %d %s, %d attendance rows, %d sent reports.',
                $weeks,
                Str::plural('week', $weeks),
                $unit,
                $this->troopers,
                Str::plural('trooper', $this->troopers),
                $this->sections,
                Str::plural('section', $this->sections),
                $this->attendance,
                $this->reports,
            );

        if ($this->skippedWeeks !== []) {
            $line .= ' Skipped '.implode(', ', array_map(fn (CarbonImmutable $week) => $week->toDateString(), $this->skippedWeeks))
                .': real attendance or reports already exist.';
        }

        return $line;
    }

    /**
     * @return array{unit: string, generated_weeks: list<string>, skipped_weeks: list<string>, troopers: int, sections: int, attendance: int, reports: int, summary: string}
     */
    public function toArray(): array
    {
        return [
            'unit' => $this->unit->callsign ?? $this->unit->name,
            'generated_weeks' => array_map(fn (CarbonImmutable $week) => $week->toDateString(), $this->generatedWeeks),
            'skipped_weeks' => array_map(fn (CarbonImmutable $week) => $week->toDateString(), $this->skippedWeeks),
            'troopers' => $this->troopers,
            'sections' => $this->sections,
            'attendance' => $this->attendance,
            'reports' => $this->reports,
            'summary' => $this->summary(),
        ];
    }
}
