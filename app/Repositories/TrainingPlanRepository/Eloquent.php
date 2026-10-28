<?php

namespace App\Repositories\TrainingPlanRepository;

use App\Models\TrainingPlan;
use App\Models\TrainingPlanStatus;
use App\Models\TrainingPlanWeek;
use App\Repositories\TrainingPlanRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class Eloquent implements TrainingPlanRepository
{
    /** What a plan read for its own page carries. */
    private const WITH = ['weeks', 'writer', 'writerAdmin', 'writtenAsUnit', 'reviewer', 'reviewerAdmin', 'reviewedAsUnit'];

    public function find(int $unitId, string $month): ?TrainingPlan
    {
        return TrainingPlan::query()
            ->with(self::WITH)
            ->where('unit_id', $unitId)
            ->where('month', $month)
            ->first();
    }

    public function forUnits(array $unitIds, string $month): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return TrainingPlan::query()
            ->with('weeks')
            ->whereIn('unit_id', $unitIds)
            ->where('month', $month)
            ->get()
            ->keyBy('unit_id');
    }

    public function lockFor(int $unitId, string $month): ?TrainingPlan
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TrainingPlanRepository::lockFor() must run inside a transaction; on its own the lock would be released at once.');
        }

        $plan = TrainingPlan::query()
            ->where('unit_id', $unitId)
            ->where('month', $month)
            ->lockForUpdate()
            ->first();

        return $plan?->load(self::WITH);
    }

    public function save(int $unitId, string $month, array $subjects, bool $enforced, int $writtenBy, ?int $writtenByAdmin = null, ?int $writtenAsUnit = null): TrainingPlan
    {
        $weeks = collect($subjects)->mapWithKeys(fn (string $subject, string $date) => [$this->weekStart($date)->toDateTimeString() => $subject]);
        $written = ['status' => TrainingPlanStatus::Pending->value, 'enforced' => $enforced, 'written_by' => $writtenBy, 'written_by_admin' => $writtenByAdmin, 'written_as_unit' => $writtenAsUnit];
        $review = ['reviewed_by' => null, 'reviewed_by_admin' => null, 'reviewed_as_unit' => null, 'reviewed_at' => null, 'review_comment' => null];

        // An upsert on the UNIQUE `(unit_id, month)` key, then a lock on the row it landed on, for the reason
        // `PlatoonTrainingTopicRepository::set()` gives: a locked read of a missing key takes only a gap lock on
        // MySQL, so two first saves of one month would both insert and the loser would fail on the key.
        return DB::transaction(function () use ($unitId, $month, $weeks, $written, $review) {
            TrainingPlan::query()->upsert(
                [['unit_id' => $unitId, 'month' => $month, 'revision' => 1, ...$written, ...$review]],
                ['unit_id', 'month'],
                [...$written, ...$review, 'revision' => DB::raw('revision + 1')],
            );

            $plan = TrainingPlan::query()->where('unit_id', $unitId)->where('month', $month)->lockForUpdate()->sole();

            TrainingPlanWeek::query()->where('training_plan_id', $plan->id)->delete();
            foreach ($weeks as $weekStart => $subject) {
                TrainingPlanWeek::query()->create(['training_plan_id' => $plan->id, 'week_start' => CarbonImmutable::parse($weekStart, 'UTC'), 'subject' => $subject]);
            }

            return $plan->load(self::WITH);
        });
    }

    public function approve(TrainingPlan $plan, int $reviewedBy, ?int $reviewedByAdmin = null, ?int $reviewedAsUnit = null): TrainingPlan
    {
        return $this->review($plan, TrainingPlanStatus::Approved, null, $reviewedBy, $reviewedByAdmin, $reviewedAsUnit);
    }

    public function sendBack(TrainingPlan $plan, string $comment, int $reviewedBy, ?int $reviewedByAdmin = null, ?int $reviewedAsUnit = null): TrainingPlan
    {
        return $this->review($plan, TrainingPlanStatus::Returned, $comment, $reviewedBy, $reviewedByAdmin, $reviewedAsUnit);
    }

    private function review(TrainingPlan $plan, TrainingPlanStatus $status, ?string $comment, int $reviewedBy, ?int $reviewedByAdmin, ?int $reviewedAsUnit): TrainingPlan
    {
        // `forceFill`: none of these is mass-assignable (see the model), so they only ever move together, here.
        $plan->forceFill([
            'status' => $status,
            'reviewed_by' => $reviewedBy,
            'reviewed_by_admin' => $reviewedByAdmin,
            'reviewed_as_unit' => $reviewedAsUnit,
            'reviewed_at' => CarbonImmutable::now('UTC'),
            'review_comment' => $comment,
        ])->save();

        return $plan->load(self::WITH);
    }

    /** `YYYY-MM-DD` of a week's opening Saturday, refused when it is anything else (AD-11). */
    private function weekStart(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        if ($day === false || $day->toDateString() !== $date) {
            throw new InvalidArgumentException("A week is YYYY-MM-DD; got {$date}.");
        }

        return WeekOf::requireStart($day);
    }
}
