<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\PageVisit;
use App\Models\User;
use App\Repositories\PageVisitRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The activity log (#382): which pages each person opened and when, newest first, so an Admin can retrace the steps
 * behind a bug report. Admin-only like the audit log (AD-6); read-only, so there is no Command. Reads
 * `PageVisitRepository` directly, as `AuditLogController` reads its repository.
 */
class ActivityLogController extends Controller
{
    public function __construct(
        private readonly PageVisitRepository $visits,
        private readonly ScopeResolver $scope,
    ) {}

    public function index(Request $request): Response
    {
        // `actsAsAdmin`, not the raw flag: an Admin previewing a leader sees what that leader would, no activity log.
        // An Admin acting as someone is that person on this request, and refused the same way.
        if (! $this->scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may review the activity log.');
        }

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            // `Y-m-d`, what the date inputs send; the looser `date` rule would take "now" and quietly match nothing.
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $visits = $this->visits->filtered(
            userId: isset($filters['user_id']) ? (int) $filters['user_id'] : null,
            dateFrom: $filters['date_from'] ?? null,
            dateTo: $filters['date_to'] ?? null,
        )->through(fn (PageVisit $visit) => [
            'id' => $visit->id,
            'user' => $visit->user->username,
            'url' => $visit->url,
            'actingAs' => $visit->acting_as,
            'viewingAs' => $visit->viewing_as,
            'visited_at' => $visit->visited_at->toIso8601String(),
        ])
            // Paging links carry the filters, or page 2 would silently be unfiltered.
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return Inertia::render('Settings/ActivityLog', [
            'visits' => $visits,
            'filters' => $filters,
            'userOptions' => $this->visits->users()->map(fn (User $user) => ['id' => $user->id, 'username' => $user->username])->values(),
            'retentionDays' => PageVisit::RETENTION_DAYS,
        ]);
    }
}
