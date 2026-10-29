<?php

namespace App\Milpac;

use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * Where a trooper's counseling stands under the regulations (2026-10-28), worked out between the records by their dates,
 * the way the 7Cav wiki measures it, never from today: each record is weighed against the ones dated before it, so a
 * record's standing stays with it however long ago it was given. Pure: it takes the records it is handed (only those the
 * viewer sees, so a flag never gives a DCS away) and reads nothing.
 *
 * Two ladders, by what the record is for ({@see CounselingKind}):
 *
 *  - **Other conduct** (and a record with no kind, filed before the portal asked): a repeat within 90 days of a DCS
 *    calls for an LOR, if it was for the same issue (the portal cannot tell, so the flag says "if"). A repeat within
 *    30 days of an LOR is the one repeat LOR, which goes to an Article 15, again if it was for the same issue, so a DCS
 *    in those 30 days is flagged as the wrong step (S6, 2026-10-28).
 *  - **Missed section practice or rollcall**: a second miss within 30 days of the last calls for an LOR (31 days or
 *    more: another DCS); a miss within 60 days of an LOR calls for a second LOR; a second LOR within 60 days of the
 *    first goes to an Article 15, company first, then battalion.
 *
 * Across both, since they count LORs on any matter: a second LOR within 90 days authorizes removal as SL or ASL, and a
 * third within 90 days goes to an Article 15. An LOR also brings 30 days of no favorable action, the one rule with a
 * period that runs on today ({@see noFavorableAction()}).
 *
 * "Within N days" is at most N days apart, the same day included: records on one date are ordered by when they were
 * filed (their id). A record filed late with an earlier date takes its place by its date.
 */
final class CounselingStanding
{
    public const DCS_REPEAT_DAYS = 90;

    public const LOR_REPEAT_DAYS = 30;

    public const LOR_WINDOW_DAYS = 90;

    public const NO_FAVORABLE_ACTION_DAYS = 30;

    public const MISSED_REPEAT_DAYS = 30;

    public const MISSED_AFTER_LOR_DAYS = 60;

    /**
     * Every window {@see flagsFor()} measures a record against, the windows a forecast breaks its ranges on (audit 2,
     * I1). Listed one by one, not deduplicated by value, so a rule whose window changes moves its own break. The no
     * favorable action period runs on today, not between records, so it is not one of them.
     */
    public const RULE_WINDOWS = [
        self::DCS_REPEAT_DAYS,
        self::LOR_REPEAT_DAYS,
        self::LOR_WINDOW_DAYS,
        self::MISSED_REPEAT_DAYS,
        self::MISSED_AFTER_LOR_DAYS,
    ];

    /**
     * One record as the rules see it: `day` counts days since the Unix epoch, `kind` is the ladder it is on (a record
     * with no kind is on the conduct ladder).
     *
     * @return array{id: int, day: int, type: CounselingType, kind: CounselingKind}
     */
    public static function entry(int $id, string $date, CounselingType $type, ?CounselingKind $kind): array
    {
        return ['id' => $id, 'day' => self::day($date), 'type' => $type, 'kind' => $kind ?? CounselingKind::Conduct];
    }

    /** @return array{id: int, day: int, type: CounselingType, kind: CounselingKind} */
    public static function fromRecord(CounselingRecord $record): array
    {
        return self::entry($record->id, $record->counseled_on->toDateString(), $record->type, $record->kind);
    }

    /**
     * The flags each record earned against the records dated before it, by record id. A record that crossed no
     * threshold has an empty list.
     *
     * @param  list<array{id: int, day: int, type: CounselingType, kind: CounselingKind}>  $entries  in any order
     * @return array<int, list<array{key: string, message: string}>>
     */
    public static function flags(array $entries): array
    {
        $ordered = self::chronological($entries);
        $flags = [];

        foreach ($ordered as $i => $entry) {
            $flags[$entry['id']] = self::flagsFor($entry, array_slice($ordered, 0, $i));
        }

        return $flags;
    }

    /**
     * What a new record of `$type` and `$kind` would be flagged with, by the date it carries: a list of date ranges,
     * each from its `from` date (inclusive) up to the next one's, oldest first, covering every date up to `$today` (the
     * first range starts at 1970-01-01). The filing form picks the range its date falls in, so the server stays the judge of the rules.
     * A new record goes after every record dated on or before its date (it is filed last).
     *
     * @param  list<array{id: int, day: int, type: CounselingType, kind: CounselingKind}>  $entries
     * @return list<array{from: string, flags: list<array{key: string, message: string}>}>
     */
    public static function forecast(array $entries, CounselingType $type, CounselingKind $kind, CarbonImmutable $today): array
    {
        $first = 0;
        $last = self::day($today->toDateString());
        $ordered = self::chronological($entries);

        // The flags change only where a record enters the past (its own day) or leaves one of the windows.
        $breaks = [$first];
        foreach ($ordered as $entry) {
            $breaks[] = $entry['day'];
            foreach (self::RULE_WINDOWS as $days) {
                $breaks[] = $entry['day'] + $days + 1;
            }
        }
        $breaks = array_values(array_unique(array_filter($breaks, fn (int $day) => $day >= $first && $day <= $last)));
        sort($breaks);

        $ranges = [];
        foreach ($breaks as $day) {
            $earlier = array_values(array_filter($ordered, fn (array $entry) => $entry['day'] <= $day));
            $flags = self::flagsFor(['id' => PHP_INT_MAX, 'day' => $day, 'type' => $type, 'kind' => $kind], $earlier);

            if ($ranges !== [] && $ranges[count($ranges) - 1]['flags'] === $flags) {
                continue;
            }

            $ranges[] = ['from' => self::date($day), 'flags' => $flags];
        }

        return $ranges;
    }

    /**
     * While today is within 30 days of the latest LOR (the 30th day included): the date no favorable action ends.
     *
     * @param  list<array{id: int, day: int, type: CounselingType, kind: CounselingKind}>  $entries
     * @return array{key: string, message: string, until: string}|null
     */
    public static function noFavorableAction(array $entries, CarbonImmutable $today): ?array
    {
        $lors = array_map(fn (array $entry) => $entry['day'], array_filter($entries, fn (array $entry) => $entry['type'] === CounselingType::Lor));

        if ($lors === []) {
            return null;
        }

        $latest = min(max($lors), self::day($today->toDateString()));
        $until = $latest + self::NO_FAVORABLE_ACTION_DAYS;

        if (self::day($today->toDateString()) > $until) {
            return null;
        }

        return [
            'key' => 'no-favorable-action',
            'message' => 'No favorable action until '.self::forumDate($until).', 30 days after the LOR of '.self::forumDate($latest).'.',
            'until' => self::date($until),
        ];
    }

    /**
     * Whether ruling a record that has no kind as other conduct changes anything: the flags would differ anywhere if it
     * were for a missed section practice or rollcall instead. Only then does the panel say how it was ruled.
     *
     * @param  list<array{id: int, day: int, type: CounselingType, kind: CounselingKind}>  $entries
     */
    public static function kindMatters(array $entries, int $id): bool
    {
        $other = array_map(fn (array $entry) => $entry['id'] === $id ? [...$entry, 'kind' => CounselingKind::MissedPractice] : $entry, $entries);

        return self::flags($entries) !== self::flags($other);
    }

    /**
     * @param  array{id: int, day: int, type: CounselingType, kind: CounselingKind}  $entry
     * @param  list<array{id: int, day: int, type: CounselingType, kind: CounselingKind}>  $earlier  the records before it
     * @return list<array{key: string, message: string}>
     */
    private static function flagsFor(array $entry, array $earlier): array
    {
        $within = fn (int $days) => array_values(array_filter($earlier, fn (array $e) => $days >= $entry['day'] - $e['day']));
        $ladder = fn (array $entries) => array_values(array_filter($entries, fn (array $e) => $e['kind'] === $entry['kind']));
        $ofType = fn (array $entries, CounselingType $type) => array_values(array_filter($entries, fn (array $e) => $e['type'] === $type));
        $missed = $entry['kind'] === CounselingKind::MissedPractice;
        $flags = [];

        if ($entry['type'] === CounselingType::Lor) {
            if ($missed && $ofType($ladder($within(self::MISSED_AFTER_LOR_DAYS)), CounselingType::Lor) !== []) {
                $flags[] = ['key' => 'missed-article-15', 'message' => '2nd LOR for a missed section practice or rollcall within 60 days of the first: Article 15, company first, then battalion.'];
            }

            if (! $missed && $ofType($ladder($within(self::LOR_REPEAT_DAYS)), CounselingType::Lor) !== []) {
                $flags[] = ['key' => 'lor-repeat', 'message' => 'Within 30 days of an LOR: if it was for the same issue, this is the repeat LOR, which goes to an Article 15.'];
            }

            $lors = count($ofType($within(self::LOR_WINDOW_DAYS), CounselingType::Lor)) + 1;
            if ($lors >= 3) {
                $flags[] = ['key' => 'lor-article-15', 'message' => "{$lors} LORs within 90 days: Article 15."];
            } elseif ($lors === 2) {
                $flags[] = ['key' => 'lor-removal', 'message' => '2nd LOR within 90 days: removal as SL or ASL is authorized.'];
            }

            return $flags;
        }

        if ($missed) {
            if ($ofType($ladder($within(self::MISSED_AFTER_LOR_DAYS)), CounselingType::Lor) !== []) {
                $flags[] = ['key' => 'missed-second-lor', 'message' => 'Missed within 60 days of an LOR for a missed section practice or rollcall: a second LOR is called for.'];
            } elseif ($ladder($within(self::MISSED_REPEAT_DAYS)) !== []) {
                $flags[] = ['key' => 'missed-lor', 'message' => 'Second miss within 30 days of the last: an LOR is called for.'];
            }

            return $flags;
        }

        if ($ofType($ladder($within(self::LOR_REPEAT_DAYS)), CounselingType::Lor) !== []) {
            $flags[] = ['key' => 'dcs-after-lor', 'message' => 'Within 30 days of an LOR: the regulation calls for a repeat LOR, not a DCS, if it is for the same issue.'];
        }

        if ($ofType($ladder($within(self::DCS_REPEAT_DAYS)), CounselingType::Dcs) !== []) {
            $flags[] = ['key' => 'dcs-repeat', 'message' => 'Repeat within 90 days of a DCS: an LOR is the next step if it was for the same issue.'];
        }

        return $flags;
    }

    /**
     * Oldest first, by date and then by id (the order they were filed in).
     *
     * @param  list<array{id: int, day: int, type: CounselingType, kind: CounselingKind}>  $entries
     * @return list<array{id: int, day: int, type: CounselingType, kind: CounselingKind}>
     */
    private static function chronological(array $entries): array
    {
        usort($entries, fn (array $a, array $b) => [$a['day'], $a['id']] <=> [$b['day'], $b['id']]);

        return $entries;
    }

    private static function day(string $date): int
    {
        return intdiv(CarbonImmutable::parse($date, 'UTC')->startOfDay()->getTimestamp(), 86400);
    }

    private static function date(int $day): string
    {
        return CarbonImmutable::createFromTimestampUTC($day * 86400)->toDateString();
    }

    /** `03Oct26`, the forum date (docs/copywriting.md). */
    private static function forumDate(int $day): string
    {
        return CarbonImmutable::createFromTimestampUTC($day * 86400)->format('dMy');
    }
}
