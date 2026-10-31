<?php

namespace App\Repositories\SectionPracticeSlotRepository;

use App\Models\SectionPracticeSlot;
use App\Repositories\SectionPracticeSlotRepository;
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

    public function set(int $sectionId, int $weekday, int $startMinute): SectionPracticeSlot
    {
        return SectionPracticeSlot::query()->updateOrCreate(
            ['section_id' => $sectionId],
            ['weekday' => $weekday, 'start_minute' => $startMinute],
        );
    }

    public function delete(SectionPracticeSlot $slot): SectionPracticeSlot
    {
        $slot->delete();

        return $slot;
    }
}
