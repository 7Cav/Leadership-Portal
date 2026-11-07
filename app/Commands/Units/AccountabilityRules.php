<?php

namespace App\Commands\Units;

use App\Authorization\ScopeResolver;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitAccountabilitySettingRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * What counts for accountability on a unit, set per unit in two tiers. An Admin or Regimental HQ staff ticks a
 * battalion's options: what counts for its battalion HQ staff report, and the most any unit under it may use. The
 * battalion's HQ staff tick each company, platoon and section under it, inside that set: on a company or platoon what
 * counts for its HQ staff report, on a section what counts as a makeup for its troopers. A unit with no options of its
 * own follows the nearest unit above it (up to, not including, its battalion) that has some, else the default for its
 * echelon ({@see defaultFor()}, today's rules), and is always cut down to its battalion's own set. With nothing set,
 * nothing changes. Read-only; the write is {@see SetAccountabilityOptions}, which asks {@see mayChange()}.
 *
 * Where each option is read: the staff report offers SP (either SP option), FTX, operation, class and event, and its
 * section picker follows the SP options and "another game". A section's makeups (another section's practice) follow
 * the SP options and "another game". The public or training server hour has no input on either yet: it is stored and
 * shown, and read by nothing.
 */
class AccountabilityRules
{
    public const SP_OWN_UNIT = 'sp_own_unit';

    public const SP_BATTALION = 'sp_battalion';

    public const FTX = 'ftx';

    public const OPERATION = 'operation';

    public const CLASS_TYPE = 'class';

    public const EVENT = 'event';

    public const SERVER_HOUR = 'server_hour';

    public const SP_OTHER_GAME = 'sp_other_game';

    /** Every option, in the order the tick list shows them, with its label. */
    public const OPTIONS = [
        self::SP_OWN_UNIT => 'Section practice in own unit',
        self::SP_BATTALION => 'Section practice elsewhere in the battalion',
        self::FTX => 'FTX',
        self::OPERATION => 'Operation',
        self::CLASS_TYPE => 'S7 class',
        self::EVENT => 'Other official event',
        self::SERVER_HOUR => 'Public or training server hour with a leader present',
        self::SP_OTHER_GAME => 'Section practice in another game',
    ];

    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitAccountabilitySettingRepository $settings,
        private readonly TrooperRepository $troopers,
        private readonly ScopeResolver $scope,
    ) {}

    /** Whether a unit of this echelon has options at all: a battalion, company, platoon or section. */
    public static function configurable(Unit $unit): bool
    {
        return in_array($unit->type, [UnitType::Battalion, UnitType::Company, UnitType::Platoon, UnitType::Section], true);
    }

    /**
     * Today's rules, per echelon: a battalion HQ counts a section practice in the battalion, an operation, a class or
     * another event; a company or platoon HQ a section practice in its own unit or an FTX; a section takes a makeup at
     * any other section's practice.
     *
     * @return list<string>
     */
    public static function defaultFor(UnitType $type): array
    {
        return match ($type) {
            UnitType::Battalion => [self::SP_OWN_UNIT, self::SP_BATTALION, self::OPERATION, self::CLASS_TYPE, self::EVENT],
            UnitType::Company, UnitType::Platoon => [self::SP_OWN_UNIT, self::FTX],
            default => [self::SP_OWN_UNIT, self::SP_BATTALION, self::SP_OTHER_GAME],
        };
    }

    /**
     * The options in effect on `$unit`, where they come from (`own`, `inherited` from `from`, or `default`), and the
     * most it may use (`allowed`: its battalion's own set, else every option; a battalion may use every option).
     * `$tree` and `$own` save the reads when the caller has them.
     *
     * @param  array<int, list<string>>|null  $own
     * @return array{options: list<string>, source: string, from: ?Unit, allowed: list<string>}
     */
    public function effective(Unit $unit, ?Collection $tree = null, ?array $own = null): array
    {
        $byId = ($tree ?? $this->units->tree())->keyBy('id');
        $own ??= $this->settings->all();
        $battalion = self::battalionOf($unit, $byId);
        $allowed = $unit->type === UnitType::Battalion || $battalion === null || ! isset($own[$battalion->id])
            ? array_keys(self::OPTIONS) : self::ordered($own[$battalion->id]);

        if ($unit->type === UnitType::Battalion) {
            return isset($own[$unit->id])
                ? ['options' => self::ordered($own[$unit->id]), 'source' => 'own', 'from' => null, 'allowed' => $allowed]
                : ['options' => self::defaultFor($unit->type), 'source' => 'default', 'from' => null, 'allowed' => $allowed];
        }

        $options = null;
        $source = 'default';
        $from = null;
        for ($at = $unit, $guard = 0; $at !== null && $guard < 10 && $at->type !== UnitType::Battalion; $guard++) {
            if (isset($own[$at->id])) {
                $options = $own[$at->id];
                $source = $at->id === $unit->id ? 'own' : 'inherited';
                $from = $at->id === $unit->id ? null : $at;
                break;
            }
            $at = $at->parent_id === null ? null : $byId->get($at->parent_id);
        }

        $options = array_values(array_intersect(self::ordered($options ?? self::defaultFor($unit->type)), $allowed));

        return ['options' => $options, 'source' => $source, 'from' => $from, 'allowed' => $allowed];
    }

    /**
     * Whether a trooper of section `$ownSectionId` takes a makeup for attending `$hostSectionId`'s practice. A section
     * on the default with nothing set on its battalion takes every makeup, as before. Otherwise the host must be in
     * the section's own company ("in own unit") or elsewhere in its battalion, each as ticked, and play the same game
     * unless "another game" is ticked. A host outside the battalion never counts then.
     */
    public function makeupCounts(int $ownSectionId, int $hostSectionId): bool
    {
        $own = $this->settings->all();

        if ($own === []) {
            return true;
        }

        $tree = $this->units->tree();
        $byId = $tree->keyBy('id');
        $section = $byId->get($ownSectionId);
        $host = $byId->get($hostSectionId);

        if ($section === null || $host === null) {
            return true;
        }

        $rules = $this->effective($section, $tree, $own);
        $battalion = self::battalionOf($section, $byId);

        if ($rules['source'] === 'default' && ($battalion === null || ! isset($own[$battalion->id]))) {
            return true;
        }

        $company = $section;
        for ($guard = 0; $company !== null && $company->type !== UnitType::Company && $guard < 10; $guard++) {
            $company = $company->parent_id === null ? null : $byId->get($company->parent_id);
        }

        return self::sectionCounts($rules['options'], $company ?? $section, $battalion, $host, UnitGame::resolve($tree));
    }

    /**
     * Whether a practice of `$host` counts under `$options` for someone whose own unit is `$own`: inside `$own` with
     * "in own unit", inside `$battalion` but not `$own` with "elsewhere in the battalion", and in the same game as
     * `$own` unless "another game" is ticked (a unit with no game on its chain matches any).
     *
     * @param  list<string>  $options
     * @param  array<int, UnitGame>  $games
     */
    public static function sectionCounts(array $options, Unit $own, ?Unit $battalion, Unit $host, array $games): bool
    {
        $inOwn = str_starts_with((string) $host->path, (string) $own->path);
        $inBattalion = $battalion !== null && str_starts_with((string) $host->path, (string) $battalion->path);
        $place = ($inOwn && in_array(self::SP_OWN_UNIT, $options, true))
            || (! $inOwn && $inBattalion && in_array(self::SP_BATTALION, $options, true));
        $ownGame = ($games[$own->id] ?? null)?->gameId;
        $hostGame = ($games[$host->id] ?? null)?->gameId;

        return $place && ($ownGame === null || $hostGame === null || $ownGame === $hostGame || in_array(self::SP_OTHER_GAME, $options, true));
    }

    /**
     * What `$hq`'s staff report offers under its options: the accountability `types` (`sp` with either SP option, then
     * `ftx`, `operation`, `class`, `event` as ticked; the staff report's own values), whether the section picker
     * takes sections in the HQ's own unit (`own`), elsewhere in its battalion (`battalion`) and in another game
     * (`otherGames`), and `counts`, which says whether a section may be picked.
     *
     * @return array{types: list<string>, own: bool, battalion: bool, otherGames: bool, counts: \Closure(Unit): bool}
     */
    public function staff(Unit $hq): array
    {
        $tree = $this->units->tree();
        $options = $this->effective($hq, $tree)['options'];
        $battalion = self::battalionOf($hq, $tree->keyBy('id'));
        $games = UnitGame::resolve($tree);
        $has = fn (string $option) => in_array($option, $options, true);
        $types = array_values(array_filter([
            $has(self::SP_OWN_UNIT) || $has(self::SP_BATTALION) ? 'sp' : null,
            $has(self::FTX) ? 'ftx' : null,
            $has(self::OPERATION) ? 'operation' : null,
            $has(self::CLASS_TYPE) ? 'class' : null,
            $has(self::EVENT) ? 'event' : null,
        ]));

        return [
            'types' => $types,
            'own' => $has(self::SP_OWN_UNIT),
            // A battalion HQ's own unit is the battalion: "elsewhere" adds nothing there.
            'battalion' => $has(self::SP_BATTALION) && $hq->type !== UnitType::Battalion,
            'otherGames' => $has(self::SP_OTHER_GAME),
            'counts' => fn (Unit $section) => self::sectionCounts($options, $hq, $battalion, $section, $games),
        ];
    }

    /**
     * Whether `$user` may change `$unit`'s options: on a battalion, an Admin or Regimental HQ staff; on a company,
     * platoon or section under a battalion, an Admin or that battalion's HQ staff (a serving trooper placed on the
     * battalion node itself, the user's own milpac or the one they act as). A view-as preview never changes anything.
     */
    public function mayChange(User $user, Unit $unit): bool
    {
        if (! self::configurable($unit) || $this->scope->activeViewAs($user) !== null) {
            return false;
        }

        if ($this->scope->isAdmin($user)) {
            return true;
        }

        if ($unit->type === UnitType::Battalion) {
            return $this->scope->isRegimentalHqStaff($user);
        }

        $battalion = self::battalionOf($unit, $this->units->tree()->keyBy('id'));
        $trooper = $user->cav_id === null ? null : $this->troopers->byCavId([(int) $user->cav_id])->first();

        return $battalion !== null && $trooper !== null && $trooper->status->isServing() && $trooper->primary_section_id === $battalion->id;
    }

    /**
     * The roster's `accountability` prop: the options in effect and where they come from, the tick list (only the
     * options the unit may use), and whether the viewer may change it. Null for a unit that has none.
     *
     * @return array{kind: string, options: list<string>, source: string, from: ?string, choices: list<array{key: string, label: string}>, canEdit: bool}|null
     */
    public function props(User $user, Unit $unit): ?array
    {
        if (! self::configurable($unit)) {
            return null;
        }

        $rules = $this->effective($unit);

        return [
            'kind' => $unit->type === UnitType::Section ? 'makeup' : 'staff',
            'options' => $rules['options'],
            'source' => $rules['source'],
            'from' => $rules['from'] === null ? null : ($rules['from']->callsign ?? $rules['from']->name),
            'choices' => array_map(fn (string $key) => ['key' => $key, 'label' => self::OPTIONS[$key]], $rules['allowed']),
            'canEdit' => $this->mayChange($user, $unit),
        ];
    }

    /** The battalion `$unit` sits in (itself when it is one), walking up `$byId`; null for one under none. */
    public static function battalionOf(Unit $unit, Collection $byId): ?Unit
    {
        for ($at = $unit, $guard = 0; $at !== null && $guard < 10; $guard++) {
            if ($at->type === UnitType::Battalion) {
                return $at;
            }
            $at = $at->parent_id === null ? null : $byId->get($at->parent_id);
        }

        return null;
    }

    /**
     * Known options only, each once, in tick-list order.
     *
     * @param  list<string>  $options
     * @return list<string>
     */
    public static function ordered(array $options): array
    {
        return array_values(array_filter(array_keys(self::OPTIONS), fn (string $key) => in_array($key, $options, true)));
    }
}
