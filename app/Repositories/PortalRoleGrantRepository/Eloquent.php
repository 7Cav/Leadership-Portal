<?php

namespace App\Repositories\PortalRoleGrantRepository;

use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class Eloquent implements PortalRoleGrantRepository
{
    public function grantedUnitsFor(User $user): Collection
    {
        return PortalRoleGrant::query()
            ->where('user_id', $user->getKey())
            ->with('unit')
            ->get()
            ->pluck('unit');
    }

    public function all(): EloquentCollection
    {
        return PortalRoleGrant::query()->with(['user', 'unit'])->orderBy('id')->get();
    }

    public function find(User $user, Unit $unit): ?PortalRoleGrant
    {
        return PortalRoleGrant::query()
            ->where('user_id', $user->getKey())
            ->where('unit_id', $unit->getKey())
            ->first();
    }

    public function grant(User $user, Unit $unit): PortalRoleGrant
    {
        return PortalRoleGrant::query()->firstOrCreate([
            'user_id' => $user->getKey(),
            'unit_id' => $unit->getKey(),
        ]);
    }

    public function revoke(User $user, Unit $unit): ?PortalRoleGrant
    {
        $grant = $this->find($user, $unit);

        if ($grant === null) {
            return null;
        }

        $grant->delete();

        return $grant;
    }
}
