<?php

namespace App\Commands\Units;

use App\Models\UnitType;
use InvalidArgumentException;

/**
 * One node of the 7Cav chain of command, as the Cav roster spells it (story 2.9): the regiment,
 * a battalion (`1-7`, `ACD`), a company (`A/1-7`), a platoon (`1/A/1-7`) or a section
 * (`2/1/A/1-7`). The designation *is* the node's identity across imports — it becomes the
 * unit's `callsign`, and the importer matches existing nodes on type + callsign.
 *
 * Parsing is deliberately narrow. A title the rules below don't recognise is reported as
 * unparseable, never guessed into the nearest plausible node: a wrong mapping would silently
 * place troopers in someone else's section, while a skipped one only leaves them unmapped
 * (2.6 surfaces those).
 */
final readonly class CavDesignation
{
    public const REGIMENT_CALLSIGN = '7CAV';

    public const REGIMENT_NAME = '7th Cavalry Regiment';

    /** A company group's title: one company letter over a battalion designation. */
    private const COMPANY_GROUP = '#^(?<company>[A-Z])/(?<battalion>[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*)$#';

    private const NATO = [
        'A' => 'Alpha', 'B' => 'Bravo', 'C' => 'Charlie', 'D' => 'Delta', 'E' => 'Echo',
        'F' => 'Foxtrot', 'G' => 'Golf', 'H' => 'Hotel', 'I' => 'India', 'J' => 'Juliett',
        'K' => 'Kilo', 'L' => 'Lima', 'M' => 'Mike', 'N' => 'November', 'O' => 'Oscar',
        'P' => 'Papa', 'Q' => 'Quebec', 'R' => 'Romeo', 'S' => 'Sierra', 'T' => 'Tango',
        'U' => 'Uniform', 'V' => 'Victor', 'W' => 'Whiskey', 'X' => 'X-ray', 'Y' => 'Yankee',
        'Z' => 'Zulu',
    ];

    /**
     * Companies whose name doesn't follow the NATO letter, by callsign. B/ACD is a WW2 unit and is
     * called Baker Company; the rest of the ACD uses the NATO names like every other battalion.
     */
    private const NAMED_COMPANIES = [
        'B/ACD' => 'Baker Company',
    ];

    private function __construct(
        public UnitType $type,
        public ?string $battalion = null,
        public ?string $company = null,
        public ?int $platoon = null,
        public ?int $section = null,
    ) {}

    public static function regiment(): self
    {
        return new self(UnitType::Regiment);
    }

    /**
     * A battalion by its designation (`1-7`, `ACD`) — the unit a battalion command group's billets
     * command (1.16, {@see CommandBillet}).
     */
    public static function battalion(string $designation): self
    {
        return new self(UnitType::Battalion, $designation);
    }

    /**
     * The company a Cav position group stands for, or null when the group is not a line company
     * — `Regimental HQ`, `1-7 Command`, `Start-Up Games`, `Retired` and every other holding group
     * fail the `<letter>/<battalion>` shape, so they are excluded by what they are rather than
     * by a list of names that would go stale at the next reorg.
     */
    public static function companyGroup(string $groupTitle): ?self
    {
        if (preg_match(self::COMPANY_GROUP, trim($groupTitle), $m) !== 1) {
            return null;
        }

        return new self(UnitType::Company, $m['battalion'], $m['company']);
    }

    /**
     * The node a billet title names inside `$company`: the company itself (`Commander A/1-7`), a
     * platoon (`Platoon Leader 1/A/1-7`) or a section (`Trooper 2/1/A/1-7`). Null when the title
     * carries no recognisable designation, or names a *different* company than the group it is
     * listed in — Cav's live data has one such stray (`Section Leader 3/2/C/1-7` listed under
     * D/1-7), and following the title would map a D Company billet into C Company.
     */
    public static function fromPositionTitle(string $title, self $company): ?self
    {
        if ($company->type !== UnitType::Company) {
            throw new InvalidArgumentException('A position title is resolved against a company designation.');
        }

        $suffix = preg_quote($company->callsign(), '#');

        if (preg_match("#^\S.*\s(?:(?<first>[1-9]\d*)/)?(?:(?<second>[1-9]\d*)/)?{$suffix}$#", trim($title), $m) !== 1) {
            return null;
        }

        $first = ($m['first'] ?? '') !== '' ? (int) $m['first'] : null;
        $second = ($m['second'] ?? '') !== '' ? (int) $m['second'] : null;

        // Read left to right, a lone number lands in `first`: `1/A/1-7` is platoon 1, and only
        // `2/1/A/1-7` (two numbers) is section 2 of platoon 1.
        return match (true) {
            $first === null => $company,
            $second === null => new self(UnitType::Platoon, $company->battalion, $company->company, $first),
            default => new self(UnitType::Section, $company->battalion, $company->company, $second, $first),
        };
    }

    /** The highest numbered battalion, platoon or section offered when one is added by hand. */
    public const MAX_ORDINAL = 9;

    /**
     * The node an Admin adds by hand one echelon below `$parentCallsign`, picked by its ordinal: a number for a
     * battalion (`3` gives `3-7`), platoon or section, a letter for a company (`B` gives `B/3-7`). Built from the
     * parent's designation by the same rules the import uses, so a hand-made unit matches what a later import finds.
     * Null when the ordinal is out of range or the parent's designation does not have the shape its echelon needs.
     */
    public static function childOf(UnitType $type, ?string $parentCallsign, string $ordinal): ?self
    {
        $ordinal = strtoupper(trim($ordinal));
        $number = preg_match('#^[1-9]$#', $ordinal) === 1 ? (int) $ordinal : null;
        $letter = preg_match('#^[A-Z]$#', $ordinal) === 1 ? $ordinal : null;
        $parent = trim((string) $parentCallsign);

        return match ($type) {
            UnitType::Battalion => $number === null ? null : new self(UnitType::Battalion, "{$number}-7"),
            UnitType::Company => $letter === null || $parent === '' || str_contains($parent, '/')
                ? null
                : new self(UnitType::Company, $parent, $letter),
            UnitType::Platoon => $number !== null && preg_match(self::COMPANY_GROUP, $parent, $m) === 1
                ? new self(UnitType::Platoon, $m['battalion'], $m['company'], $number)
                : null,
            UnitType::Section => $number !== null && preg_match('#^(?<platoon>[1-9]\d*)/(?<rest>.+)$#', $parent, $p) === 1
                && preg_match(self::COMPANY_GROUP, $p['rest'], $m) === 1
                ? new self(UnitType::Section, $m['battalion'], $m['company'], (int) $p['platoon'], $number)
                : null,
            default => null,
        };
    }

    /**
     * The echelon one rank up. Null only for the regiment.
     */
    public function parent(): ?self
    {
        return match ($this->type) {
            UnitType::Regiment => null,
            UnitType::Battalion => self::regiment(),
            UnitType::Company => new self(UnitType::Battalion, $this->battalion),
            UnitType::Platoon => new self(UnitType::Company, $this->battalion, $this->company),
            UnitType::Section => new self(UnitType::Platoon, $this->battalion, $this->company, $this->platoon),
        };
    }

    /**
     * The 7Cav designation, stored as the unit's `callsign`: `7CAV`, `1-7`, `A/1-7`, `1/A/1-7`,
     * `2/1/A/1-7`.
     */
    public function callsign(): string
    {
        return match ($this->type) {
            UnitType::Regiment => self::REGIMENT_CALLSIGN,
            UnitType::Battalion => $this->battalion,
            UnitType::Company => "{$this->company}/{$this->battalion}",
            UnitType::Platoon => "{$this->platoon}/{$this->company}/{$this->battalion}",
            UnitType::Section => "{$this->section}/{$this->platoon}/{$this->company}/{$this->battalion}",
        };
    }

    /**
     * The identity a node is matched on across imports: type + callsign. The one definition both
     * sides of the match use — a designation via {@see key()}, an existing unit via
     * {@see keyFor()} — so the two can never drift into "every import duplicates the tree".
     */
    public function key(): string
    {
        return self::keyFor($this->type, $this->callsign());
    }

    public static function keyFor(UnitType $type, string $callsign): string
    {
        return "{$type->value}|{$callsign}";
    }

    /**
     * The display name a newly imported node gets. Only ever used on create — an Admin's rename
     * of an existing node is kept on every later import.
     */
    public function name(): string
    {
        return match ($this->type) {
            UnitType::Regiment => self::REGIMENT_NAME,
            UnitType::Battalion => self::battalionName($this->battalion),
            UnitType::Company => self::NAMED_COMPANIES[$this->callsign()] ?? (self::NATO[$this->company] ?? $this->company).' Company',
            UnitType::Platoon => self::ordinal($this->platoon).' Platoon',
            UnitType::Section => self::ordinal($this->section).' Section',
        };
    }

    private static function battalionName(string $designation): string
    {
        if (preg_match('#^(?<number>[1-9]\d*)-7$#', $designation, $m) === 1) {
            return self::ordinal((int) $m['number']).' Battalion';
        }

        return match ($designation) {
            'ACD' => 'Auxiliary Combat Detachment',
            'DEVCOM' => 'Development Command',
            // Anything Cav adds later keeps its designation as its name; an Admin
            // can rename them and the rename survives every later import.
            default => $designation,
        };
    }

    private static function ordinal(int $number): string
    {
        $suffix = match (true) {
            in_array($number % 100, [11, 12, 13], true) => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return "{$number}{$suffix}";
    }
}
