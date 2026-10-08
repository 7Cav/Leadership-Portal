<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Repositories\AuditLogRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin audit-log review (Story 1.14, AD-16). Reads `AuditLogRepository` directly per the slim,
 * Laravel-idiomatic layer model (mirrors `StaffGrantsController`). Unlike `StaffGrants`, viewing
 * itself is Admin-only (AC #4) — there is no read/write split here, so the gate covers the whole
 * action.
 */
class AuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogRepository $auditLog,
        private readonly ScopeResolver $scope,
    ) {}

    public function index(Request $request): Response
    {
        // `actsAsAdmin`, not the raw flag: an Admin previewing a leader (view-as, Story 1.13)
        // sees what that leader would — no audit log.
        if (! $this->scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may review the audit log.');
        }

        $filters = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:255'],
            'target_type' => ['nullable', 'string', 'max:255'],
            'target_id' => ['nullable', 'string', 'max:255'],
            // `date_format:Y-m-d`, not the looser `date` rule — `date` accepts anything
            // `strtotime()` parses (e.g. "now", "+1 day"), which `AuditLogRepository::filtered()`
            // would then hand straight to the query, silently returning an empty result instead
            // of failing loudly. `Y-m-d` matches what the `<input type="date">` controls emit.
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $entries = $this->auditLog->filtered(
            actorId: $filters['actor_id'] ?? null,
            action: $filters['action'] ?? null,
            targetType: $filters['target_type'] ?? null,
            targetId: $filters['target_id'] ?? null,
            dateFrom: $filters['date_from'] ?? null,
            dateTo: $filters['date_to'] ?? null,
        )->through(fn (AuditLogEntry $entry) => [
            'id' => $entry->id,
            'actor' => $entry->actor->username,
            'action' => $entry->action,
            'target_type' => $entry->target_type,
            'target_id' => $entry->target_id,
            'reason' => $entry->reason,
            'content_digest' => $entry->content_digest,
            'created_at' => $entry->created_at->toIso8601String(),
        ])
            // Without this, `next_page_url`/`prev_page_url` carry no query string (Laravel only
            // appends one that's been set explicitly), so paging silently drops every filter and
            // the Admin ends up looking at an unfiltered page while the form still shows the
            // filters they set.
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return Inertia::render('Settings/AuditLog', [
            'entries' => $entries,
            'filters' => $filters,
            'actorOptions' => $this->auditLog->actors()->map(fn ($user) => [
                'id' => $user->id,
                'username' => $user->username,
            ])->values(),
            'actionOptions' => $this->auditLog->actions()->values(),
        ]);
    }
}
