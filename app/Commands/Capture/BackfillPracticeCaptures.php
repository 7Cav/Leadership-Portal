<?php

namespace App\Commands\Capture;

use App\Commands\Practices\PracticeSchedule;
use App\Models\SectionPractice;
use App\Repositories\GameRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SectionPracticeSlotRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The past practices a backfill captures again over their slot's hour (#558): for each section with a slot, every
 * Cav week from `WeekOf::floor()` whose scheduled hour has ended, that is not cancelled, whose report was not sent
 * (AD-12) and whose section has a game a capture can read. The capture itself is the live one
 * ({@see CaptureSectionPracticeHandler}), so a backfill gives the same marks as a live run; `--dry-run` uses
 * {@see CaptureSectionPracticeHandler::preview()} and writes nothing, not even a practice row.
 */
class BackfillPracticeCaptures
{
    public function __construct(
        private readonly SectionPracticeSlotRepository $slots,
        private readonly PracticeSchedule $schedule,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly UnitRepository $units,
        private readonly GameRepository $games,
        private readonly CaptureSources $sources,
    ) {}

    /**
     * The captures to run at `$now`, oldest week first, and one line per section and week left out. With `$dryRun`
     * no practice row is created: a week without one carries practice id 0 and an unsaved practice for the preview.
     *
     * @return array{due: list<array{section: string, week: string, capture: CaptureSectionPractice, practice: SectionPractice}>, skipped: list<array{section: string, week: string, reason: string}>}
     */
    public function at(DateTimeInterface $now, bool $dryRun = false): array
    {
        $now = CarbonImmutable::instance($now)->utc();
        $due = [];
        $skipped = [];

        foreach ($this->slots->all() as $slot) {
            $name = $slot->section?->callsign ?? $slot->section?->name ?? "section {$slot->section_id}";
            $played = $this->units->gameOf($slot->section_id);
            $game = $played === null ? null : $this->games->find($played->gameId);

            for ($week = WeekOf::floor($now); $week <= $now; $week = WeekOf::next($week)) {
                $label = $week->toDateString();
                $practice = $this->practices->find($slot->section_id, $week);
                $window = $this->schedule->scheduledWindow($slot->section_id, $week, $practice, $slot);

                $reason = match (true) {
                    $window === null => 'practice cancelled that week',
                    ! $window->hasEnded($now) => 'practice not over yet',
                    $this->reports->isSentFor($slot->section_id, $week) => 'week locked, its report was sent',
                    $game === null => 'no game set for the section or above it',
                    ! $this->sources->isConfigured($game) => "no training server with credentials for {$game->name}",
                    default => null,
                };

                if ($reason !== null) {
                    $skipped[] = ['section' => $name, 'week' => $label, 'reason' => $reason];

                    continue;
                }

                $practice ??= $dryRun
                    ? (new SectionPractice)->forceFill(['id' => 0, 'section_id' => $slot->section_id, 'week_start' => $week])
                    : $this->practices->findOrCreate($slot->section_id, $week);
                $practiceId = (int) $practice->id;
                $due[] = [
                    'section' => $name,
                    'week' => $label,
                    'capture' => new CaptureSectionPractice($practiceId, $game->id, $window->start->toDateTimeImmutable(), $window->end->toDateTimeImmutable(), 'system'),
                    'practice' => $practice,
                ];
            }
        }

        return ['due' => $due, 'skipped' => $skipped];
    }
}
