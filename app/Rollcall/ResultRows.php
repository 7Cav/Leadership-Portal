<?php

namespace App\Rollcall;

/**
 * The rows of the roll call result images (#641): each trooper's name and their Signed RC and Attended SP mark as the
 * forum post words them, in the order the post lists them. It is the server's twin of what the browser works out for the
 * post (`resources/js/lib/rollcallBbcode.js`, `rollcall.js`, `fireteams.js`), so a mark reads the same in the post and in
 * the images, except that an unmarked trooper reads `-` here where the post prints nothing, so the image never shows a
 * blank. Pure: the caller passes rows shaped as {@see RollcallRoster} builds them, plus `excused` (the automatic
 * Excused reason, or null) and `locked` (covered by leave), which the roll call page adds from the one definitions of
 * those (`AutoExcused`, `LoaCoverage`).
 *
 * The images list leaders first and then everyone by rank, as the post does; a section whose post groups troopers under
 * their fireteam's leader is listed leaders-first here.
 */
final class ResultRows
{
    private const RC_WORDS = ['will_attend' => 'Yes', 'cannot_attend' => 'No', 'makeup_requested' => 'MU', 'excused' => 'EX'];

    private const SP_WORDS = ['present' => 'Yes', 'missed' => 'No', 'excused' => 'EX', 'makeup' => 'MU', 'loa' => 'LOA'];

    /** Junior to senior, as `resources/js/lib/roster.js` `RANK_ORDER`. */
    private const RANK_ORDER = [
        'RCT', 'PVT', 'PFC', 'SPC', 'CPL', 'SGT', 'SSG', 'SFC', 'MSG', '1SG', 'SGM', 'CSM',
        'WO1', 'CW2', 'CW3', 'CW4', 'CW5', '2LT', '1LT', 'CPT', 'MAJ', 'LTC', 'COL', 'BG', 'MG', 'LTG', 'GEN', 'GOA',
    ];

    private const GROUP = ['sl' => 0, 'asl' => 1, 'ftl' => 2];

    /**
     * @param  list<array<string, mixed>>  $troopers
     * @param  bool  $showFireteams  whether the section's post lists its fireteam leaders above the rest
     * @return list<array{name: string, rc: string, sp: string}>
     */
    public static function for(array $troopers, bool $showFireteams): array
    {
        usort($troopers, fn (array $a, array $b) => self::group($a, $showFireteams) <=> self::group($b, $showFireteams)
            ?: self::rank($b) <=> self::rank($a)
            ?: self::compareNames($a, $b)
            ?: $a['id'] <=> $b['id']);

        return array_map(fn (array $trooper) => [
            'name' => self::label($trooper),
            'rc' => self::RC_WORDS[self::signature($trooper) ?? ''] ?? '-',
            'sp' => self::SP_WORDS[self::practice($trooper) ?? ''] ?? '-',
        ], $troopers);
    }

    /** The signature as it counts: what was recorded, else the automatic Excused (`AutoExcused::excusesSignature()`). */
    private static function signature(array $trooper): ?string
    {
        $recorded = $trooper['rollcall'] ?? 'unsigned';

        return ! empty($trooper['excused']) && $recorded === 'unsigned' ? 'excused' : $recorded;
    }

    /** The practice mark as it counts: leave first, then what was recorded, else the automatic Excused. */
    private static function practice(array $trooper): ?string
    {
        $status = $trooper['status'] ?? null;

        if (! empty($trooper['locked']) || $status === 'loa') {
            return 'loa';
        }

        if (! empty($trooper['excused']) && ($status === null || (($trooper['source'] ?? null) === 'auto' && ! in_array($status, ['present', 'makeup', 'loa'], true)))) {
            return 'excused';
        }

        return $status;
    }

    private static function group(array $trooper, bool $showFireteams): int
    {
        $role = $trooper['role'] ?? null;

        if ($role === 'ftl' && ! $showFireteams) {
            return 3;
        }

        return self::GROUP[$role ?? ''] ?? 3;
    }

    private static function rank(array $trooper): int
    {
        $index = array_search($trooper['rankShort'] ?? $trooper['rank'] ?? null, self::RANK_ORDER, true);

        return $index === false ? -1 : $index;
    }

    /** By last name then initial of the forum username; a trooper with no username after the named ones, by milpac id. */
    private static function compareNames(array $a, array $b): int
    {
        [$x, $y] = [self::nameKey($a), self::nameKey($b)];

        if ($x === null || $y === null) {
            return ((int) ($x === null) <=> (int) ($y === null)) ?: (($a['cavId'] ?? 0) <=> ($b['cavId'] ?? 0));
        }

        return strcmp($x[0], $y[0]) ?: strcmp($x[1], $y[1]);
    }

    /** @return array{string, string}|null */
    private static function nameKey(array $trooper): ?array
    {
        if (empty($trooper['name'])) {
            return null;
        }

        $name = mb_strtolower($trooper['name']);
        $dot = mb_strrpos($name, '.');

        return $dot > 0 ? [mb_substr($name, 0, $dot), mb_substr($name, $dot + 1)] : [$name, ''];
    }

    private static function label(array $trooper): string
    {
        $rank = $trooper['rankShort'] ?? $trooper['rank'] ?? null;
        $name = $trooper['name'] ?? '#'.($trooper['cavId'] ?? '');

        return trim("{$rank} {$name}");
    }
}
