<?php

namespace App\Repositories\SectionPracticeSlotRepository;

use App\Models\SectionPracticeSlot;
use App\Models\SectionPracticeSlotChange;
use App\Repositories\SectionPracticeSlotRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class Eloquent implements SectionPracticeSlotRepository
{
    public function forSection(int $sectionId): ?SectionPracticeSlot
    {
        return SectionPracticeSlot::query()->where('section_id', $sectionId)->first();
    }

    public function forSections(array $sectionIds): Collection
    {
        if ($sectionIds === []) {
            return collect();
        }

        return SectionPracticeSlot::query()->whereIn('section_id', $sectionIds)->get()->keyBy('section_id')->toBase();
    }

    public function all(): Collection
    {
        return SectionPracticeSlot::query()->with('section')->orderBy('section_id')->get()->toBase();
    }

    public function placingFor(int $sectionId, CarbonInterface $weekStart): ?array
    {
        $changes = SectionPracticeSlotChange::query()->where('section_id', $sectionId)->orderBy('id')->get();

        if ($changes->isEmpty()) {
            // No change recorded yet (a slot from before the history): the current row answers.
            return $this->forSection($sectionId)?->placingFor($weekStart);
        }

        $week = CarbonImmutable::instance($weekStart)->utc()->startOfDay();
        $inEffect = $changes
            ->filter(fn (SectionPracticeSlotChange $change) => $change->effective_from === null || ! $week->lessThan($change->effective_from->utc()->startOfDay()))
            // The latest start wins; two changes for the same week, the later one.
            ->sortBy(fn (SectionPracticeSlotChange $change) => [$change->effective_from?->toDateString() ?? '', $change->id])
            ->last();

        return $inEffect === null || $inEffect->weekday === null || $inEffect->start_minute === null
            ? null
            : [$inEffect->weekday, $inEffect->start_minute];
    }

    public function set(int $sectionId, int $weekday, int $startMinute, ?CarbonImmutable $effectiveFrom = null, ?array $previous = null): SectionPracticeSlot
    {
        $this->recordChange($sectionId, $effectiveFrom, $weekday, $startMinute);

        return SectionPracticeSlot::query()->updateOrCreate(
            ['section_id' => $sectionId],
            [
                'weekday' => $weekday,
                'start_minute' => $startMinute,
                'effective_from' => $effectiveFrom?->toDateString(),
                'previous_weekday' => $effectiveFrom === null ? null : $previous[0] ?? null,
                'previous_start_minute' => $effectiveFrom === null ? null : $previous[1] ?? null,
            ],
        );
    }

    public function delete(SectionPracticeSlot $slot, ?CarbonImmutable $effectiveFrom = null): SectionPracticeSlot
    {
        $this->recordChange($slot->section_id, $effectiveFrom, null, null);
        $slot->delete();

        return $slot;
    }

    /**
     * Append a change; a section's first one first records the slot it had (the current row, with any pending start),
     * so the weeks before keep it.
     */
    private function recordChange(int $sectionId, ?CarbonImmutable $effectiveFrom, ?int $weekday, ?int $startMinute): void
    {
        if (! SectionPracticeSlotChange::query()->where('section_id', $sectionId)->exists()) {
            $current = $this->forSection($sectionId);

            if ($current !== null) {
                if ($current->effective_from !== null) {
                    SectionPracticeSlotChange::query()->create(['section_id' => $sectionId, 'effective_from' => null, 'weekday' => $current->previous_weekday, 'start_minute' => $current->previous_start_minute]);
                }

                SectionPracticeSlotChange::query()->create(['section_id' => $sectionId, 'effective_from' => $current->effective_from?->toDateString(), 'weekday' => $current->weekday, 'start_minute' => $current->start_minute]);
            }
        }

        SectionPracticeSlotChange::query()->create(['section_id' => $sectionId, 'effective_from' => $effectiveFrom?->toDateString(), 'weekday' => $weekday, 'start_minute' => $startMinute]);
    }
}
