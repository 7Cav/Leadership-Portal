<?php

namespace App\Http\Controllers;

use App\Kpi\StrengthTrends;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The old strength trends address. Strength is a tab on the roster now, so this sends a link or bookmark there with the
 * tab and its range selected. The roster does the scope check (AD-8), so a unit outside scope is still a 403.
 */
class StrengthTrendsController extends Controller
{
    public function show(Request $request, int $unit): RedirectResponse
    {
        $weeks = StrengthTrends::weeksFor($request->query('range'));
        $query = http_build_query(array_filter(['view' => 'strength', 'range' => $weeks === null ? 'all' : ($weeks === 12 ? null : (string) $weeks)]));

        return redirect("/roster/{$unit}?{$query}");
    }
}
