<?php

namespace App\Commands\WeeklyReports;

use App\Models\UnaccountedReason;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TrooperRepository;
use Carbon\CarbonImmutable;

/**
 * The leader-written half of a weekly report (5.5) — notes, training subject, and a reason for
 * each trooper who missed practice without a makeup — checked the same way whether it is being
 * saved as a draft or frozen by a send. Blank text is stored as null. Built only through
 * `checked()`, so holding one means the content was checked.
 */
final readonly class ReportContent
{
    /**
     * @param  list<array{trooper_id: int, reason: string}>  $unaccounted
     */
    private function __construct(
        public ?string $notes,
        public ?string $trainingSubject,
        public array $unaccounted,
    ) {}

    /**
     * @param  list<array{trooper_id: int, reason: string}>  $unaccounted
     *
     * @throws InvalidReportContentException when a reason is unknown, a trooper appears twice or
     *                                       is not on `$unit`'s rollcall for the week (placed in it
     *                                       now, or when a week that has ended ended), or `$unit` is
     *                                       not a section and still lists troopers.
     */
    public static function checked(Unit $unit, CarbonImmutable $weekStart, ?string $notes, ?string $trainingSubject, array $unaccounted, TrooperRepository $troopers): self
    {
        $rows = [];

        foreach ($unaccounted as $row) {
            $trooperId = (int) $row['trooper_id'];

            if (UnaccountedReason::tryFrom($row['reason']) === null) {
                throw InvalidReportContentException::unknownReason($row['reason']);
            }

            if (isset($rows[$trooperId])) {
                throw InvalidReportContentException::duplicateTrooper($trooperId);
            }

            $rows[$trooperId] = ['trooper_id' => $trooperId, 'reason' => $row['reason']];
        }

        if ($rows !== [] && $unit->type !== UnitType::Section) {
            throw InvalidReportContentException::notASection($unit->id);
        }

        $stray = array_values(array_diff(array_keys($rows), $troopers->idsOnRollcall($unit, $weekStart, array_keys($rows))->all()));

        if ($stray !== []) {
            throw InvalidReportContentException::notInSection($stray, $unit->id);
        }

        return new self(self::text($notes), self::text($trainingSubject), array_values($rows));
    }

    private static function text(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }
}
