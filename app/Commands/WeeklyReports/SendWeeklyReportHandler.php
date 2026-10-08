<?php

namespace App\Commands\WeeklyReports;

use App\Authorization\ScopeResolver;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\WeeklyReportKpis;
use App\Models\User;
use App\Models\WeeklyReportStatus;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * Freezes a unit-week's weekly report (AD-12): the six KPIs and their delta as the unit's
 * {@see WeeklyReportKpis} snapshot computes them now, the leader's notes, and the template
 * version, written through `WeeklyReportRepository::send()` — the table's one writer of sent rows
 * (AD-22) — as one audited, reason-required transaction (AD-16). Purely internal: no external
 * system is called (AD-10).
 *
 * Refused before anything is written: a unit outside the actor's scope (AD-8), a blank reason,
 * and a unit whose KPIs cannot be reported yet (a non-leaf until 5.7's roll-up lands).
 *
 * **Re-send is a clean no-op.** A report already sent for the unit-week is returned as it was
 * frozen, with `alreadySent` set; nothing is rewritten and no second audit entry is recorded —
 * the original send's entry and `content_digest` stay the record. A concurrent send ends the same
 * way: the repository serializes writers of one unit-week on its UNIQUE row, the loser finds the
 * row sent and throws `WeeklyReportAlreadySentException`, which rolls back its audit entry with
 * it, and this handler answers with the winner's frozen report rather than an error.
 *
 * The template version (AD-17) is frozen as null: template versioning (3.5) is deferred for the
 * MVP. When it lands, resolve the active version here and pass its id to `send()`.
 */
class SendWeeklyReportHandler
{
    public const ACTION = 'weekly_report.sent';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly WeeklyReportKpis $snapshots,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws SendWeeklyReportForbiddenException when the unit is outside the actor's scope.
     * @throws ReasonRequiredException when no reason is given — on a re-send too.
     * @throws ReportKpisUnavailableException when the unit's KPIs cannot be reported yet.
     */
    public function handle(User $actor, SendWeeklyReport $command): SentWeeklyReport
    {
        $unit = $this->scope->resolve($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

        if ($unit === null) {
            throw new SendWeeklyReportForbiddenException($command->unitId);
        }

        // Checked here as well as by the envelope, so a reasonless re-send is refused too rather
        // than answered with the no-op below.
        if (blank($command->reason)) {
            throw ReasonRequiredException::forAction(self::ACTION);
        }

        $weekStart = WeekOf::start($command->week);
        $existing = $this->reports->find($unit->id, $weekStart);

        if ($existing?->status === WeeklyReportStatus::Sent) {
            return new SentWeeklyReport($existing, alreadySent: true);
        }

        $kpis = $this->snapshots->snapshot($unit, $weekStart)->toArray();
        $templateVersionId = null;

        try {
            $report = $this->audit->record(
                actorId: $actor->id,
                action: self::ACTION,
                reason: $command->reason,
                reasonRequired: true,
                mutation: fn () => $this->reports->send(
                    $unit->id,
                    $weekStart,
                    $kpis,
                    $command->notes,
                    $actor->id,
                    self::contentDigest($unit->id, $weekStart, $kpis, $command->notes, $templateVersionId),
                    $templateVersionId,
                ),
            );
        } catch (WeeklyReportAlreadySentException $raced) {
            return new SentWeeklyReport($this->reports->find($unit->id, $weekStart) ?? throw $raced, alreadySent: true);
        }

        return new SentWeeklyReport($report, alreadySent: false);
    }

    /**
     * SHA-256 over exactly what is frozen — the unit-week key, the KPI snapshot, the notes and the
     * template version — so the stored digest proves the snapshot has not changed since the send.
     *
     * @param  array<string, mixed>  $kpis
     */
    private static function contentDigest(int $unitId, CarbonImmutable $weekStart, array $kpis, ?string $notes, ?int $templateVersionId): string
    {
        return hash('sha256', json_encode([
            'unit_id' => $unitId,
            'week_start' => $weekStart->toIso8601String(),
            'kpis' => $kpis,
            'notes' => $notes,
            'template_version_id' => $templateVersionId,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
