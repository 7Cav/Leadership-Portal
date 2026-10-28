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
            ->with(['setter', 'setterAdmin', 'setAsUnit'])
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

    public function set(int $unitId, CarbonInterface $weekStart, string $subject, bool $enforced, int $setBy, ?int $setByAdmin = null, ?int $setAsUnit = null): PlatoonTrainingTopic
    {
        $weekStart = $this->assertWeekStart($weekStart);

        // An upsert on the UNIQUE `(unit_id, week_start)` key, then a lock on the row it landed on,
        // rather than a locked read and an insert: a `SELECT ... FOR UPDATE` on a missing key takes
        // only a gap lock on MySQL, so two first sets of one week would both insert, and the loser
        // would end in a duplicate-key error or a deadlock. The upsert takes an exclusive lock on the
        // row whether it inserts or updates, so concurrent sets queue one at a time.
        return DB::transaction(function () use ($unitId, $weekStart, $subject, $enforced, $setBy, $setByAdmin, $setAsUnit) {
            PlatoonTrainingTopic::query()->upsert(
                [['unit_id' => $unitId, 'week_start' => $weekStart, 'subject' => $subject, 'enforced' => $enforced, 'set_by' => $setBy, 'set_by_admin' => $setByAdmin, 'set_as_unit' => $setAsUnit]],
                ['unit_id', 'week_start'],
                ['subject', 'enforced', 'set_by', 'set_by_admin', 'set_as_unit'],
            );

            return PlatoonTrainingTopic::query()
                ->where('unit_id', $unitId)
                ->where('week_start', $weekStart)
                ->lockForUpdate()
                ->sole();
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

    public function hasTopicsFor(Unit $unit): bool
    {
        return PlatoonTrainingTopic::query()->where('unit_id', $unit->getKey())->exists();
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
