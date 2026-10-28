<?php

namespace App\Commands\TrainingPlans;

use App\Authorization\ScopeResolver;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReportStatus;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The rules every training plan (#380) write and the plan page share, so the page offers exactly what the handlers
 * accept:
 *
 *  - **writing** a plan is the platoon's own chain of command (or an Admin), like its weekly topic;
 *  - **reviewing** it is the command of the company the platoon is in, battalion and regiment above it, or an Admin.
 *    A platoon leader cannot approve their own plan unless they also command the company;
 *  - a plan's month runs from the week floor up to a year ahead;
 *  - a week whose platoon report is sent, or that is before the week floor, is **locked**: its subject never changes
 *    again (AD-12), in the plan or on the reports.
 *
 * Both gates read real command authority (`ScopeResolver::commandsUnit`), never a staff grant, which only reads.
 */
class TrainingPlanRules
{
    /** How far ahead a plan may be written. */
    public const MONTHS_AHEAD = 12;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
    ) {}

    public function mayWrite(User $actor, Unit $platoon): bool
    {
        return $platoon->type === UnitType::Platoon && $this->scope->commandsUnit($actor, $platoon);
    }

    public function mayReview(User $actor, Unit $platoon): bool
    {
        if ($platoon->type !== UnitType::Platoon) {
            return false;
        }

        // The echelon rules put every line platoon under a company; one that somehow has none is left to an Admin.
        $company = $this->units->owningCompany($platoon);

        return $company === null ? $this->scope->isAdmin($actor) : $this->scope->commandsUnit($actor, $company);
    }

    /** Whether a plan may be written for `$month` (`YYYY-MM`): it has a week from the floor on, and is within a year. */
    public static function inRange(string $month): bool
    {
        $weeks = WeekOf::inMonth($month);
        $latest = CarbonImmutable::now('UTC')->startOfMonth()->addMonthsNoOverflow(self::MONTHS_AHEAD);

        return ! WeekOf::isBeforeFloor(end($weeks)) && CarbonImmutable::parse("{$month}-01", 'UTC')->lessThanOrEqualTo($latest);
    }

    /**
     * The weeks among `$weeks` whose subject can no longer change, keyed by `YYYY-MM-DD`: `floor` before the week
     * floor, `sent` once the platoon's report for it is sent (a demo report counts, as for the topic).
     *
     * @param  list<CarbonImmutable>  $weeks
     * @return array<string, 'floor'|'sent'>
     */
    public function lockedWeeks(Unit $platoon, array $weeks): array
    {
        $locked = [];
        foreach ($weeks as $week) {
            if (WeekOf::isBeforeFloor($week)) {
                $locked[$week->toDateString()] = 'floor';
            } elseif ($this->reports->find($platoon->id, $week)?->status === WeeklyReportStatus::Sent) {
                $locked[$week->toDateString()] = 'sent';
            }
        }

        return $locked;
    }
}
