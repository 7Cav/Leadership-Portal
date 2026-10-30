<?php

namespace App\Commands\Demo;

use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The plausible week a section's troopers had (#115). Deterministic: the seed is the
 * (section, week) pair and troopers are walked in id order, so regenerating a week reproduces the
 * same marks. Most troopers sign "will attend" and turn up; a few cannot attend (and mostly miss
 * or make up), a few never sign.
 *
 * One trooper in each section of at least {@see AT_RISK_MIN_SECTION} — picked by a stable hash,
 * so the same trooper every week — follows an at-risk pattern: present one week in four, missed
 * the rest, which crosses `TrooperAttendance::AT_RISK_MISSES` in every 4-week KPI window.
 *
 * Never produces `loa` (derived from LOA data, deferred for the MVP).
 */
final class DemoAttendancePattern
{
    public const AT_RISK_MIN_SECTION = 4;

    private const WEEK_SECONDS = 604800;

    /**
     * @param  list<int>  $trooperIds  the section's placed troopers
     * @return list<array{trooper_id: int, status: AttendanceStatus, rollcall: RollcallSignature}> in trooper-id order
     */
    public static function forSectionWeek(int $sectionId, CarbonImmutable $weekStart, array $trooperIds): array
    {
        sort($trooperIds);
        $random = new Randomizer(new Mt19937(crc32("{$sectionId}:{$weekStart->utc()->toDateString()}")));
        $atRisk = self::atRiskTrooper($sectionId, $trooperIds);
        // Every fourth Cav week, counted from the epoch, is the at-risk trooper's one good week.
        $goodWeek = intdiv($weekStart->getTimestamp(), self::WEEK_SECONDS) % 4 === 0;

        return array_map(function (int $trooperId) use ($random, $atRisk, $goodWeek) {
            // Drawn for every trooper, at-risk or not, so the sequence stays aligned.
            $signature = $random->getInt(0, 99);
            $mark = $random->getInt(0, 99);

            [$rollcall, $status] = $trooperId === $atRisk
                ? self::atRiskWeek($goodWeek, $signature)
                : self::typicalWeek($signature, $mark);

            return ['trooper_id' => $trooperId, 'status' => $status, 'rollcall' => $rollcall];
        }, $trooperIds);
    }

    /**
     * The section's at-risk trooper, or null when the section is too small to single one out.
     *
     * @param  list<int>  $trooperIds
     */
    public static function atRiskTrooper(int $sectionId, array $trooperIds): ?int
    {
        if (count($trooperIds) < self::AT_RISK_MIN_SECTION) {
            return null;
        }

        usort($trooperIds, fn (int $a, int $b) => [crc32("at-risk:{$sectionId}:{$a}"), $a] <=> [crc32("at-risk:{$sectionId}:{$b}"), $b]);

        return $trooperIds[0];
    }

    /**
     * @return array{RollcallSignature, AttendanceStatus}
     */
    private static function typicalWeek(int $signature, int $mark): array
    {
        return match (true) {
            $signature < 82 => [RollcallSignature::WillAttend, match (true) {
                $mark < 92 => AttendanceStatus::Present,
                $mark < 98 => AttendanceStatus::Makeup,
                default => AttendanceStatus::Missed,
            }],
            $signature < 94 => [RollcallSignature::CannotAttend, $mark < 40 ? AttendanceStatus::Missed : AttendanceStatus::Makeup],
            default => [RollcallSignature::Unsigned, $mark < 70 ? AttendanceStatus::Present : AttendanceStatus::Missed],
        };
    }

    /**
     * @return array{RollcallSignature, AttendanceStatus}
     */
    private static function atRiskWeek(bool $goodWeek, int $signature): array
    {
        if ($goodWeek) {
            return [RollcallSignature::WillAttend, AttendanceStatus::Present];
        }

        return [$signature < 60 ? RollcallSignature::Unsigned : RollcallSignature::CannotAttend, AttendanceStatus::Missed];
    }
}
