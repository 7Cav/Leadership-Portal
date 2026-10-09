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
        // The week whose report cycle is running: the closing week stays current until its report
        // deadline passes, so the deadline counted down is never one that already went by.
        $weekStart = WeekOf::reporting(CarbonImmutable::now());

        return Inertia::render('Home', [
            'week' => [
                // Saturday 00:00 UTC (AD-11). The page draws Sat → Fri plus the due Saturday.
                'start' => $weekStart->toIso8601String(),
                'reportDue' => WeekOf::reportDue($weekStart)->toIso8601String(),
            ],
        ]);
    }
}
