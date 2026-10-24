<?php

namespace App\Commands\Units;

use App\Models\UnitType;

/**
 * Which Cav leadership billets command a unit, and which unit (story 1.16, AD-7) — the rule the
 * org-structure import records as `unit_command_mappings`, from which `ScopeResolver` derives a
 * leader's default scope:
 *
 *  - inside a line company: `Section Leader` / `Assistant Section Leader` → their section,
 *    `Platoon Leader` / `Platoon Sergeant` → their platoon, `Commander` / `Executive Officer` /
 *    `First Sergeant` → their company;
 *  - a `<bn> Command` group: `<bn> Commanding Officer` / `Executive Officer` / `Sergeant Major` →
 *    that battalion (ACD spells its command team `Auxiliary Commander` / `Executive Officer` /
 *    `Sergeant Major`);
 *  - the `Regimental HQ` group: `Regimental Commanding Officer` / `Executive Officer` /
 *    `Command Sergeant Major` → the regiment;
 *  - `DEVCOM Lead` (2026-10-24), in whatever group Cav lists it outside the line companies → the DEVCOM line units (the
 *    `DEVCOM` battalion node and everything under it), and `DEVCOM Lead <platoon>` inside the DEVCOM company → that
 *    platoon, the same rights a Platoon Leader has over a platoon. The ADR shows only the single `DEVCOM Lead`; the
 *    per-platoon form is accepted should Cav add one;
 *  - a regimental staff officer's billet (SecOps, Recruiting Oversight, IMO, {@see StaffBillet::PORTFOLIOS}) → their
 *    portfolio node, so they see the departments under it.
 *
 * Matching is exact, like {@see CavDesignation}'s: a role at an echelon it does not command
 * (`Section Leader 1/A/1-7`), or any title not listed, confers nothing rather than being guessed
 * into the nearest plausible unit — wrongly widening someone's scope is the failure that matters.
 */
final class CommandBillet
{
    /** A line-company role → the echelon of the designation it must carry. */
    private const LINE_ROLES = [
        'Section Leader' => UnitType::Section,
        'Assistant Section Leader' => UnitType::Section,
        'Platoon Leader' => UnitType::Platoon,
        'Platoon Sergeant' => UnitType::Platoon,
        'Commander' => UnitType::Company,
        'Executive Officer' => UnitType::Company,
        'First Sergeant' => UnitType::Company,
        // The DEVCOM lead of one DEVCOM platoon (`DEVCOM Lead 1/D/DEVCOM`), should Cav list one per platoon: the same
        // rights over it as a Platoon Leader (decided 2026-10-24).
        'DEVCOM Lead' => UnitType::Platoon,
    ];

    /** The single `DEVCOM Lead` billet the ADR lists under Development Command: it commands the DEVCOM line units. */
    private const DEVCOM_LEAD = 'DEVCOM Lead';

    /** The battalion designation the import gives the DEVCOM line units (from the `D/DEVCOM` group). */
    private const DEVCOM_BATTALION = 'DEVCOM';

    private const REGIMENTAL_GROUP = 'Regimental HQ';

    private const REGIMENTAL_TITLES = [
        'Regimental Commanding Officer',
        'Regimental Executive Officer',
        'Regimental Command Sergeant Major',
    ];

    /** Prefixed with the battalion designation: `1-7 Commanding Officer`. */
    private const BATTALION_ROLES = ['Commanding Officer', 'Executive Officer', 'Sergeant Major'];

    /** ACD's own spelling of its command team. */
    private const AUXILIARY_TITLES = ['Auxiliary Commander', 'Auxiliary Executive Officer', 'Auxiliary Sergeant Major'];

    private const AUXILIARY_BATTALION = 'ACD';

    /** A battalion command group's title: `1-7 Command`, `ACD Command`. */
    private const COMMAND_GROUP = '#^(?<battalion>[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*) Command$#';

    /**
     * The HQ node a non-line group's staff belong to (2026-10-24): the regiment for `Regimental HQ`, the battalion for a
     * `<bn> Command` group, null for any other group. The import places a non-command billet of such a group there
     * (`Regimental Chief of Staff`, `Aide to the SecOps`, ACD's `Reserve Commander`), so the whole HQ is counted in its
     * unit's strength; a command billet is already placed by its command mapping.
     */
    public static function headquartersOf(string $groupTitle): ?CavDesignation
    {
        $groupTitle = trim($groupTitle);

        if ($groupTitle === self::REGIMENTAL_GROUP) {
            return CavDesignation::regiment();
        }

        return preg_match(self::COMMAND_GROUP, $groupTitle, $m) === 1 ? CavDesignation::battalion($m['battalion']) : null;
    }

    /**
     * The unit a billet in a line-company group commands — `$designation` itself, as
     * {@see CavDesignation::fromPositionTitle()} parsed it from `$title` — or null when the
     * billet's role is not a leadership role of that echelon.
     */
    public static function inLineGroup(string $title, CavDesignation $designation): ?CavDesignation
    {
        $role = trim(substr(trim($title), 0, -strlen($designation->callsign())));

        return (self::LINE_ROLES[$role] ?? null) === $designation->type ? $designation : null;
    }

    /**
     * The unit a billet in a group that is not a line company commands: the regiment for a
     * regimental title in `Regimental HQ`, the battalion its `<bn> Command` group names, or the DEVCOM
     * battalion for `DEVCOM Lead`. Null
     * for every other billet — a regimental title listed in any other group included, since the
     * regiment is the widest scope there is.
     */
    public static function inCommandGroup(string $groupTitle, string $title): ?CavDesignation
    {
        $title = trim($title);
        $groupTitle = trim($groupTitle);

        if ($title === self::DEVCOM_LEAD) {
            return CavDesignation::battalion(self::DEVCOM_BATTALION);
        }

        if ($groupTitle === self::REGIMENTAL_GROUP) {
            return in_array($title, self::REGIMENTAL_TITLES, true) ? CavDesignation::regiment() : null;
        }

        if (preg_match(self::COMMAND_GROUP, $groupTitle, $m) !== 1) {
            return null;
        }

        $battalion = $m['battalion'];
        $titles = array_map(fn (string $role) => "{$battalion} {$role}", self::BATTALION_ROLES);

        if ($battalion === self::AUXILIARY_BATTALION) {
            $titles = [...$titles, ...self::AUXILIARY_TITLES];
        }

        return in_array($title, $titles, true) ? CavDesignation::battalion($battalion) : null;
    }
}
