<?php

namespace App\Commands\Capture;

use App\Commands\Practices\PracticeSchedule;
use App\Models\SectionPracticeSlot;
use App\Repositories\GameRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SectionPracticeSlotRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\PracticeWindow;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Which section practices the scheduler should capture now (#393, AD-19): each section with a practice slot (#392), a
 * game in effect (its own or the nearest ancestor's, #390) that a capture can read (a selected training server with
 * credentials, #389), and a practice whose one-hour window ended in the current Cav week and has not been captured or
 * attempted. The capture reads exactly that window, triggered by `'system'`.
 *
 * "Attempted" is a `sync_runs` row for the practice (source `capture`), finished or not, so a run is never repeated by
 * a later tick whether it succeeded or failed; a failed one gets "Try again" on the roll call. A capture queued but not
 * yet started is remembered for {@see QUEUED_MINUTES}, so a slow queue does not get it twice.
 *
 * Skipped without opening a run: a section with no slot (not listed), a week cancelled, a window not yet over, a
 * locked week (its report was sent, AD-12), and a section with no game or a game nothing can be read for. A practice
 * that ends after the week turns (a Friday 2330Z slot) belongs to the week it started in, and is due in the new week.
 * The practice row is created only when the capture is really queued, never on a dry run.
 */
class CapturePracticesDue
{
    /** How long a queued capture counts as attempted before its run row exists. */
    public const QUEUED_MINUTES = 120;

    public function __construct(
        private readonly SectionPracticeSlotRepository $slots,
        private readonly PracticeSchedule $schedule,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly SyncRunRepository $syncRuns,
        private readonly UnitRepository $units,
        private readonly GameRepository $games,
        private readonly CaptureSources $sources,
    ) {}

    /**
     * Every sectioned slot's verdict at `$now`: `due` holds the captures to queue, `skipped` one line per section left out
     * and why (for `--dry-run`). With `$dryRun` no practice row is created and the `due` captures carry practice id 0
     * when the row does not exist yet.
     *
     * @return array{due: list<array{section: string, capture: CaptureSectionPractice}>, skipped: list<array{section: string, reason: string}>}
     */
    public function at(DateTimeInterface $now, bool $dryRun = false): array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $thisWeek = WeekOf::start($now);
        $due = [];
        $skipped = [];

        foreach ($this->slots->all() as $slot) {
            $name = $slot->section?->callsign ?? $slot->section?->name ?? "section {$slot->section_id}";
            $verdict = $this->verdict($slot, $now, $thisWeek, $dryRun);

            if (is_string($verdict)) {
                $skipped[] = ['section' => $name, 'reason' => $verdict];
            } else {
                $due[] = ['section' => $name, 'capture' => $verdict];
            }
        }

        return ['due' => $due, 'skipped' => $skipped];
    }

    /** Remember that a capture of the practice was queued, so the next tick leaves it be. */
    public function markQueued(int $sectionPracticeId): void
    {
        Cache::put(self::queuedKey($sectionPracticeId), true, now()->addMinutes(self::QUEUED_MINUTES));
    }

    private static function queuedKey(int $sectionPracticeId): string
    {
        return "practice-capture-queued:{$sectionPracticeId}";
    }

    /**
     * The capture to queue, or why the section is skipped. Two practices can end in this week: last week's, when it
     * started before the week turned and ended after, and this week's. The first one over and not yet done wins.
     */
    private function verdict(SectionPracticeSlot $slot, CarbonImmutable $now, CarbonImmutable $thisWeek, bool $dryRun): CaptureSectionPractice|string
    {
        $reason = 'practice cancelled this week';

        foreach ([WeekOf::previous($thisWeek), $thisWeek] as $weekStart) {
            $window = $this->schedule->windowFor($slot->section_id, $weekStart, slot: $slot);

            // Only a window that ends in this week: last week's ordinary practice was due last week.
            if ($window === null || $window->end <= $thisWeek) {
                continue;
            }

            $verdict = $this->verdictFor($slot, $weekStart, $window, $now, $dryRun);

            if ($verdict instanceof CaptureSectionPractice) {
                return $verdict;
            }

            $reason = $verdict;
        }

        return $reason;
    }

    private function verdictFor(SectionPracticeSlot $slot, CarbonImmutable $weekStart, PracticeWindow $window, CarbonImmutable $now, bool $dryRun): CaptureSectionPractice|string
    {
        if (! $window->hasEnded($now)) {
            return 'practice not over yet, it ends '.$window->end->format('D Hi').'Z';
        }

        $practice = $this->practices->find($slot->section_id, $weekStart);

        if ($practice?->captured_at !== null) {
            return 'already captured';
        }

        if ($practice !== null && ($this->syncRuns->existsForPractice('capture', $practice->id) || Cache::has(self::queuedKey($practice->id)))) {
            return 'already attempted';
        }

        if ($this->reports->isSentFor($slot->section_id, $weekStart)) {
            return 'week locked, its report was sent';
        }

        $played = $this->units->gameOf($slot->section_id);
        $game = $played === null ? null : $this->games->find($played->gameId);

        if ($game === null) {
            return 'no game set for the section or above it';
        }

        if (! $this->sources->isConfigured($game)) {
            return "no training server with credentials for {$game->name}";
        }

        $practiceId = $practice?->id ?? ($dryRun ? 0 : $this->practices->findOrCreate($slot->section_id, $weekStart)->id);

        return new CaptureSectionPractice($practiceId, $game->id, $window->start->toDateTimeImmutable(), $window->end->toDateTimeImmutable(), 'system');
    }
}
