<?php

namespace App\Commands\Attendance;

use App\Authorization\ScopeResolver;
use App\Kpi\AutoExcused;
use App\Loa\LoaCover;
use App\Loa\LoaCoverage;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\PracticeTiming;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SectionPracticeSlotRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Confirms a section's practice (7.8): finds or creates the week's practice row and stamps it with
 * now and the actor, as one audited action (AD-16). Works for a captured practice and a purely
 * manual one alike. Confirming again re-stamps; each confirmation is its own audit entry. A leader
 * whose screen did not show the practice's current confirmation (another leader confirmed it in
 * the meantime, or at the same moment) gets that confirmation back, with no stamp and no entry of
 * their own: see {@see ConfirmSectionPractice::$seenConfirmedAt}. Refuses a
 * section outside the actor's scope (AD-8) and a unit that is not a section.
 *
 * Not the same as "signed" (a trooper signing the rollcall thread, 3.7), "posted" (the leader
 * posting the BBCode to the forum, 3.10) or the weekly report's send freeze (5.6): this says the
 * leader reviewed the practice marks. A later hand edit of a mark clears it (see
 * {@see MarkAttendanceHandler}), and capture pre-marks leave a confirmed practice alone.
 *
 * A week whose section report has been really sent is locked for this too (AD-12), as it is for a
 * mark: confirming it is refused with `RollcallWeekLockedException`, checked first inside the
 * audited transaction under the same shared lock, so a refused confirmation leaves no audit entry.
 *
 * The leader confirms the practice as held or as not held (#634), and only once it is over: before the week's practice
 * hour ends it is refused ({@see ConfirmPracticeRefusedException}). A section with no slot, or a week whose practice
 * was cancelled, has no hour and can be confirmed any time in the week. Held needs every trooper on the roll call
 * marked (leave and an automatic Excused count as marked, as on the screen); not held is refused while a trooper is
 * marked as attended, since then the practice took place.
 */
class ConfirmSectionPracticeHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
        private readonly SectionPracticeSlotRepository $slots,
        private readonly TrooperRepository $troopers,
        private readonly AttendanceRepository $attendance,
        private readonly LoaCoverage $loaCoverage,
        private readonly AutoExcused $autoExcused,
    ) {}

    /**
     * @throws ConfirmSectionPracticeForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section, or the week is before `WeekOf::floor()`.
     * @throws RollcallWeekLockedException when the section's report for the week has been sent.
     * @throws ConfirmPracticeRefusedException before the practice is over, or when the marks contradict the answer.
     */
    public function handle(User $actor, ConfirmSectionPractice $command): SectionPractice
    {
        if (! $this->scope->writeScope($actor)->contains($command->sectionId)) {
            throw new ConfirmSectionPracticeForbiddenException($command->sectionId);
        }

        $section = $this->units->find($command->sectionId);

        if ($section?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidAttendanceMarkException::weekBeforeFloor($weekStart);
        }

        $end = PracticeTiming::hourEnd($weekStart, $this->practices->find($command->sectionId, $weekStart), $this->slots->forSection($command->sectionId));

        if ($end !== null && $end->greaterThan(CarbonImmutable::now())) {
            throw ConfirmPracticeRefusedException::notOver($end);
        }

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'practice.confirmed',
                reason: null,
                reasonRequired: false,
                mutation: function () use ($command, $weekStart, $actor, $section) {
                    if ($this->reports->isSentFor($command->sectionId, $weekStart)) {
                        throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
                    }

                    $created = $this->practices->findOrCreate($command->sectionId, $weekStart);

                    // Decided under the row lock, so of two leaders confirming at once the second
                    // waits, then sees the first one's confirmation instead of stamping its own.
                    $practice = $this->practices->lockById($created->id) ?? $created;

                    if ($this->confirmedMeanwhile($practice, $command->seenConfirmedAt)) {
                        throw new PracticeConfirmedMeanwhileException($practice);
                    }

                    $this->refuseContradictingMarks($section, $practice, $weekStart, $command->held);

                    return $this->practices->confirm($practice, $actor->id, $command->held);
                },
            );
        } catch (PracticeConfirmedMeanwhileException $meanwhile) {
            return $this->practices->find($command->sectionId, $weekStart) ?? $meanwhile->practice;
        }
    }

    /**
     * Held needs a practice mark for every trooper on the roll call, less those whose leave covers the week or who are
     * excused automatically; not held needs nobody marked as attended at it.
     *
     * @throws ConfirmPracticeRefusedException
     */
    private function refuseContradictingMarks(Unit $section, SectionPractice $practice, CarbonImmutable $weekStart, bool $held): void
    {
        $rows = $this->attendance->forPractice($practice)->keyBy(fn (Attendance $row) => $row->trooper_id);

        if (! $held) {
            if ($rows->contains(fn (Attendance $row) => $row->status === AttendanceStatus::Present)) {
                throw ConfirmPracticeRefusedException::attended();
            }

            return;
        }

        $ids = $this->troopers->onRollcall($section, $weekStart)->troopers->modelKeys();
        $covered = array_keys(array_filter($this->loaCoverage->forTroopers($ids, $weekStart), fn (LoaCover $cover) => $cover->isCovered()));
        $excused = array_keys($this->autoExcused->forTroopers($ids, $weekStart));
        $unmarked = array_filter(
            array_diff($ids, $covered, $excused),
            fn (int $id) => $rows->get($id)?->status === null,
        );

        if ($unmarked !== []) {
            throw ConfirmPracticeRefusedException::unmarked(count($unmarked));
        }
    }

    /**
     * Whether the practice holds a confirmation other than the one the leader saw. A practice with
     * no confirmation never counts: confirming it is always a real confirmation. `confirmed_at` is
     * stored to the second, which is also what the screen is sent.
     */
    private function confirmedMeanwhile(SectionPractice $practice, DateTimeImmutable|false|null $seen): bool
    {
        if ($seen === false || $practice->confirmed_at === null) {
            return false;
        }

        return $seen === null || $practice->confirmed_at->getTimestamp() !== $seen->getTimestamp();
    }
}
