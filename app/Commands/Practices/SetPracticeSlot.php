<?php

namespace App\Commands\Practices;

use App\Authorization\ScopeResolver;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SectionPracticeSlotRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\PracticeWindow;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Set or clear a section's weekly practice slot (#392): a weekday and a start time in Zulu, the practice lasting one
 * hour. Gated by the section's write scope (AD-8): its leaders, the command above them and Admins; a staff grant only
 * reads, and a view-as preview is refused before it gets here. Audited (AD-16); a change that changes nothing is not
 * logged. A change or removal starts with the first week not yet held ({@see changesFrom()}): the current week, or the
 * next one when the current week is held (its report sent, AD-12, reopened for editing or not, or its practice
 * captured). Every week before keeps the slot it had, or none, whichever week the editor was opened from (#630); the
 * repository keeps each change so a later one never rewrites a past week.
 */
class SetPracticeSlot
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
     * The Cav week (its Saturday) a slot change made now starts with: the current week, or the next one when the
     * current week is held (its report sent, including one reopened for editing, or its practice captured).
     */
    public function changesFrom(int $sectionId, ?DateTimeInterface $now = null): CarbonImmutable
    {
        $thisWeek = WeekOf::start($now ?? CarbonImmutable::now());

        $held = $this->reports->isSentFor($sectionId, $thisWeek)
            || $this->practices->find($sectionId, $thisWeek)?->captured_at !== null;

        return $held ? WeekOf::next($thisWeek) : $thisWeek;
    }

    /**
     * `$weekday` or `$startMinute` null clears the slot.
     *
     * @throws PracticeSlotForbiddenException when the section is outside the actor's write scope (or does not exist).
     * @throws InvalidPracticeSlotException when the unit is not a section or the slot is out of range.
     */
    public function handle(User $actor, int $sectionId, ?int $weekday, ?int $startMinute): void
    {
        // Scope first, so a unit outside it is refused whether or not it exists (AD-8).
        $section = $this->scope->writeScope($actor)->contains($sectionId) ? $this->units->find($sectionId) : null;

        if ($section === null) {
            throw new PracticeSlotForbiddenException;
        }

        if ($section->type !== UnitType::Section) {
            throw InvalidPracticeSlotException::notASection($section);
        }

        $current = $this->slots->forSection($sectionId);
        $effectiveFrom = $this->changesFrom($sectionId);

        if ($weekday === null || $startMinute === null) {
            if ($current !== null) {
                $this->audit->record($actor->id, 'practice_slot.cleared', null, false, fn () => $this->slots->delete($current, $effectiveFrom));
            }

            return;
        }

        try {
            PracticeWindow::requireSlot($weekday, $startMinute);
        } catch (InvalidArgumentException) {
            throw InvalidPracticeSlotException::outOfRange();
        }

        if ($current?->weekday === $weekday && $current->start_minute === $startMinute) {
            return;
        }

        // The weeks before keep the slot in effect the week before, which may itself be the one before a pending change.
        $previous = $this->slots->placingFor($sectionId, WeekOf::previous($effectiveFrom));

        $this->audit->record($actor->id, 'practice_slot.set', null, false, fn () => $this->slots->set($sectionId, $weekday, $startMinute, $effectiveFrom, $previous));
    }
}
