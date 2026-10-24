<?php

namespace App\Commands\Units;

use App\Models\UnitKind;

/**
 * Where a billet outside the line companies places its holder (decided by the product owner 2026-10-24), read by the
 * org import from the Cav position groups. The one place that says which staff groups exist, what they are called and
 * which portfolio a department starts under:
 *
 *  - **Holding groups**, by exact billet title: `ELOA` → Extended Leave of Absence, `Reservist` → Reservists,
 *    `New Recruit` → New Recruits. Each sits directly under the regiment.
 *  - **Staff departments**, by the department code that starts the billet title, as the ADR lists them: S1, S2, S3, S5,
 *    S6, S7, WAG, MP, RRD, RTC and DEVCOM (`S6 1IC`, `RRD Recruiter - Squad`, `DEVCOM Support Clerk`). A few billets
 *    carry no code but belong to one: `Regimental Technical Aide` (S6), `Cavalry Times ...` (S5), `ODS ...` and
 *    `NCOA ...` (S7), `RDC Commander` and `FCC Analyst` (DEVCOM staff).
 *  - **Portfolios**: a department hangs under the regimental staff officer whose portfolio holds it ({@see PORTFOLIOS},
 *    {@see DEPARTMENT_PORTFOLIO}). Only a newly created department is put there; an Admin moves one to another
 *    portfolio with the audited Move, and the import never moves it back. A department with no portfolio sits under
 *    the regiment. The portfolio officer's billet commands the portfolio node, so they see their departments and the
 *    node shows them as its holder (or vacant).
 *
 * Matching is exact or by a whole leading code, never a guess: a billet none of this names places nobody, as before.
 * Only billets in groups that are not line companies are read here; a line company's billets are {@see CavDesignation}'s.
 */
final class StaffBillet
{
    /** Holding group per exact billet title: [callsign, name, kind]. */
    private const HOLDING = [
        'ELOA' => ['ELOA', 'Extended Leave of Absence', UnitKind::Eloa],
        'Reservist' => ['RES', 'Reservists', UnitKind::Reserve],
        'New Recruit' => ['NR', 'New Recruits', UnitKind::Recruit],
    ];

    /** Department code → [callsign, name]. */
    private const DEPARTMENTS = [
        'S1' => ['S1', 'S1'],
        'S2' => ['S2', 'S2'],
        'S3' => ['S3', 'S3'],
        'S5' => ['S5', 'S5'],
        'S6' => ['S6', 'S6'],
        'S7' => ['S7', 'S7'],
        'WAG' => ['WAG', 'WAG'],
        'MP' => ['MP', 'MP'],
        'RRD' => ['RRD', 'RRD'],
        'RTC' => ['RTC', 'RTC'],
        'DEVCOM' => ['DEVCOM STAFF', 'DEVCOM Staff'],
    ];

    /** Billets that belong to a department without starting with its code, by exact title. */
    private const DEPARTMENT_TITLES = [
        'Regimental Technical Aide' => 'S6',
        'RDC Commander' => 'DEVCOM',
        'FCC Analyst' => 'DEVCOM',
    ];

    /** Billets that belong to a department without its code, by their leading words. */
    private const DEPARTMENT_PREFIXES = [
        'Cavalry Times' => 'S5',
        'ODS' => 'S7',
        'NCOA' => 'S7',
    ];

    /**
     * Regimental staff portfolios: callsign → [name, the officer's billet title in `Regimental HQ`].
     */
    public const PORTFOLIOS = [
        'SECOPS' => ['Security Operations', 'Regimental Security Operations Officer'],
        'RROO' => ['Recruiting Oversight', 'Regimental Recruiting Oversight Officer'],
        'IMO' => ['Information Management', 'Regimental Information Management Officer'],
    ];

    /**
     * The portfolio a new department is created under. Placeholder defaults until the product owner confirms the full
     * list; a department not named here sits under the regiment. Edit this one map to change the default.
     */
    public const DEPARTMENT_PORTFOLIO = [
        'S2' => 'SECOPS',
        'MP' => 'SECOPS',
        'RRD' => 'RROO',
        'RTC' => 'RROO',
        'S6' => 'IMO',
    ];

    /**
     * The group a billet places its holder in, or null: a holding group or a staff department.
     */
    public static function groupFor(string $title): ?GroupDesignation
    {
        $title = trim($title);

        if (isset(self::HOLDING[$title])) {
            [$callsign, $name, $kind] = self::HOLDING[$title];

            return new GroupDesignation($callsign, $name, $kind);
        }

        $code = self::departmentCode($title);

        return $code === null ? null : self::department($code);
    }

    /**
     * The portfolio a regimental staff officer's billet heads, or null.
     */
    public static function portfolioFor(string $title): ?GroupDesignation
    {
        foreach (self::PORTFOLIOS as $callsign => [, $billet]) {
            if ($billet === trim($title)) {
                return self::portfolio($callsign);
            }
        }

        return null;
    }

    public static function portfolio(string $callsign): GroupDesignation
    {
        return new GroupDesignation($callsign, self::PORTFOLIOS[$callsign][0], UnitKind::Staff);
    }

    private static function department(string $code): GroupDesignation
    {
        [$callsign, $name] = self::DEPARTMENTS[$code];

        return new GroupDesignation($callsign, $name, UnitKind::Staff, self::DEPARTMENT_PORTFOLIO[$code] ?? null);
    }

    private static function departmentCode(string $title): ?string
    {
        if (isset(self::DEPARTMENT_TITLES[$title])) {
            return self::DEPARTMENT_TITLES[$title];
        }

        $first = strtok($title, ' ');

        if ($first !== false && isset(self::DEPARTMENTS[$first])) {
            return $first;
        }

        foreach (self::DEPARTMENT_PREFIXES as $prefix => $code) {
            if ($title === $prefix || str_starts_with($title, "{$prefix} ")) {
                return $code;
            }
        }

        return null;
    }
}
