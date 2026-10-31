<?php

namespace App\Commands\Units;

use App\Authorization\ScopeResolver;
use App\Models\UnitGuidon;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitGuidonRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;

/**
 * Saves a unit's guidon as one audited action (`unit.guidon_saved`, AD-16, no reason), #446. Companies and above only
 * (decided 2026-10-28): a company, a battalion or the regiment. Who may: the unit's command team, the battalion or
 * regimental command above it, or an Admin ({@see ScopeResolver::commandsUnit()}); a staff grant does not, and
 * neither does a platoon or section leader beneath it. A view-as preview never gets here (the read-only middleware).
 *
 * The page draws the flag as SVG from these fields, so each is checked here as well as in the controller: a color is
 * exactly `#rrggbb`, a text at most {@see MAX_TEXT} letters, digits, `.`, `/`, `-` or spaces,
 * a format and a placement one of a fixed list, the large character one or two letters or digits. Nothing else can reach the
 * drawing. An unchanged design writes nothing and logs nothing.
 */
class SaveUnitGuidonHandler
{
    public const ACTION = 'unit.guidon_saved';

    public const MAX_TEXT = 6;

    // `\z`, not `$`: `$` would let a trailing newline through.
    public const COLOR_PATTERN = '/^#[0-9a-f]{6}\z/';

    public const TEXT_PATTERN = '/^[A-Za-z0-9.\/\- ]*\z/';

    /** The large character at the hoist: none, or one or two letters or digits. */
    public const LARGE_PATTERN = '/^[A-Za-z0-9]{0,2}\z/';

    /** How the flag is split. A guidon saved before formats is `diagonal`. */
    public const FORMATS = ['band', 'diagonal', 'horizontal', 'solid'];

    /** Where a text sits on its half in the top and bottom formats. */
    public const ALIGNS = ['left', 'center'];

    /** The echelons that carry a guidon. */
    public const TYPES = [UnitType::Regiment, UnitType::Battalion, UnitType::Company];

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly UnitGuidonRepository $guidons,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws GuidonForbiddenException when the actor does not command the unit, or it does not exist.
     * @throws InvalidGuidonException when the unit carries no guidon, or a field breaks the rules above.
     */
    public function handle(User $actor, SaveUnitGuidon $command): UnitGuidon
    {
        $unit = $this->units->find($command->unitId);

        if ($unit === null || ! $this->scope->commandsUnit($actor, $unit)) {
            throw new GuidonForbiddenException($command->unitId);
        }

        if (! in_array($unit->type, self::TYPES, true)) {
            throw InvalidGuidonException::notAGuidonUnit();
        }

        // The same keys in the same order as UnitGuidon::design(), so an unchanged design compares equal.
        $design = [
            'format' => self::oneOf($command->format, self::FORMATS, 'format'),
            'primary' => self::color($command->primaryColor, 'first color'),
            'secondary' => self::color($command->secondaryColor, 'second color'),
            'topLeft' => self::text($command->topLeftText, 'top left text'),
            'bottomRight' => self::text($command->bottomRightText, 'bottom right text'),
            'topAlign' => self::oneOf($command->topAlign, self::ALIGNS, 'top text placement'),
            'bottomAlign' => self::oneOf($command->bottomAlign, self::ALIGNS, 'bottom text placement'),
            'large' => preg_match(self::LARGE_PATTERN, $command->largeText) === 1
                ? $command->largeText
                : throw InvalidGuidonException::badField('large character'),
        ];

        $current = $this->guidons->find($unit->id);
        if ($current !== null && $current->design() === $design) {
            return $current;
        }

        // Who saved it, as the log names them: the Admin while they act as someone.
        [$by] = $this->acting->attribute($actor->id);

        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->guidons->save($unit->id, $design, $by),
        );
    }

    private static function color(string $value, string $field): string
    {
        $value = strtolower($value);

        return preg_match(self::COLOR_PATTERN, $value) === 1 ? $value : throw InvalidGuidonException::badField($field);
    }

    /** @param  list<string>  $allowed */
    private static function oneOf(string $value, array $allowed, string $field): string
    {
        return in_array($value, $allowed, true) ? $value : throw InvalidGuidonException::badField($field);
    }

    private static function text(string $value, string $field): string
    {
        $value = trim($value);

        return mb_strlen($value) <= self::MAX_TEXT && preg_match(self::TEXT_PATTERN, $value) === 1
            ? $value
            : throw InvalidGuidonException::badField($field);
    }
}
