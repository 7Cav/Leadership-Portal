<?php

namespace App\Repositories;

use App\Models\AuditLogEntry;

/**
 * Which audit entries belong to each earlier version of a sent report (`revisions`, oldest first). Each version was
 * replaced by exactly one send, audited in the same transaction that appended it (`weekly_report.resent`,
 * `staff_report.edited`), so the n-th such entry replaced the n-th revision. A weekly report is reopened first
 * (`weekly_report.reopened`): the first reopen since the version before was replaced is the one that opened it; a
 * second reopen while it was open changed nothing.
 */
final class ReportRevisionTrail
{
    /**
     * @param  list<array<string, mixed>>  $revisions
     * @param  iterable<AuditLogEntry>  $entries  oldest first ({@see AuditLogRepository::forTarget()})
     * @return list<array{reopened: ?AuditLogEntry, replaced: ?AuditLogEntry}> one per revision, in its order
     */
    public static function pair(array $revisions, iterable $entries, string $replacedAction, ?string $reopenedAction = null): array
    {
        $trail = array_fill(0, count($revisions), ['reopened' => null, 'replaced' => null]);
        $index = 0;
        $reopened = null;

        foreach ($entries as $entry) {
            if ($reopenedAction !== null && $entry->action === $reopenedAction) {
                $reopened ??= $entry;
            } elseif ($entry->action === $replacedAction) {
                if ($index < count($trail)) {
                    $trail[$index] = ['reopened' => $reopened, 'replaced' => $entry];
                }
                $index++;
                $reopened = null;
            }
        }

        return $trail;
    }
}
