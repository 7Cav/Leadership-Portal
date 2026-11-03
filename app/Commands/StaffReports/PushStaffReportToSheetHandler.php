<?php

namespace App\Commands\StaffReports;

use App\Integration\GoogleSheets\GoogleSheetsUnavailable;
use App\Integration\GoogleSheets\SheetsClient;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\StaffReportRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;

/**
 * Append one sent staff report to the reports Google Sheet ({@see StaffReportSheetRow}) and note when it went
 * (`sheet_pushed_at`) and where (`sheet_range`). Platoon and company HQ staff go to the staff tab, battalion HQ staff to
 * the battalion tab. An edited report (its `sheet_pushed_at` cleared by the edit) overwrites the row it went to before
 * rather than adding another; one whose earlier push did not say where its row went is appended.
 * Nothing happens when the sheet is not configured, when the report is gone, or when it was already pushed, so a retry
 * never adds a second row after a success it saw. A failure is rethrown for the queue to retry; the send it came from
 * stands either way.
 *
 * The timestamp is the push's own bookkeeping, like the counseling Drive copy's status, not a personnel action: it is
 * not audited (AD-16's carve-out for system jobs). The send is.
 */
class PushStaffReportToSheetHandler
{
    public function __construct(
        private readonly StaffReportRepository $reports,
        private readonly TrooperRepository $troopers,
        private readonly UnitRepository $units,
        private readonly SheetsClient $sheet,
    ) {}

    /** Whether the reports sheet is set up: pages say "Not sent to the sheet yet" only then. */
    public function sheetConfigured(): bool
    {
        return $this->sheet->isConfigured();
    }

    /**
     * Returns whether a row was appended or overwritten.
     *
     * @throws GoogleSheetsUnavailable when the sheet did not take the row
     */
    public function handle(PushStaffReportToSheet $command): bool
    {
        if (! $this->sheet->isConfigured()) {
            return false;
        }

        $report = $this->reports->findById($command->reportId);

        if ($report === null || $report->sheet_pushed_at !== null) {
            return false;
        }

        $trooper = $this->troopers->find($report->trooper_id);
        $units = $this->units->tree()->keyBy('id');
        $hq = $report->unit_id === null ? null : $units->get($report->unit_id);

        // An HQ deleted since the send leaves no tab to pick: there is nothing sound to write.
        if ($trooper === null || ! $hq instanceof Unit) {
            return false;
        }

        [$tab, $row] = $hq->type === UnitType::Battalion
            ? [$this->sheet->battalionTab(), StaffReportSheetRow::battalion($report, $trooper, $hq)]
            : [$this->sheet->staffTab(), StaffReportSheetRow::staff($report, $trooper, $hq, $units)];

        // Edited after an earlier push: its row is overwritten where it is, so the sheet keeps one row per week.
        if ($report->sheet_range !== null) {
            $this->sheet->update($report->sheet_range, $row);
            $this->reports->markPushed($report->id);

            return true;
        }

        $this->reports->markPushed($report->id, $this->sheet->append($tab, $row));

        return true;
    }
}
