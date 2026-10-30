<?php

namespace App\Milpac;

use App\Models\CrossSectionSighting;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\StaffReportRepository;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use Carbon\CarbonImmutable;

/**
 * What one staff member's week looks like on their staff report page and on their HQ's report:
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
        private readonly SectionPracticeRepository $sectionPractices,
    ) {}

    /**
     * The sent report, shaped for the page, or null while it is not sent. Each practice says whether it was in the
     * staff member's own unit (`own`), which the host's rollcall lists as a staff guest.
     *
     * @return array<string, mixed>|null
     */
    public function report(Trooper $trooper, CarbonImmutable $weekStart): ?array
    {
        $report = $this->reports->find($trooper->id, $weekStart);

        return $report === null ? null : self::reportProps($report);
    }

    /**
     * The report as sent. `practiceType` is `sp`, `ftx` or `office_hour` (a report sent before the type was asked reads as an SP when
     * they attended); `ftx` holds the FTX's name and date, `officeHour` the office hour's date and note; `sheetPushedAt` is when the row went to the reports sheet; `presence` is the game server check frozen at send
     * (`check` `conflict`, `confirmed` or `no_data`, `source` the system's name, "CRCON" for HLL), null when none was made.
     *
     * @return array{sentAt: string, attended: bool, practiceType: ?string, ftx: ?array{name: ?string, date: ?string}, officeHour: ?array{date: ?string, note: ?string}, otherEvents: ?string, notes: ?string, sheetPushedAt: ?string, presence: ?array{check: string, source: string}, practices: list<array{sectionId: int, section: ?string}>}
     */
    public static function reportProps(StaffReport $report): array
    {
        $type = $report->practice_type ?? ($report->attended ? StaffReport::SP : null);

        return [
            'sentAt' => $report->sent_at->toIso8601String(),
            'attended' => $report->attended,
            'practiceType' => $type,
            'ftx' => $type === StaffReport::FTX ? ['name' => $report->ftx_name, 'date' => $report->ftx_date?->toDateString()] : null,
            'officeHour' => $type === StaffReport::OFFICE_HOUR ? ['date' => $report->office_hour_date?->toDateString(), 'note' => $report->office_hour_note] : null,
            'otherEvents' => $report->other_events,
            'notes' => $report->notes,
            'sheetPushedAt' => $report->sheet_pushed_at?->toIso8601String(),
            'presence' => $report->presence_check === null ? null : ['check' => $report->presence_check, 'source' => $report->presence_source ?? 'game server'],
            'practices' => array_map(fn (array $row) => [
                'sectionId' => (int) $row['section_id'],
                'section' => $row['section'] ?? null,
            ], $report->practices ?? []),
        ];
    }

    /** The trooper's sent report row for the week, or null. */
    public function find(Trooper $trooper, CarbonImmutable $weekStart): ?StaffReport
    {
        return $this->reports->find($trooper->id, $weekStart);
    }

    /**
     * Whether a sent report keeps to the rule of one section practice a week: "Yes" with a practice picked, "Yes"
     * to an FTX, whose sections are optional, or a battalion HQ staff member's office hour.
     *
     * @param  array{attended: bool, practiceType: ?string, practices: list<mixed>}  $props  from {@see reportProps()}
     */
    public static function metRule(array $props): bool
    {
        return $props['attended'] && ($props['practices'] !== []
            || in_array($props['practiceType'], [StaffReport::FTX, StaffReport::OFFICE_HOUR], true));
    }

    /**
     * Where the game server disagrees with what each sent report says, for readers only (the controllers never pass
     * the viewer's own): trooper id => `{notSeen, seenElsewhere}`, each a list of section labels, or no key when the
     * two agree. `notSeen`: a section whose practice they reported attending was captured that week and the capture
     * did not see them. `seenElsewhere`: a captured practice that week saw them, through a linked game identity, at
     * a section they did not report (all of them when they answered No). A practice with no capture says nothing, and
     * an FTX or an office hour is never compared: neither is captured. Two queries whatever the number of reports.
     *
     * @param  iterable<StaffReport>  $reports  sent reports of one week
     * @return array<int, array{notSeen: list<string>, seenElsewhere: list<string>}>
     */
    public function discrepancies(iterable $reports, CarbonImmutable $weekStart): array
    {
        $compared = collect($reports)->filter(fn (StaffReport $report) => ! in_array(
            $report->practice_type ?? ($report->attended ? StaffReport::SP : null),
            [StaffReport::FTX, StaffReport::OFFICE_HOUR],
            true,
        ))->keyBy('trooper_id');

        if ($compared->isEmpty()) {
            return [];
        }

        $reportedIds = $compared->flatMap(fn (StaffReport $report) => array_map(fn (array $row) => (int) $row['section_id'], $report->practices ?? []))
            ->unique()->values()->all();
        $captured = $this->sectionPractices->forSections($reportedIds, $weekStart)
            ->filter(fn (SectionPractice $practice) => $practice->captured_at !== null);
        $seen = $this->captures->seenInWeek($compared->keys()->map(fn ($id) => (int) $id)->all(), $weekStart);

        $out = [];
        foreach ($compared as $trooperId => $report) {
            $reported = collect($report->practices ?? [])->mapWithKeys(fn (array $row) => [(int) $row['section_id'] => $row['section'] ?? null]);
            $seenHere = $seen[(int) $trooperId] ?? [];

            $notSeen = $reported->filter(fn (?string $label, int $sectionId) => $captured->has($sectionId) && ! isset($seenHere[$sectionId]))
                ->map(fn (?string $label) => $label ?? 'a section no longer in the portal')->values()->all();
            $seenElsewhere = array_values(array_diff_key($seenHere, $reported->all()));

            if ($notSeen !== [] || $seenElsewhere !== []) {
                $out[(int) $trooperId] = ['notSeen' => $notSeen, 'seenElsewhere' => $seenElsewhere];
            }
        }

        return $out;
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
     * The staff placed on `$hq` when the week ended, each with their sent report (or null), by username: what the
     * HQ's weekly report shows about its staff. Someone who has left since is kept when they were still serving as the
     * week ended (gone with a `departed_at` from then on), and always when they sent a report for it.
     *
     * @return list<array{trooper: Trooper, report: ?StaffReport}>
     */
    public function hqStaff(Unit $hq, CarbonImmutable $weekStart): array
    {
        return $this->staffOfHqs([$hq->id], $weekStart)[$hq->id];
    }

    /**
     * {@see hqStaff()} for many HQs at once, in a fixed number of queries whatever their count: HQ id => its staff, for
     * every HQ given. What the staff reports overview reads.
     *
     * @param  list<int>  $hqIds
     * @return array<int, list<array{trooper: Trooper, report: ?StaffReport}>>
     */
    public function staffOfHqs(array $hqIds, CarbonImmutable $weekStart): array
    {
        $idsByHq = $this->placements->trooperIdsInUnitsAtWeekEnd($hqIds, $weekStart);
        $ids = array_values(array_unique(array_merge([], ...array_values($idsByHq))));
        $sent = $this->reports->forTroopers($ids, $weekStart);
        $troopers = $this->troopers->byIds($ids);
        // The Saturday the week ends, when its roster is read.
        $end = $weekStart->addWeek();

        return array_map(fn (array $hqTroopers) => $troopers->only($hqTroopers)
            ->filter(fn (Trooper $trooper) => $sent->has($trooper->id) || $trooper->status->isServing()
                || ($trooper->departed_at !== null && $trooper->departed_at->greaterThanOrEqualTo($end)))
            ->sortBy(fn (Trooper $trooper) => [mb_strtolower((string) $trooper->username), $trooper->id])
            ->map(fn (Trooper $trooper) => ['trooper' => $trooper, 'report' => $sent->get($trooper->id)])
            ->values()
            ->all(), $idsByHq);
    }
}
