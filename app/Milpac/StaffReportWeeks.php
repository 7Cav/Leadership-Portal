<?php

namespace App\Milpac;

use App\Models\StaffReport;
use App\Repositories\StaffReportRepository;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The roster's weeks for line HQ staff (a platoon, company or battalion HQ node): they are on no section's roll call,
 * so their accountability for a week is their staff report, judged by the one rule the staff report pages use
 * ({@see StaffReportWeek::metRule()}). Read only; which weeks they were on an HQ, on leave and on a roll call is the
 * caller's to say, and so is who may read a report in full.
 *
 * A week's `state`: `met` when the sent report keeps to the rule, `short` when it was sent and does not, `notSent` for a
 * finished week with nothing sent, `open` for the running week while nothing is sent, `before` for a week before staff
 * reports could be filed with nothing sent, and `loa` for a week leave covers, whatever was sent. A `before` week
 * never counts in the summary. One query for the reports, however many troopers and weeks.
 */
class StaffReportWeeks
{
    public const MET = 'met';

    public const SHORT = 'short';

    public const NOT_SENT = 'notSent';

    public const OPEN = 'open';

    public const LOA = 'loa';

    public const BEFORE = 'before';

    public function __construct(private readonly StaffReportRepository $reports) {}

    /**
     * Each trooper's weeks, aligned to `$weeks` (oldest first, the last one running): null for a week they were not on
     * an HQ, else `{state, hq, sent, report}`. `report` is what they answered (`attended`, `practiceType`, `ftx`,
     * `officeHour`, `presenceType` and the `practices` by label), only where `$reads` says the viewer may read it.
     * `reports` sums the `$window` finished weeks before the running one: how many were `due` (met, short or not
     * sent) and how many `met`; null when none was due.
     *
     * @param  array<int, array<string, int>>  $hqWeeks  trooper id => week start => the HQ unit id they were on, only
     *                                                   the weeks they were on no section's roll call
     * @param  array<int, array<string, bool>>  $onLeave  trooper id => week start => covered by leave
     * @param  list<string>  $weeks
     * @param  array<int, string>  $hqLabels  HQ unit id => its label
     * @param  Closure(int, int): bool  $reads  whether the viewer reads trooper `$1`'s report from HQ `$2`
     * @param  string|null  $firstWeek  the first week start (Y-m-d) a staff report could be filed for
     * @return array<int, array{weeks: list<?array<string, mixed>>, reports: ?array{met: int, due: int, weeks: int}}>
     */
    public function forWeeks(array $hqWeeks, array $onLeave, array $weeks, array $hqLabels, Closure $reads, int $window, ?string $firstWeek = null): array
    {
        if ($hqWeeks === [] || $weeks === []) {
            return [];
        }

        $sent = $this->reports->forTroopersBetween(
            array_keys($hqWeeks),
            CarbonImmutable::parse($weeks[0], 'UTC'),
            CarbonImmutable::parse($weeks[array_key_last($weeks)], 'UTC'),
        )->groupBy('trooper_id')->map(fn ($rows) => $rows->keyBy(fn (StaffReport $row) => $row->week_start->toDateString()));

        $running = $weeks[array_key_last($weeks)];
        $counted = array_slice($weeks, max(0, count($weeks) - 1 - $window), min($window, count($weeks) - 1));
        $out = [];

        foreach ($hqWeeks as $trooperId => $byWeek) {
            $entries = array_map(function (string $week) use ($trooperId, $byWeek, $onLeave, $sent, $running, $hqLabels, $reads, $firstWeek) {
                $hqId = $byWeek[$week] ?? null;

                if ($hqId === null) {
                    return null;
                }

                $report = $sent->get($trooperId)?->get($week);
                $props = $report === null ? null : StaffReportWeek::reportProps($report);

                return [
                    'state' => match (true) {
                        $onLeave[$trooperId][$week] ?? false => self::LOA,
                        $props !== null => StaffReportWeek::metRule($props) ? self::MET : self::SHORT,
                        $firstWeek !== null && $week < $firstWeek => self::BEFORE,
                        $week === $running => self::OPEN,
                        default => self::NOT_SENT,
                    },
                    'hq' => $hqLabels[$hqId] ?? null,
                    'sent' => $props !== null,
                    'report' => $props === null || ! $reads((int) $trooperId, $hqId) ? null : self::answer($props),
                ];
            }, $weeks);

            $byDate = array_combine($weeks, $entries);
            $due = array_filter(array_map(fn (string $week) => $byDate[$week]['state'] ?? null, $counted),
                fn (?string $state) => in_array($state, [self::MET, self::SHORT, self::NOT_SENT], true));
            $met = count(array_filter($due, fn (string $state) => $state === self::MET));

            $out[(int) $trooperId] = [
                'weeks' => $entries,
                'reports' => $due === [] ? null : ['met' => $met, 'due' => count($due), 'weeks' => count($counted)],
            ];
        }

        return $out;
    }

    /**
     * What a report answered, as the roster's tooltip reads it: the staff report pages' own fields, the practices by
     * label. Notes, other events and the presence checks stay on the report page.
     *
     * @param  array<string, mixed>  $props  from {@see StaffReportWeek::reportProps()}
     * @return array<string, mixed>
     */
    private static function answer(array $props): array
    {
        return [
            'attended' => $props['attended'],
            'practiceType' => $props['practiceType'],
            'ftx' => $props['ftx'],
            'officeHour' => $props['officeHour'],
            'presenceType' => $props['presenceType'],
            'practices' => array_map(fn (array $practice) => $practice['section'] ?? 'A section no longer in the portal', $props['practices']),
        ];
    }
}
