<?php

namespace App\Commands\Practices;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SectionPracticeSlotRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Move one week's section practice to another start, cancel it, or put it back on the slot (#392, #558). Only that
 * week changes; the slot stays. The new start must fall in the same Cav week (a practice belongs to the week of its
 * start, AD-11). Refused for a section with no slot, a week whose report was sent (AD-12) and a practice already
 * captured, whose window is history. Gated and audited as {@see SetPracticeSlot}.
 */
class ChangePracticeWeek
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly SectionPracticeSlotRepository $slots,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * `$movedTo` null and `$cancelled` false puts the week back on the slot. `$week` is any moment in the week.
     *
     * @throws PracticeSlotForbiddenException when the section is outside the actor's write scope (or does not exist).
     * @throws InvalidPracticeSlotException when the unit is not a section, it has no slot, the practice was captured,
     *                                      or `$movedTo` is outside the week.
     * @throws RollcallWeekLockedException when the section's report for the week has been sent.
     */
    public function handle(User $actor, int $sectionId, DateTimeInterface $week, ?DateTimeInterface $movedTo, bool $cancelled): void
    {
        $section = $this->scope->writeScope($actor)->contains($sectionId) ? $this->units->find($sectionId) : null;

        if ($section === null) {
            throw new PracticeSlotForbiddenException;
        }

        if ($section->type !== UnitType::Section) {
            throw InvalidPracticeSlotException::notASection($section);
        }

        if ($this->slots->forSection($sectionId) === null) {
            throw InvalidPracticeSlotException::noSlot();
        }

        $weekStart = WeekOf::start($week);

        if ($this->reports->isSentFor($sectionId, $weekStart)) {
            throw RollcallWeekLockedException::for($sectionId, $weekStart);
        }

        $movedTo = $movedTo === null || $cancelled ? null : CarbonImmutable::instance($movedTo)->utc()->startOfMinute();

        if ($movedTo !== null && ! WeekOf::start($movedTo)->equalTo($weekStart)) {
            throw InvalidPracticeSlotException::outsideWeek();
        }

        $existing = $this->practices->find($sectionId, $weekStart);

        if ($existing?->captured_at !== null) {
            throw InvalidPracticeSlotException::alreadyCaptured();
        }

        // Putting back a week that was never changed changes nothing, and no practice row is made for it.
        if ($existing === null && $movedTo === null && ! $cancelled) {
            return;
        }

        if ($existing !== null && $existing->slot_cancelled === $cancelled
            && $existing->slot_moved_to?->getTimestamp() === $movedTo?->getTimestamp()) {
            return;
        }

        $this->audit->record($actor->id, 'practice_week.changed', null, false, fn () => $this->practices->changeWeek(
            $existing ?? $this->practices->findOrCreate($sectionId, $weekStart),
            $movedTo,
            $cancelled,
        ));
    }
}
