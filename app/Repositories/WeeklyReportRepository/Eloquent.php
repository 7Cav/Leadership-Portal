<?php

namespace App\Repositories\WeeklyReportRepository;

use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class Eloquent implements WeeklyReportRepository
{
    public function find(int $unitId, CarbonInterface $weekStart): ?WeeklyReport
    {
        return WeeklyReport::query()
            ->where('unit_id', $unitId)
            ->where('week_start', $this->assertWeekStart($weekStart))
            ->first();
    }

    /**
     * Reads the row by its UNIQUE `(unit_id, week_start)` key whatever its status, and decides in
     * PHP: filtering on status in SQL would leave a draft row unlocked, and a send could then flip
     * it to sent under a save already past this check.
     */
    public function isSentFor(int $unitId, CarbonInterface $weekStart): bool
    {
        $report = WeeklyReport::query()
            ->where('unit_id', $unitId)
            ->where('week_start', $this->assertWeekStart($weekStart))
            ->sharedLock()
            ->first(['id', 'status', 'is_demo']);

        return $report !== null && $report->status === WeeklyReportStatus::Sent && ! $report->is_demo;
    }

    public function forUnits(array $unitIds, CarbonInterface $weekStart): Collection
    {
        $weekStart = $this->assertWeekStart($weekStart);

        if ($unitIds === []) {
            return collect();
        }

        return WeeklyReport::query()
            ->whereIn('unit_id', $unitIds)
            ->where('week_start', $weekStart)
            ->get()
            ->keyBy('unit_id')
            ->toBase();
    }

    public function sentBefore(int $unitId, CarbonInterface $weekStart, int $limit): Collection
    {
        return WeeklyReport::query()
            ->with('sender')
            ->where('unit_id', $unitId)
            ->where('status', WeeklyReportStatus::Sent)
            ->where('week_start', '<', $this->assertWeekStart($weekStart))
            ->orderByDesc('week_start')
            ->limit($limit)
            ->get()
            ->toBase();
    }

    public function sentWithin(array $unitIds, CarbonInterface $firstWeek, CarbonInterface $lastWeek): Collection
    {
        $firstWeek = $this->assertWeekStart($firstWeek);
        $lastWeek = $this->assertWeekStart($lastWeek);

        if ($unitIds === []) {
            return collect();
        }

        return WeeklyReport::query()
            ->whereIn('unit_id', $unitIds)
            ->where('status', WeeklyReportStatus::Sent)
            ->whereBetween('week_start', [$firstWeek, $lastWeek])
            ->orderBy('week_start')
            ->orderBy('unit_id')
            ->get()
            ->toBase();
    }

    public function saveDraft(
        int $unitId,
        CarbonInterface $weekStart,
        array $kpis,
        ?string $notes,
        ?string $trainingSubject = null,
        array $unaccounted = [],
    ): WeeklyReport {
        return $this->write($unitId, $weekStart, [
            'kpis' => $kpis,
            'notes' => $notes,
            'training_subject' => $trainingSubject,
            'unaccounted' => $unaccounted,
        ]);
    }

    public function send(
        int $unitId,
        CarbonInterface $weekStart,
        array $kpis,
        ?string $notes,
        int $sentBy,
        string $contentDigest,
        ?int $templateVersionId,
        ?string $trainingSubject = null,
        array $unaccounted = [],
        ?int $sentByAdmin = null,
    ): WeeklyReport {
        if (! User::query()->whereKey($sentBy)->exists()) {
            throw new InvalidArgumentException("User {$sentBy} does not exist; a sent report records its sender.");
        }

        if ($templateVersionId !== null && ! TemplateVersion::query()->whereKey($templateVersionId)->exists()) {
            throw new InvalidArgumentException("Template version {$templateVersionId} does not exist; a sent report can only name a stored version.");
        }

        return $this->write($unitId, $weekStart, [
            'status' => WeeklyReportStatus::Sent,
            'kpis' => $kpis,
            'notes' => $notes,
            'training_subject' => $trainingSubject,
            'unaccounted' => $unaccounted,
            'sent_at' => CarbonImmutable::now('UTC'),
            'sent_by' => $sentBy,
            'sent_by_admin' => $sentByAdmin,
            'content_digest' => $contentDigest,
            'template_version_id' => $templateVersionId,
        ]);
    }

    /**
     * The first half of {@see write()} on its own — the same placeholder upsert and row lock, the
     * same refusal of a sent row — so the send path can hold the row before it computes what it
     * freezes. It writes nothing of its own that outlives a rollback: the placeholder a first send
     * creates is rolled back with the caller's transaction if the send then fails.
     */
    public function lockForSend(int $unitId, CarbonInterface $weekStart): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('WeeklyReportRepository::lockForSend() must run inside the send\'s transaction; on its own the lock would be released at once.');
        }

        $this->lockUnsent($unitId, $this->assertWeekStart($weekStart));
    }

    /**
     * Update-or-create the unit-week's row, refusing a sent one, in one transaction: lock the row
     * ({@see lockUnsent()}), then write `$values` over it.
     *
     * @param  array<string, mixed>  $values  built only by this class, so they are force-filled
     */
    private function write(int $unitId, CarbonInterface $weekStart, array $values): WeeklyReport
    {
        $weekStart = $this->assertWeekStart($weekStart);

        return DB::transaction(function () use ($unitId, $weekStart, $values) {
            $report = $this->lockUnsent($unitId, $weekStart);

            $report->forceFill($values)->save();

            return $report;
        });
    }

    /**
     * Inside the caller's transaction, lock the unit-week's row for update and refuse it if sent:
     *
     * 1. Share-lock the `units` row, so the unit cannot be deleted mid-write and a missing unit is
     *    an `InvalidArgumentException` rather than a raw foreign-key error.
     * 2. Upsert a placeholder `(unit_id, week_start)` row, so a row always exists before it is
     *    locked. A `SELECT ... FOR UPDATE` on a missing key takes only a gap lock on MySQL, and
     *    first writes sharing the gap deadlock when they all insert. An upsert takes an
     *    *exclusive* lock on the row whether it inserts or hits the duplicate, so concurrent
     *    writers of one unit-week queue one at a time — an insert-or-ignore would take only a
     *    shared lock on the duplicate, and three or more writers then deadlock upgrading it.
     *    The placeholder values never outlive the transaction; a duplicate touches only
     *    `updated_at`, which the write sets again anyway.
     * 3. Lock that row and refuse it if sent.
     *
     * Every read here is a locking read, so on MySQL's REPEATABLE READ none of them opens the
     * transaction's read view: the first plain read after this — the send's KPI snapshot — sees
     * everything committed before the lock was granted.
     *
     * @throws WeeklyReportAlreadySentException when the unit-week is already sent.
     */
    private function lockUnsent(int $unitId, CarbonImmutable $weekStart): WeeklyReport
    {
        if (Unit::query()->whereKey($unitId)->sharedLock()->first() === null) {
            throw new InvalidArgumentException("Unit {$unitId} does not exist; a weekly report belongs to a unit.");
        }

        $now = CarbonImmutable::now('UTC');

        WeeklyReport::query()->toBase()->upsert(
            [[
                'unit_id' => $unitId,
                'week_start' => $weekStart,
                'status' => WeeklyReportStatus::Draft->value,
                'kpis' => '[]',
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['unit_id', 'week_start'],
            ['updated_at'],
        );

        $report = WeeklyReport::query()
            ->where('unit_id', $unitId)
            ->where('week_start', $weekStart)
            ->lockForUpdate()
            ->sole();

        if ($report->status === WeeklyReportStatus::Sent) {
            throw WeeklyReportAlreadySentException::for($unitId, $weekStart);
        }

        return $report;
    }

    /**
     * Validates rather than derives: `$weekStart` must already be the boundary `WeekOf` maps its
     * own instant to (AD-11), so the Saturday 00:00 UTC rule lives in that one helper. Returned
     * as that UTC instant, so the stored and queried value is the same on every driver.
     */
    private function assertWeekStart(CarbonInterface $weekStart): CarbonImmutable
    {
        $boundary = WeekOf::start($weekStart);

        if (! $boundary->equalTo($weekStart)) {
            throw new InvalidArgumentException(
                "week_start must be a Saturday 00:00 UTC (AD-11); got {$weekStart->toIso8601String()}.",
            );
        }

        return $boundary;
    }

    public function hasReportsFor(Unit $unit): bool
    {
        return WeeklyReport::query()->where('unit_id', $unit->getKey())->exists();
    }
}
