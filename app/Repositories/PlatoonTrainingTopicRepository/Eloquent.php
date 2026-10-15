<?php

namespace App\Repositories\PlatoonTrainingTopicRepository;

use App\Models\PlatoonTrainingTopic;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Eloquent implements PlatoonTrainingTopicRepository
{
    public function find(int $unitId, CarbonInterface $weekStart): ?PlatoonTrainingTopic
    {
        return PlatoonTrainingTopic::query()
            ->with('setter')
            ->where('unit_id', $unitId)
            ->where('week_start', $this->assertWeekStart($weekStart))
            ->first();
    }

    public function forSection(Unit $section, CarbonInterface $weekStart): ?PlatoonTrainingTopic
    {
        $weekStart = $this->assertWeekStart($weekStart);

        if ($section->type !== UnitType::Section || $section->parent_id === null) {
            return null;
        }

        return $this->find($section->parent_id, $weekStart);
    }

    public function set(int $unitId, CarbonInterface $weekStart, string $subject, bool $enforced, int $setBy): PlatoonTrainingTopic
    {
        $weekStart = $this->assertWeekStart($weekStart);

        return DB::transaction(function () use ($unitId, $weekStart, $subject, $enforced, $setBy) {
            $topic = PlatoonTrainingTopic::query()
                ->where('unit_id', $unitId)
                ->where('week_start', $weekStart)
                ->lockForUpdate()
                ->first() ?? new PlatoonTrainingTopic(['unit_id' => $unitId, 'week_start' => $weekStart]);

            $topic->fill(['subject' => $subject, 'enforced' => $enforced, 'set_by' => $setBy])->save();

            return $topic;
        });
    }

    public function clear(int $unitId, CarbonInterface $weekStart): ?PlatoonTrainingTopic
    {
        $weekStart = $this->assertWeekStart($weekStart);

        return DB::transaction(function () use ($unitId, $weekStart) {
            $topic = PlatoonTrainingTopic::query()
                ->where('unit_id', $unitId)
                ->where('week_start', $weekStart)
                ->lockForUpdate()
                ->first();

            $topic?->delete();

            return $topic;
        });
    }

    /** The same rule as `WeeklyReportRepository`: `$weekStart` must already be a week boundary (AD-11). */
    private function assertWeekStart(CarbonInterface $weekStart): CarbonImmutable
    {
        $boundary = WeekOf::start($weekStart);

        if (! $boundary->equalTo($weekStart)) {
            throw new InvalidArgumentException(
                "week_start must be a Saturday 00:00 UTC (AD-11); got {$weekStart->toIso8601String()}.",
            );
        }

        return $boundary;
    }
}
