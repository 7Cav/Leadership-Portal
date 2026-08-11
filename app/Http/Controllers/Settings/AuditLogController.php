<?php

namespace App\Http\Controllers\Settings;

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
    public function __construct(private readonly AuditLogRepository $auditLog) {}

    public function index(Request $request): Response
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may review the audit log.');
        }

        $filters = $request->validate([
            'actor_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string'],
            'target_type' => ['nullable', 'string'],
            'target_id' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
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
        ]);

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
