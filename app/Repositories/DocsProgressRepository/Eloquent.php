<?php

namespace App\Repositories\DocsProgressRepository;

use App\Models\AuditLogEntry;
use App\Models\PageVisit;
use App\Models\SectionPracticeSlot;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Repositories\DocsProgressRepository;

class Eloquent implements DocsProgressRepository
{
    /** The audit actions that count, and the fact each one proves. */
    private const ACTIONS = [
        'attendance.marked' => self::ROLLCALL,
        'practice.confirmed' => self::ROLLCALL,
        'rollcall.posted' => self::ROLLCALL,
        'practice_slot.set' => self::PRACTICE_SLOT,
        'game_identity.linked' => self::GAME_IDENTITY,
        'weekly_report.sent' => self::WEEKLY_REPORT,
        'staff_report.sent' => self::STAFF_REPORT,
    ];

    public function factsFor(?int $userId, ?int $cavId, ?int $sectionId): array
    {
        $facts = [];

        if ($userId !== null) {
            $actions = AuditLogEntry::query()
                ->whereIn('action', array_keys(self::ACTIONS))
                ->where(fn ($query) => $query
                    ->where(fn ($own) => $own->where('actor_id', $userId)->whereNull('on_behalf_of_id'))
                    ->orWhere('on_behalf_of_id', $userId))
                ->distinct()
                ->pluck('action');
            foreach ($actions as $action) {
                $facts[] = self::ACTIONS[$action];
            }

            if (PageVisit::query()->where('user_id', $userId)->where(fn ($query) => $query->where('url', '/roster')->orWhere('url', 'like', '/roster/%')->orWhere('url', 'like', '/roster?%'))->exists()) {
                $facts[] = self::ROSTER;
            }
        }

        if ($sectionId !== null && ! in_array(self::PRACTICE_SLOT, $facts, true) && SectionPracticeSlot::query()->where('section_id', $sectionId)->exists()) {
            $facts[] = self::PRACTICE_SLOT;
        }

        if ($cavId !== null && ! in_array(self::STAFF_REPORT, $facts, true)
            && StaffReport::query()->whereIn('trooper_id', Trooper::query()->select('id')->where('cav_id', $cavId))->exists()) {
            $facts[] = self::STAFF_REPORT;
        }

        return array_values(array_unique($facts));
    }
}
