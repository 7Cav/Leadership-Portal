<?php

namespace App\Commands\Units;

use App\Models\UnitKind;

/**
 * Where a billet outside the line companies places its holder, read by the
 * org import from the Cav position groups. The one place that says which staff groups exist, what they are called and
 * which portfolio a department starts under:
 *
 *  - **Holding groups**, by exact billet title: `ELOA` → Extended Leave of Absence, `Reservist` → Reservists,
 *    `New Recruit` → New Recruits. Each sits directly under the regiment. The reserve's command team (`Reserve
 *    Commander`, `Reserve Executive Officer`) is placed with the Reservists too: Cav lists them in `ACD Command`, but
 *    the reserve is not ACD's, both sit under RDC ({@see HOLDING_TITLES}).
 *  - **Staff departments**, by the department code that starts the billet title, as the ADR lists them: S1, S2, S3, S5,
 *    S6, S7, ODS, NCOA, WAG, MP, RRD, RTC, JAG and DEVCOM (`S6 1IC`, `RRD Recruiter - Squad`, `ODS Lead Instructor`,
 *    `DEVCOM Support Clerk`). A few billets carry no code but belong to one: `Regimental Technical Aide` (S6),
 *    `Cavalry Times ...` (S5), `Judge Advocate ...` (JAG), `FCC Analyst` (DEVCOM staff). `Aide to the SecOps` sits on
 *    the Security Operations portfolio itself.
 *  - **Portfolios** (2026-10-25, reorganised 2026-11-10 with the General Staff oversight chart): a department or holding group
 *    hangs under the portfolio that holds it, and a portfolio may sit under another ({@see PORTFOLIOS},
 *    {@see DEPARTMENT_PORTFOLIO}). Only a newly created node is put there; an Admin moves one with the audited Move,
 *    and the import never moves it back. A department with no portfolio sits under the regiment. Each portfolio names
 *    its officer's billet: the node shows that billet's serving holder as its lead (or vacant), and a billet that
 *    commands nothing else commands the portfolio, so its holder sees what is under it.
 *  - **The whole staff tree is created** (2026-11-12): every portfolio and department here exists after an import, even
 *    one no Cav billet maps to yet ({@see defaultGroups()}), so JAG sits under the Executive Officer while Cav lists
 *    no JAG billet, as S3 or S7 do with no holder.
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

    /** Billets placed in a holding group whose title is not the group's own, by exact title → the group's billet. */
    private const HOLDING_TITLES = [
        'Reserve Commander' => 'Reservist',
        'Reserve Executive Officer' => 'Reservist',
    ];

    /** Department code → [callsign, name]. */
    private const DEPARTMENTS = [
        'S1' => ['S1', 'S1'],
        'S2' => ['S2', 'S2'],
        'S3' => ['S3', 'S3'],
        'S5' => ['S5', 'S5'],
        'S6' => ['S6', 'S6'],
        'S7' => ['S7', 'S7'],
        'ODS' => ['ODS', 'ODS'],
        'NCOA' => ['NCOA', 'NCOA'],
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

    /** Billets that sit on a portfolio itself without leading it, by exact title. */
    private const PORTFOLIO_TITLES = [
        'Aide to the SecOps' => 'SECOPS',
    ];

    /** Billets that belong to a department without its code, by their leading words. */
    private const DEPARTMENT_PREFIXES = [
        'Cavalry Times' => 'S5',
        'Judge Advocate' => 'JAG',
    ];

    /**
     * The portfolio tree: callsign → [name, the officer's billet title or null, the portfolio it sits under or null for
     * the regiment]. With {@see DEPARTMENT_PORTFOLIO} and {@see HOLDING_PORTFOLIO}, the one place the default staff tree
     * is written down, as the General Staff oversight chart of 2026-11-10 draws it:
     *
     *   Office of the Commanding Officer → Executive Officer
     *   Executive Officer → Reserve & Development Command (RDC Commander) → DEVCOM staff, Reservists; JAG
     *   Chief of Staff → S1, ODS, and the two Deputy Chiefs of Staff:
     *     Deputy Chief of Staff 1 → S5, RRD, RTC
     *     Deputy Chief of Staff 2 → S3, S7
     *   Security Operations → S2, MP (and the Aide to the SecOps)
     *   Information Management → S6 (with the Regimental Technical Aides)
     *   Command Sergeant Major → NCOA, WAG
     *
     * Both Deputy Chiefs of Staff hold the same billet, `Regimental Deputy Chief of Staff`, so no billet says which
     * deputy group is whose: those two name no billet, and an Admin makes each deputy acting lead of theirs. The
     * Recruiting Oversight portfolio went with the ROO billet on 2026-11-10. The Finance Officer has no portfolio; they
     * are Regimental HQ staff on the regiment, and like every serving trooper placed there read the whole regiment
     * (ScopeResolver). ACD stays a line battalion under the regiment: line units never move into this tree.
     *
     * The Office of the Commanding Officer (2026-11-12) is led by the regimental commander's billet. That billet commands
     * the regiment, as the XO's and CSM's do, so naming it the lead changes nobody's scope.
     *
     * @var array<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public const PORTFOLIOS = [
        'CO' => ['Office of the Commanding Officer', 'Regimental Commanding Officer', null],
        'XO' => ['Executive Officer', 'Regimental Executive Officer', 'CO'],
        'RDC' => ['Reserve & Development Command', 'RDC Commander', 'XO'],
        'COS' => ['Chief of Staff', 'Regimental Chief of Staff', null],
        'DCOS1' => ['Deputy Chief of Staff 1', null, 'COS'],
        'DCOS2' => ['Deputy Chief of Staff 2', null, 'COS'],
        'SECOPS' => ['Security Operations', 'Regimental Security Operations Officer', null],
        'IMO' => ['Information Management', 'Regimental Information Management Officer', null],
        'CSM' => ['Command Sergeant Major', 'Regimental Command Sergeant Major', null],
    ];

    /** The portfolio a new department is created under; a department not named here sits under the regiment. */
    public const DEPARTMENT_PORTFOLIO = [
        'DEVCOM' => 'RDC',
        'JAG' => 'XO',
        'S1' => 'COS',
        'ODS' => 'COS',
        'S5' => 'DCOS1',
        'RRD' => 'DCOS1',
        'RTC' => 'DCOS1',
        'S3' => 'DCOS2',
        'S7' => 'DCOS2',
        'S2' => 'SECOPS',
        'MP' => 'SECOPS',
        'S6' => 'IMO',
        'NCOA' => 'CSM',
        'WAG' => 'CSM',
    ];

    /**
     * Line units a portfolio oversees, by callsign: the wiki's Reserve & Development Command page lists DEVCOM, ACD and
     * the Reserve under RDC, and the oversight chart puts each battalion under the Chief of Staff or a deputy. The org
     * page shows each as a reference row under the portfolio in the staff card; the units stay in the line chain of
     * command, nothing moves, and the reference grants nothing (oversight is its own, Admin-set grant).
     */
    public const PORTFOLIO_LINE_UNITS = [
        'RDC' => ['ACD', 'DEVCOM'],
        'COS' => ['1-7'],
        'DCOS1' => ['3-7'],
        'DCOS2' => ['2-7'],
    ];

    /**
     * Staff groups a line unit lists as its own, by callsign: DEVCOM's staff, whose
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
        $holding = self::HOLDING_TITLES[$title] ?? $title;

        if (isset(self::HOLDING[$holding])) {
            [$callsign, $name, $kind] = self::HOLDING[$holding];

            return new GroupDesignation($callsign, $name, $kind, self::HOLDING_PORTFOLIO[$callsign] ?? null);
        }

        if (isset(self::PORTFOLIO_TITLES[$title])) {
            return self::portfolio(self::PORTFOLIO_TITLES[$title]);
        }

        // A JAG group's billets belong to JAG whatever they are called.
        $code = in_array(trim($groupTitle), ['JAG', 'Judge Advocate General'], true) ? 'JAG' : self::departmentCode($title);

        return $code === null ? null : self::department($code);
    }

    /**
     * Every portfolio and department of the default staff tree, whether or not a Cav billet maps to it, so the import
     * builds the whole tree: a department whose billets Cav does not list (JAG, 2026-11-12) still exists, empty, under
     * its portfolio. Holding groups are not here; each is created by the billet that fills it.
     *
     * @return list<GroupDesignation>
     */
    public static function defaultGroups(): array
    {
        return [
            ...array_map(fn (string $callsign) => self::portfolio($callsign), array_keys(self::PORTFOLIOS)),
            ...array_map(fn (string $code) => self::department($code), array_keys(self::DEPARTMENTS)),
        ];
    }

    /**
     * The portfolio a regimental staff officer's billet heads, or null.
     */
    public static function portfolioFor(string $title): ?GroupDesignation
    {
        foreach (self::PORTFOLIOS as $callsign => [, $billet]) {
            if ($billet !== null && $billet === trim($title)) {
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
     * The billet title whose holder leads a portfolio, or null for one an Admin gives an acting lead (a deputy).
     */
    public static function portfolioBillet(string $callsign): ?string
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
