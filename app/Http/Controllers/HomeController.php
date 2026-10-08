<?php

namespace App\Http\Controllers;

use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated landing (Story 1.4), now the weekly-cycle home (Story 1.15). Its only data so
 * far is the calendar: which Cav week's cycle is running and when its report falls due. Rollcall,
 * attendance and report figures join as their stories land.
 */
class HomeController extends Controller
{
    public function show(): Response
    {
        $now = CarbonImmutable::now();
        $weekStart = WeekOf::start($now);

        // A week's report is due at 23:59Z on the Saturday after it ends, which is day one of the
        // next Cav week. Until that deadline passes, the cycle still running is the week that just
        // closed, so the home page keeps showing it (with the deadline as "today") rather than a
        // fresh week that hides the report due in a few hours.
        if ($now->lessThan($weekStart->setTime(23, 59))) {
            $weekStart = $weekStart->subWeek();
        }

        return Inertia::render('Home', [
            'week' => [
                // Saturday 00:00 UTC (AD-11). The page draws Sat → Fri plus the due Saturday.
                'start' => $weekStart->toIso8601String(),
                'reportDue' => $weekStart->addWeek()->setTime(23, 59)->toIso8601String(),
            ],
        ]);
    }
}
