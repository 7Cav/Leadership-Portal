<?php

namespace App\Milpac;

use App\Models\CrossSectionSighting;
use App\Models\PracticeCapturePlayer;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\StaffReportRepository;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use Carbon\CarbonImmutable;

/**
 * What one staff member's week looks like on their staff report page and on their HQ's report (policy, 2026-10-29):
 * the report as sent, the staff practice entries already there for the week (what the picker starts from), and the
 * practices the game server saw them at (shown as a hint, never ticked for them). Read only: who may see or send it is
 * the controller's to say, through `StaffReportAccess`.
 */
class StaffReportWeek
{
    public function __construct(
        private readonly StaffReportRepository $reports,
        private readonly CrossSectionSightingRepository $sightings,
        private readonly PracticeCapturePlayerRepository $captures,
        private readonly TrooperPlacementRepository $placements,
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * The sent report, shaped for the page, or null while it is not sent. Each practice says whether it was in the
     * staff member's own unit (`own`), which the host's rollcall lists as a staff guest.
     *
     * @return array{sentAt: string, attended: bool, practices: list<array{sectionId: int, section: ?string}>}|null
     */
    public function report(Trooper $trooper, CarbonImmutable $weekStart): ?array
    {
        $report = $this->reports->find($trooper->id, $weekStart);

        return $report === null ? null : self::reportProps($report);
    }

    /**
     * @return array{sentAt: string, attended: bool, practices: list<array{sectionId: int, section: ?string}>}
     */
    public static function reportProps(StaffReport $report): array
    {
        return [
            'sentAt' => $report->sent_at->toIso8601String(),
            'attended' => $report->attended,
            'practices' => array_map(fn (array $row) => [
                'sectionId' => (int) $row['section_id'],
                'section' => $row['section'] ?? null,
            ], $report->practices ?? []),
        ];
    }

    /**
     * The staff practice entries already there for the week: the picker ticks them, and says who entered each.
     *
     * @return list<array{sectionId: int, section: ?string, enteredBy: ?string, selfReported: bool}>
     */
    public function entries(Trooper $trooper, CarbonImmutable $weekStart): array
    {
        return $this->sightings->staffEntriesInWeek($trooper->id, $weekStart)
            ->map(fn (CrossSectionSighting $row) => [
                'sectionId' => $row->practice->section_id,
                'section' => $row->practice->section === null ? null : ($row->practice->section->callsign ?? $row->practice->section->name),
                'enteredBy' => match (true) {
                    $row->recordedByAdmin !== null => "{$row->recordedByAdmin->username} for {$row->recordedBy?->username}",
                    $row->recordedAsUnit !== null => "{$row->recordedBy?->username} acting as {$row->recordedAsUnit->billet()}",
                    default => $row->recordedBy?->username,
                },
                'selfReported' => $row->recordedByAdmin === null && $row->recordedBy?->cav_id !== null
                    && (int) $row->recordedBy->cav_id === (int) $trooper->cav_id,
            ])
            ->values()
            ->all();
    }

    /**
     * The sections whose practice capture saw the staff member that week through a linked game identity. A hint only:
     * the capture reads the whole Cav week so far, so it says they were on the server, not at that practice.
     *
     * @return list<int>
     */
    public function seenSectionIds(Trooper $trooper, CarbonImmutable $weekStart): array
    {
        return $this->captures->seenTrooper($trooper->id)
            ->filter(fn (PracticeCapturePlayer $row) => $row->practice->week_start->equalTo($weekStart))
            ->map(fn (PracticeCapturePlayer $row) => (int) $row->practice->section_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The staff placed on `$hq` when the week ended, each with their sent report (or null), by display name: what the
     * HQ's weekly report shows about its staff.
     *
     * @return list<array{trooper: Trooper, report: ?StaffReport}>
     */
    public function hqStaff(Unit $hq, CarbonImmutable $weekStart): array
    {
        $ids = $this->placements->trooperIdsInUnitAtWeekEnd($hq->id, $weekStart);
        $sent = $this->reports->forTroopers($ids, $weekStart);

        return $this->troopers->byIds($ids)
            ->filter(fn (Trooper $trooper) => $trooper->status->isServing())
            ->sortBy(fn (Trooper $trooper) => mb_strtolower($trooper->display_name))
            ->map(fn (Trooper $trooper) => ['trooper' => $trooper, 'report' => $sent->get($trooper->id)])
            ->values()
            ->all();
    }
}
