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
 *  - `Regimental Commanding Officer` / `Executive Officer` / `Command Sergeant Major` → the
 *    regiment.
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
    ];

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
     * The unit a billet in a group that is not a line company commands: the regiment, or the
     * battalion its `<bn> Command` group names. Null for every other billet.
     */
    public static function inCommandGroup(string $groupTitle, string $title): ?CavDesignation
    {
        $title = trim($title);

        if (in_array($title, self::REGIMENTAL_TITLES, true)) {
            return CavDesignation::regiment();
        }

        if (preg_match(self::COMMAND_GROUP, trim($groupTitle), $m) !== 1) {
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
