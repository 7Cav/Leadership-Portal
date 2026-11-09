<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Notifications\AccountabilityChanged;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Notification;

/**
 * After {@see SetAccountabilityOptions} stored a change on a company, platoon or section, tells its battalion's HQ
 * staff in the bell ({@see AccountabilityChanged}): the serving troopers placed on the battalion node itself who have
 * a portal account. Only when the change came from above them: nobody is told when the actor is on that battalion's
 * HQ staff (their own milpac or the one they act as), and nobody is told about the battalion's own set. The actor is
 * never told.
 */
class AccountabilityChangeNotifier
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly UserRepository $users,
    ) {}

    /** @param  list<string>|null  $options  the options now stored, null when the unit went back to its default */
    public function changed(User $actor, Unit $unit, ?array $options): void
    {
        if ($unit->type === UnitType::Battalion) {
            return;
        }

        $battalion = AccountabilityRules::battalionOf($unit, $this->units->tree()->keyBy('id'));

        if ($battalion === null) {
            return;
        }

        $staff = $this->troopers->placedIn($battalion->id);

        if ($actor->cav_id !== null && $staff->contains(fn ($trooper) => (int) $trooper->cav_id === (int) $actor->cav_id)) {
            return;
        }

        $recipients = $this->users->withCavIds($staff->pluck('cav_id')->map(fn ($id) => (int) $id)->all())
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $own = $actor->cav_id === null ? null : $this->troopers->byCavId([(int) $actor->cav_id])->first();

        Notification::send($recipients, new AccountabilityChanged(
            $unit->id,
            $unit->callsign ?? $unit->name,
            $own?->display_name ?? $actor->username,
            $options === null ? null : array_map(fn (string $key) => AccountabilityRules::OPTIONS[$key], $options),
        ));
    }
}
