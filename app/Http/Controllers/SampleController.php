<?php

namespace App\Http\Controllers;

use App\ReadServices\SampleReadService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Throwaway controller proving the Controller -> Inertia::render -> Vue wire (Story 1.1, AC-2),
 * refactored layer-legal in Story 1.2: props are shaped by a ReadService (AD-15/AD-20), so the
 * controller only orchestrates and holds no business logic. Removed once a real page lands.
 */
class SampleController extends Controller
{
    public function show(SampleReadService $readService): Response
    {
        return Inertia::render('Sample', $readService->props());
    }
}
