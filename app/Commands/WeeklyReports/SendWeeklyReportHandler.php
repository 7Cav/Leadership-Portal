<?php

namespace App\Commands\WeeklyReports;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Kpi\KpiService;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\TrooperAttendance;
use App\Kpi\WeeklyReportKpis;
use App\Models\AttendanceStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReportStatus;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Freezes a unit-week's weekly report (AD-12): the six KPIs and their delta as the unit's
 * {@see WeeklyReportKpis} snapshot computes them now (for a roll-up, with the children it went
 * without), the leader's notes, training subject and unaccounted reasons, and the template
 * version, written through `WeeklyReportRepository::send()` — the table's one writer of sent rows
 * (AD-22) — as one audited, reason-required transaction (AD-16). Purely internal: no external
 * system is called (AD-10).
 *
 * Refused before anything is written: a unit outside the actor's scope (AD-8), a blank reason,
 * content that is not the unit's to record, a training subject other than the one the platoon
 * enforces for the week, and a unit whose KPIs cannot be reported (a non-leaf with no children) —
 * that last one inside the transaction, since the snapshot is computed under the report-row lock,
 * so it rolls back with nothing left behind. The frozen subject is {@see SectionTrainingSubject::forSend()}: the enforced topic when there is
 * one. A roll-up whose children have not all sent **is** sent: their ids are frozen
 * as `missing_children` and never substituted with live numbers (5.7, AD-13).
 *
 * **Re-send is a clean no-op.** A report already sent for the unit-week is returned as it was
 * frozen, with `alreadySent` set; nothing is rewritten and no second audit entry is recorded —
 * the original send's entry and `content_digest` stay the record. A concurrent send ends the same
 * way: the repository serializes writers of one unit-week on its UNIQUE row, the loser finds the
 * row sent and throws `WeeklyReportAlreadySentException` from inside the envelope's mutation —
 * before any audit entry is written — so its transaction rolls back, and this handler answers with
 * the winner's frozen report rather than an error.
 *
 * Call it outside an open transaction: the race path re-reads the winner's row with a plain read,
 * which inside a caller's REPEATABLE READ transaction could see a stale snapshot. If that read
 * does not come back sent, the original `WeeklyReportAlreadySentException` is rethrown rather than
 * a draft being passed off as the sent report.
 *
 * The template version (AD-17) is frozen as null. `template_version_id` records the report-layout
 * template a report was rendered from, and no such template exists yet: the only `ArtifactType` is
 * the rollcall BBCode, which the weekly report does not use, so freezing its id here would record a
 * template the report never rendered from. When a report-layout type lands, resolve the unit's
 * version through `TemplateResolver` (the owning company's active version) and pass its id to
 * `send()`; `contentDigest()` already covers it.
 *
 * **Make-ups still owed.** A section's send must answer, once each, for every trooper who asked for a make-up on the
 * week's roll call and has none recorded ({@see KpiService::owesMakeup()}): did they make it up or not. Anything else
 * is refused with {@see MakeupChecksIncompleteException} before a write. A "did" is recorded as Make-up complete
 * through {@see MarkAttendanceHandler} (its own audited write, by the sender) before the snapshot is taken, so the
 * frozen numbers count it; a "did not" leaves the mark as it is. Both answers are frozen with the report as
 * `makeup_checks`. If the send then fails, the make-ups stay recorded: the leader said they happened.
 */
class SendWeeklyReportHandler
{
    public const ACTION = 'weekly_report.sent';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly WeeklyReportRepository $reports,
        private readonly WeeklyReportKpis $snapshots,
        private readonly AuditEnvelope $audit,
        private readonly PlatoonTrainingTopicRepository $topics,
        private readonly ActingFor $acting,
        private readonly KpiService $kpis,
        private readonly MarkAttendanceHandler $attendance,
    ) {}

    /**
     * @throws SendWeeklyReportForbiddenException when the unit is outside the actor's scope, or
     *                                            does not exist.
     * @throws ReasonRequiredException when no reason is given — on a re-send too.
     * @throws InvalidReportContentException when the content is not the unit's to record, or the
     *                                       week is before `WeekOf::floor()`.
     * @throws TrainingSubjectEnforcedException when the platoon enforces a different subject.
     * @throws ReportKpisUnavailableException when the unit's KPIs cannot be reported yet, or the
     *                                        prior week's sent snapshot is unreadable.
     */
    public function handle(User $actor, SendWeeklyReport $command): SentWeeklyReport
    {
        $unit = $this->scope->writeScope($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

        // A group (a staff department, ELOA, Reservists, New Recruits) has no weekly report (2026-10-24).
        if ($unit === null || ! $unit->isLine()) {
            throw new SendWeeklyReportForbiddenException($command->unitId);
        }

        // Checked here as well as by the envelope, so a reasonless re-send is refused too rather
        // than answered with the no-op below.
        if (blank($command->reason)) {
            throw ReasonRequiredException::forAction(self::ACTION);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidReportContentException::weekBeforeFloor($weekStart);
        }

        $existing = $this->reports->find($unit->id, $weekStart);

        if ($existing?->status === WeeklyReportStatus::Sent) {
            return new SentWeeklyReport($existing, alreadySent: true);
        }

        $content = ReportContent::checked($unit, $weekStart, $command->notes, $command->trainingSubject, $command->unaccounted, $this->troopers);
        $trainingSubject = SectionTrainingSubject::forSend($unit->id, $this->topics->forSection($unit, $weekStart), $content->trainingSubject);
        $templateVersionId = null;
        $makeupChecks = $this->makeupChecks($unit->type === UnitType::Section ? $this->owedThisWeek($unit, $weekStart) : [], $command->makeupChecks);
        $madeUp = array_values(array_map(fn (array $check) => $check['trooper_id'], array_filter($makeupChecks ?? [], fn (array $check) => $check['done'])));

        if ($madeUp !== []) {
            $this->attendance->handle($actor, new MarkAttendance(
                $unit->id,
                $weekStart->toDateTimeImmutable(),
                ...array_map(fn (int $trooperId) => new AttendanceMark($trooperId, AttendanceStatus::Makeup), $madeUp),
            ));
        }

        try {
            $report = $this->audit->record(
                actorId: $actor->id,
                action: self::ACTION,
                reason: $command->reason,
                reasonRequired: true,
                mutation: function () use ($unit, $weekStart, $content, $actor, $templateVersionId, $trainingSubject, $makeupChecks) {
                    // Lock the report row first, then read the numbers it freezes. A rollcall save
                    // holds a shared lock on this row until it commits (`isSentFor()`), so the
                    // lock is granted only once every save that got in first has committed — and
                    // the snapshot read after it includes them. Read before the lock, a save
                    // committing in between would be missing from the frozen KPIs while the week
                    // then locked under it (AD-12).
                    $this->reports->lockForSend($unit->id, $weekStart);

                    $kpis = $this->snapshots->snapshot($unit, $weekStart)->toArray();

                    return $this->reports->send(
                        $unit->id,
                        $weekStart,
                        $kpis,
                        $content->notes,
                        $actor->id,
                        self::contentDigest($unit->id, $weekStart, $kpis, $content->notes, $templateVersionId, $trainingSubject, $content->unaccounted, $makeupChecks),
                        $templateVersionId,
                        $trainingSubject,
                        $content->unaccounted,
                        // An Admin acting as the sender (#379) is named beside them.
                        $this->acting->adminActingAs($actor->id),
                        // An Admin holding a billet sends in their own name, as that unit's leader.
                        $this->acting->billetOf($actor->id),
                        $makeupChecks,
                    );
                },
            );
        } catch (WeeklyReportAlreadySentException $raced) {
            $winner = $this->reports->find($unit->id, $weekStart);

            if ($winner?->status !== WeeklyReportStatus::Sent) {
                throw $raced;
            }

            return new SentWeeklyReport($winner, alreadySent: true);
        }

        return new SentWeeklyReport($report, alreadySent: false);
    }

    /**
     * SHA-256 over the content a send freezes — the unit-week key, the KPI snapshot, the notes, the
     * training subject, the unaccounted reasons and the template version — with every object's keys sorted first, so the digest recomputed from
     * the stored row matches whatever key order the JSON column hands back (MySQL's JSON type
     * reorders keys). Public so a reader can verify a sent report against its `content_digest`.
     *
     * @param  array<string, mixed>  $kpis
     * @param  list<array{trooper_id: int, reason: string}>  $unaccounted
     * @param  list<array{trooper_id: int, done: bool}>|null  $makeupChecks  left out of the digest when null, so a
     *                                                                       report sent before it existed still verifies
     */
    public static function contentDigest(
        int $unitId,
        CarbonInterface $weekStart,
        array $kpis,
        ?string $notes,
        ?int $templateVersionId,
        ?string $trainingSubject = null,
        array $unaccounted = [],
        ?array $makeupChecks = null,
    ): string {
        return hash('sha256', json_encode(self::sortKeys([
            ...($makeupChecks === null ? [] : ['makeup_checks' => $makeupChecks]),
            'unit_id' => $unitId,
            'week_start' => CarbonImmutable::instance($weekStart)->utc()->toIso8601String(),
            'kpis' => $kpis,
            'notes' => $notes,
            'training_subject' => $trainingSubject,
            'unaccounted' => $unaccounted,
            'template_version_id' => $templateVersionId,
        ]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The troopers on the section's roll call who asked for a make-up this week and have none recorded, in id order.
     *
     * @return list<int>
     */
    private function owedThisWeek(Unit $section, CarbonImmutable $weekStart): array
    {
        $week = $weekStart->toDateString();

        return $this->kpis->trooperAttendance($section, $weekStart)
            ->filter(fn (TrooperAttendance $row) => in_array($week, $row->makeupOwedWeeks, true))
            ->map(fn (TrooperAttendance $row) => $row->trooperId)
            ->sort()->values()->all();
    }

    /**
     * The answers as frozen, in trooper id order: exactly one for each owed trooper, none for anyone else. Null when
     * nobody owed a make-up, so nothing is asked or stored.
     *
     * @param  list<int>  $owed
     * @param  list<array{trooper_id: int, done: bool}>  $given
     * @return list<array{trooper_id: int, done: bool}>|null
     *
     * @throws MakeupChecksIncompleteException
     */
    private function makeupChecks(array $owed, array $given): ?array
    {
        $answers = [];
        $notOwed = [];

        foreach ($given as $check) {
            $trooperId = (int) $check['trooper_id'];

            if (! in_array($trooperId, $owed, true) || isset($answers[$trooperId])) {
                $notOwed[] = $trooperId;

                continue;
            }

            $answers[$trooperId] = ['trooper_id' => $trooperId, 'done' => (bool) $check['done']];
        }

        $unanswered = array_values(array_diff($owed, array_keys($answers)));

        if ($unanswered !== [] || $notOwed !== []) {
            throw new MakeupChecksIncompleteException($unanswered, $notOwed);
        }

        if ($owed === []) {
            return null;
        }

        ksort($answers);

        return array_values($answers);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? self::sortKeys($item) : $item, $value);
    }
}
