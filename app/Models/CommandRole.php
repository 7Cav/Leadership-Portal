<?php

namespace App\Models;

/**
 * The command billet a trooper holds in the unit their billet commands, by its short name: the tag the roster, the
 * Sheet and the readiness matrix print next to a leader (#443). Which unit a billet commands is the org import's
 * `unit_command_mappings` (1.16, `Commands\Units\CommandBillet`); this only names the billet within it, from the title
 * Cav publishes for it (`troopers.billet`, the same position the mapping is keyed by).
 *
 *  - a company: `Commander` CO, `Executive Officer` XO, `First Sergeant` 1SG (`Commander A/1-7`);
 *  - a platoon: `Platoon Leader` PL, `Platoon Sergeant` PSG;
 *  - a section: `Section Leader` SL, `Assistant Section Leader` ASL;
 *  - a battalion: `<bn> Commanding Officer` CO, `<bn> Executive Officer` XO, `<bn> Sergeant Major` SGM (ACD's
 *    `Auxiliary Commander`, `Auxiliary Executive Officer`, `Auxiliary Sergeant Major` alike);
 *  - the regiment: `Regimental Commanding Officer` CO, `Regimental Executive Officer` XO, `Regimental Command Sergeant
 *    Major` CSM.
 *
 * A staff portfolio's officer commands a group, which has no command team, so they get no tag.
 */
enum CommandRole: string
{
    case Co = 'CO';
    case Xo = 'XO';
    case FirstSergeant = '1SG';
    case SergeantMajor = 'SGM';
    case CommandSergeantMajor = 'CSM';
    case Pl = 'PL';
    case Psg = 'PSG';
    case Sl = 'SL';
    case Asl = 'ASL';

    /**
     * Each echelon's billets as a pattern on the title, case-insensitive like the rest of the billet matching. A line
     * title names its role first and the unit after (`Platoon Leader 1/A/1-7`), a battalion title the battalion first
     * (`1-7 Sergeant Major`), so the patterns anchor on the role end. `Section Leader` cannot match an assistant's
     * title, which starts with `Assistant`.
     */
    private const TITLES = [
        'section' => [
            '/^Section Leader\b/i' => self::Sl,
            '/^Assistant Section Leader\b/i' => self::Asl,
        ],
        'platoon' => [
            '/^Platoon Leader\b/i' => self::Pl,
            '/^Platoon Sergeant\b/i' => self::Psg,
        ],
        'company' => [
            '/^Commander\b/i' => self::Co,
            '/^Executive Officer\b/i' => self::Xo,
            '/^First Sergeant\b/i' => self::FirstSergeant,
        ],
        'battalion' => [
            '/(^|\s)Commanding Officer$/i' => self::Co,
            '/^Auxiliary Commander$/i' => self::Co,
            '/(^|\s)Executive Officer$/i' => self::Xo,
            '/(^|\s)Sergeant Major$/i' => self::SergeantMajor,
        ],
        'regiment' => [
            '/^Regimental Commanding Officer$/i' => self::Co,
            '/^Regimental Executive Officer$/i' => self::Xo,
            '/^Regimental Command Sergeant Major$/i' => self::CommandSergeantMajor,
        ],
    ];

    /**
     * The billet `$title` names in a unit of type `$commands`, or null for a title that is not one of that echelon's
     * command billets.
     */
    public static function fromBillet(?string $title, UnitType $commands): ?self
    {
        $title = trim((string) $title);

        foreach (self::TITLES[$commands->value] ?? [] as $pattern => $role) {
            if (preg_match($pattern, $title) === 1) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The billet that leads a unit of type `$type`: the CO of a company, battalion or the regiment, the PL of a
     * platoon, the SL of a section. Null for a group. An acting lead stands in for this billet ("Acting PL").
     */
    public static function leadOf(UnitType $type): ?self
    {
        return match ($type) {
            UnitType::Regiment, UnitType::Battalion, UnitType::Company => self::Co,
            UnitType::Platoon => self::Pl,
            UnitType::Section => self::Sl,
            UnitType::Group => null,
        };
    }
}
