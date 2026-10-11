<?php

namespace App\Repositories\SectionPracticeRepository;

use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\SectionPracticeRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

class Eloquent implements SectionPracticeRepository
{
    public function findOrCreate(int $sectionId, CarbonInterface $weekStart): SectionPractice
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if (! Unit::query()->whereKey($sectionId)->where('type', UnitType::Section->value)->exists()) {
            throw new InvalidArgumentException("Unit {$sectionId} is not a section; practices are per section (AD-14).");
        }

        $key = ['section_id' => $sectionId, 'week_start' => $weekStart];

        try {
            $practice = SectionPractice::query()->createOrFirst($key);
        } catch (UniqueConstraintViolationException $e) {
            // createOrFirst's own re-read is a plain (snapshot) read: inside a MySQL REPEATABLE
            // READ transaction that has already read, it cannot see a row a racing submit committed
            // after that snapshot, so it rethrows. A locking read always sees the latest committed
            // row, so the insert-or-find holds inside the audited transaction too.
            $practice = SectionPractice::query()->where($key)->sharedLock()->first() ?? throw $e;
        }

        // Found a generated demo practice (#115): a real mark is about to hang off it, so it is
        // real from now on — a demo purge or regeneration must leave it and that week alone.
        if ($practice->is_demo) {
            $practice->forceFill(['is_demo' => false])->save();
        }

        return $practice;
    }

    public function find(int $sectionId, CarbonInterface $weekStart): ?SectionPractice
    {
        return SectionPractice::query()
            ->where('section_id', $sectionId)
            ->where('week_start', WeekOf::requireStart($weekStart))
            ->first();
    }

    public function hasPracticesFor(Unit $unit): bool
    {
        return SectionPractice::query()->where('section_id', $unit->getKey())->exists();
    }
}
