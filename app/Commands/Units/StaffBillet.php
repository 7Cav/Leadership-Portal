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
 *  - **Portfolios** (decided 2026-10-25, from the wiki's Regimental Headquarters page): a department or holding group
 *    hangs under the portfolio that holds it, and a portfolio may sit under another ({@see PORTFOLIOS},
 *    {@see DEPARTMENT_PORTFOLIO}). Only a newly created node is put there; an Admin moves one with the audited Move,
 *    and the import never moves it back. A department with no portfolio sits under the regiment. Each portfolio names
 *    its officer's billet: the node shows that billet's serving holder as its lead (or vacant), and a billet that
 *    commands nothing else commands the portfolio, so its holder sees what is under it.
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
        'JAG' => ['JAG', 'JAG'],
        'DEVCOM' => ['DEVCOM STAFF', 'DEVCOM Staff'],
    ];

    /** Billets that belong to a department without starting with its code, by exact title. */
    private const DEPARTMENT_TITLES = [
        'Regimental Technical Aide' => 'S6',
        'FCC Analyst' => 'DEVCOM',
    ];

    /** Billets that belong to a department without its code, by their leading words. */
    private const DEPARTMENT_PREFIXES = [
        'Cavalry Times' => 'S5',
        'ODS' => 'S7',
        'NCOA' => 'S7',
        'Judge Advocate' => 'JAG',
    ];

    /**
     * The portfolio tree: callsign → [name, the officer's billet title, the portfolio it sits under or null for the
     * regiment]. With {@see DEPARTMENT_PORTFOLIO} and {@see HOLDING_PORTFOLIO}, the one place the default staff tree is
     * written down (product owner, 2026-10-25):
     *
     *   Executive Officer → Reserve & Development Command (RDC Commander) → DEVCOM staff, Reservists
     *   Recruiting Oversight → RRD, RTC, S5
     *   Security Operations → S2, MP, JAG
     *   Information Management → S6 (with the Regimental Technical Aides)
     *   S1, S3, S7, WAG: under the regiment until the product owner places them
     *
     * The Chief of Staff, Finance Officer and Adjutants General have no portfolio for now; they are Regimental HQ staff
     * on the regiment. Commanding nothing, they still read the whole regiment: ScopeResolver gives every serving trooper
     * placed on the regiment node read-only scope over it (policy, 2026-10-28). ACD stays a line battalion
     * under the regiment: line units never move into this tree.
     */
    public const PORTFOLIOS = [
        'XO' => ['Executive Officer', 'Regimental Executive Officer', null],
        'RDC' => ['Reserve & Development Command', 'RDC Commander', 'XO'],
        'RROO' => ['Recruiting Oversight', 'Regimental Recruiting Oversight Officer', null],
        'SECOPS' => ['Security Operations', 'Regimental Security Operations Officer', null],
        'IMO' => ['Information Management', 'Regimental Information Management Officer', null],
    ];

    /** The portfolio a new department is created under; a department not named here sits under the regiment. */
    public const DEPARTMENT_PORTFOLIO = [
        'DEVCOM' => 'RDC',
        'RRD' => 'RROO',
        'RTC' => 'RROO',
        'S5' => 'RROO',
        'S2' => 'SECOPS',
        'MP' => 'SECOPS',
        'JAG' => 'SECOPS',
        'S6' => 'IMO',
    ];

    /**
     * Line units a portfolio oversees, by callsign (policy, 2026-10-29; the wiki's Reserve & Development
     * Command page lists DEVCOM, ACD and the Reserve under RDC). The org page shows each as a reference row under the
     * portfolio in the staff card; the units stay in the line chain of command, nothing moves.
     */
    public const PORTFOLIO_LINE_UNITS = [
        'RDC' => ['ACD', 'DEVCOM'],
    ];

    /**
     * Staff groups a line unit lists as its own, by callsign (policy, 2026-10-29): DEVCOM's staff, whose
     * group sits under RDC. The org page shows it as a reference row under the line unit; the group does not move.
     */
    public const LINE_STAFF_GROUPS = [
        'DEVCOM' => ['DEVCOM STAFF'],
    ];

    /** The portfolio a new holding group is created under, by its callsign; the rest sit under the regiment. */
    public const HOLDING_PORTFOLIO = [
        'RES' => 'RDC',
    ];

    /**
     * The group a billet places its holder in, or null: a holding group or a staff department.
     */
    public static function groupFor(string $title, string $groupTitle = ''): ?GroupDesignation
    {
        $title = trim($title);

        if (isset(self::HOLDING[$title])) {
            [$callsign, $name, $kind] = self::HOLDING[$title];

            return new GroupDesignation($callsign, $name, $kind, self::HOLDING_PORTFOLIO[$callsign] ?? null);
        }

        // A JAG group's billets belong to JAG whatever they are called.
        $code = in_array(trim($groupTitle), ['JAG', 'Judge Advocate General'], true) ? 'JAG' : self::departmentCode($title);

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
        [$name, , $parent] = self::PORTFOLIOS[$callsign];

        return new GroupDesignation($callsign, $name, UnitKind::Staff, $parent);
    }

    /**
     * The billet title whose holder leads a portfolio.
     */
    public static function portfolioBillet(string $callsign): string
    {
        return self::PORTFOLIOS[$callsign][1];
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
