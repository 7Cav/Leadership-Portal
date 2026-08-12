<?php

namespace App\Repositories\AuditLogRepository;

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Repositories\AuditLogRepository;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Eloquent implements AuditLogRepository
{
    public function recordWithMutation(int $actorId, string $action, ?string $reason, Closure $mutation): mixed
    {
        return DB::transaction(function () use ($actorId, $action, $reason, $mutation) {
            $result = $mutation();

            if (! $result instanceof Model) {
                throw new RuntimeException(sprintf(
                    'AuditLogRepository::recordWithMutation() mutation for action "%s" must return an Eloquent Model, got %s.',
                    $action,
                    get_debug_type($result),
                ));
            }

            // Raw attributes, sorted by key: independent of insertion order and of any loaded
            // relation or `$hidden`/`$visible` cast, so two rows over identical content always
            // digest identically regardless of how the model reached this method.
            $attributes = $result->getAttributes();
            ksort($attributes);

            AuditLogEntry::query()->forceCreate([
                'actor_id' => $actorId,
                'action' => $action,
                'target_type' => class_basename($result),
                'target_id' => (string) $result->getKey(),
                'reason' => $reason,
                'content_digest' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);

            return $result;
        });
    }

    public function filtered(
        ?int $actorId = null,
        ?string $action = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        return AuditLogEntry::query()
            ->with('actor')
            ->when($actorId, fn ($query, $value) => $query->where('actor_id', $value))
            ->when($action, fn ($query, $value) => $query->where('action', $value))
            ->when($targetType, fn ($query, $value) => $query->where('target_type', $value))
            ->when($targetId, fn ($query, $value) => $query->where('target_id', $value))
            ->when($dateFrom, fn ($query, $value) => $query->where('created_at', '>=', Carbon::parse($value)->startOfDay()))
            ->when($dateTo, fn ($query, $value) => $query->where('created_at', '<', Carbon::parse($value)->addDay()->startOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function actors(): Collection
    {
        return User::query()
            ->whereIn('id', AuditLogEntry::query()->select('actor_id')->distinct())
            ->orderBy('username')
            ->get();
    }

    public function actions(): Collection
    {
        return AuditLogEntry::query()->select('action')->distinct()->orderBy('action')->pluck('action');
    }
}
