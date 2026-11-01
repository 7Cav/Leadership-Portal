<?php

namespace App\Repositories\PortalRoleGrantRepository;

use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

class Eloquent implements PortalRoleGrantRepository
{
    public function grantedUnitsFor(User $user): Collection
    {
        return PortalRoleGrant::query()
            ->where('user_id', $user->getKey())
            ->tap($this->inForce(...))
            ->with('unit')
            ->get()
            ->pluck('unit');
    }

    public function grantedUnitIdsByUserId(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return PortalRoleGrant::query()
            ->whereIn('user_id', $userIds)
            ->tap($this->inForce(...))
            ->orderBy('id')
            ->get(['user_id', 'unit_id'])
            ->groupBy(fn (PortalRoleGrant $grant) => (int) $grant->user_id)
            ->map(fn ($grants) => $grants->map(fn (PortalRoleGrant $grant) => (int) $grant->unit_id)->values()->toBase())
            ->toBase();
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

    public function findById(int $id): ?PortalRoleGrant
    {
        return PortalRoleGrant::query()->find($id);
    }

    /**
     * Only grants with no end date, or whose end date has not passed: the moment it passes, the grant gives no scope,
     * before the scheduled revoke removes an ended one.
     */
    private function inForce(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }

    public function grant(User $user, Unit $unit, ?DateTimeInterface $expiresAt = null, ?int $renewEveryDays = null, ?int $setBy = null): PortalRoleGrant
    {
        $key = ['user_id' => $user->getKey(), 'unit_id' => $unit->getKey()];
        $expiry = $expiresAt === null ? [] : ['expires_at' => $expiresAt, 'renew_every_days' => $renewEveryDays, 'expiry_set_by' => $setBy];

        try {
            return PortalRoleGrant::query()->firstOrCreate($key, $expiry);
        } catch (UniqueConstraintViolationException $e) {
            // A racing grant committed the row after this transaction's snapshot: firstOrCreate's own
            // re-read is a plain (snapshot) read under MySQL REPEATABLE READ, so it cannot see it and
            // rethrows. A locking read sees the latest committed row (as SectionPracticeRepository does).
            return PortalRoleGrant::query()->where($key)->lockForUpdate()->first() ?? throw $e;
        }
    }

    public function revoke(User $user, Unit $unit): ?PortalRoleGrant
    {
        // A locking read, so a racing revoke that committed first is seen (null here) rather than
        // hidden by this transaction's snapshot, and the same row is never deleted twice.
        $grant = PortalRoleGrant::query()
            ->where('user_id', $user->getKey())
            ->where('unit_id', $unit->getKey())
            ->lockForUpdate()
            ->first();

        if ($grant === null) {
            return null;
        }

        $grant->delete();

        return $grant;
    }

    public function setExpiry(int $grantId, ?DateTimeInterface $expiresAt, ?int $renewEveryDays, ?int $setBy): ?PortalRoleGrant
    {
        $grant = PortalRoleGrant::query()->lockForUpdate()->find($grantId);

        if ($grant === null) {
            return null;
        }

        $grant->forceFill([
            'expires_at' => $expiresAt,
            'renew_every_days' => $expiresAt === null ? null : $renewEveryDays,
            'expiry_set_by' => $expiresAt === null ? null : $setBy,
        ])->save();

        return $grant;
    }

    public function endedBefore(DateTimeInterface $now): EloquentCollection
    {
        return PortalRoleGrant::query()
            ->whereNotNull('expires_at')
            ->whereNull('renew_every_days')
            ->where('expires_at', '<', $now)
            ->orderBy('id')
            ->get();
    }

    public function revokeById(int $grantId): ?PortalRoleGrant
    {
        $grant = PortalRoleGrant::query()->lockForUpdate()->find($grantId);
        $grant?->delete();

        return $grant;
    }

    public function hasGrantsOn(Unit $unit): bool
    {
        return PortalRoleGrant::query()->where('unit_id', $unit->getKey())->exists();
    }
}
