<?php

namespace App\Commands\Attendance;

use App\Authorization\ScopeResolver;
use App\Models\SectionPractice;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
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
 */
class ConfirmSectionPracticeHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws ConfirmSectionPracticeForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section, or the week is before `WeekOf::floor()`.
     * @throws RollcallWeekLockedException when the section's report for the week has been sent.
     */
    public function handle(User $actor, ConfirmSectionPractice $command): SectionPractice
    {
        if (! $this->scope->writeScope($actor)->contains($command->sectionId)) {
            throw new ConfirmSectionPracticeForbiddenException($command->sectionId);
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidAttendanceMarkException::weekBeforeFloor($weekStart);
        }

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'practice.confirmed',
                reason: null,
                reasonRequired: false,
                mutation: function () use ($command, $weekStart, $actor) {
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

                    return $this->practices->confirm($practice, $actor->id);
                },
            );
        } catch (PracticeConfirmedMeanwhileException $meanwhile) {
            return $this->practices->find($command->sectionId, $weekStart) ?? $meanwhile->practice;
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
