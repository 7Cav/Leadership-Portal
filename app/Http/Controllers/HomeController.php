<?php

namespace App\Http\Controllers;

use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The authenticated landing (Story 1.4), now the weekly-cycle home (Story 1.15). Its only data so
 * far is the calendar: which Cav week is running and when its report falls due. Rollcall,
 * attendance and report figures join as their stories land.
 */
class HomeController extends Controller
{
    public function show(): Response
    {
        $weekStart = WeekOf::start(CarbonImmutable::now());

        return Inertia::render('Home', [
            'week' => [
                // Saturday 00:00 UTC (AD-11). The page draws Sat → Fri plus the due Saturday.
                'start' => $weekStart->toIso8601String(),
                // The week's report is due by the end of the Saturday that closes it, the
                // Saturday 23:59Z the weekly-cycle mockup counts down to.
                'reportDue' => $weekStart->addWeek()->setTime(23, 59)->toIso8601String(),
            ],
        ]);
    }
}
