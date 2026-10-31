<?php

namespace App\Commands\Practices;

use App\Authorization\ScopeResolver;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\BattalionSettingRepository;
use App\Repositories\TrooperRepository;

/**
 * How long a trooper must be on a practice's server inside its hour to count as attending it (#558): the battalion's
 * own minimum, set by its HQ staff, else `portal.presence_minimum_minutes` (40). Read-only; the write is
 * {@see SetPresenceMinimum}, which asks {@see mayChange()}.
 */
class PresenceMinimum
{
    public const LOWEST = 1;

    public const HIGHEST = 60;

    public function __construct(
        private readonly BattalionSettingRepository $settings,
        private readonly TrooperRepository $troopers,
        private readonly ScopeResolver $scope,
    ) {}

    public static function default(): int
    {
        return (int) config('portal.presence_minimum_minutes', 40);
    }

    /** The minimum for a practice of `$unitId` (a section, or any unit): its battalion's, else the default. */
    public function forUnit(int $unitId): int
    {
        $battalion = $this->settings->battalionOf($unitId);

        return ($battalion === null ? null : $this->settings->presenceMinimum($battalion->id)) ?? self::default();
    }

    /**
     * Whether `$user` may change `$battalion`'s minimum: its HQ staff (a serving trooper placed on the battalion node
     * itself, the user's own milpac or the one they act as) and Admins. A view-as preview is refused before a write
     * gets here (`ReadOnlyWhileViewingAs`), and a preview never changes anything, so it reads false.
     */
    public function mayChange(User $user, Unit $battalion): bool
    {
        if ($battalion->type !== UnitType::Battalion || $this->scope->activeViewAs($user) !== null) {
            return false;
        }

        if ($this->scope->isAdmin($user)) {
            return true;
        }

        $trooper = $user->cav_id === null ? null : $this->troopers->byCavId([(int) $user->cav_id])->first();

        return $trooper !== null && $trooper->status->isServing() && $trooper->primary_section_id === $battalion->id;
    }

    /**
     * The roster's `presenceMinimum` prop for a battalion: the minimum in effect, whether it is the default, and
     * whether the viewer may change it. Null for any other unit.
     *
     * @return array{minutes: int, isDefault: bool, default: int, canEdit: bool}|null
     */
    public function props(User $user, Unit $unit): ?array
    {
        if ($unit->type !== UnitType::Battalion) {
            return null;
        }

        $own = $this->settings->presenceMinimum($unit->id);

        return [
            'minutes' => $own ?? self::default(),
            'isDefault' => $own === null,
            'default' => self::default(),
            'canEdit' => $this->mayChange($user, $unit),
        ];
    }
}
