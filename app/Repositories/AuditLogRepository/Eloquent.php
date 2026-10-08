<?php

namespace App\Repositories\AuditLogRepository;

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Repositories\AuditedBatch;
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

            $this->append($actorId, $action, $reason, $result, $this->sortedAttributes($result));

            return $result;
        });
    }

    public function recordBatchWithMutation(int $actorId, string $action, ?string $reason, Closure $mutation): AuditedBatch
    {
        return DB::transaction(function () use ($actorId, $action, $reason, $mutation) {
            $batch = $mutation();

            if (! $batch instanceof AuditedBatch) {
                throw new RuntimeException(sprintf(
                    'AuditLogRepository::recordBatchWithMutation() mutation for action "%s" must return an %s, got %s.',
                    $action,
                    AuditedBatch::class,
                    get_debug_type($batch),
                ));
            }

            $members = collect($batch->members)
                ->sort(fn (Model $a, Model $b) => [$a::class, $a->getKey()] <=> [$b::class, $b->getKey()])
                ->map(fn (Model $model) => $this->sortedAttributes($model))
                ->values()
                ->all();

            $this->append($actorId, $action, $reason, $batch->target, [
                'target' => $this->sortedAttributes($batch->target),
                'members' => $members,
            ]);

            return $batch;
        });
    }

    /**
     * Raw attributes, sorted by key: independent of insertion order and of any loaded relation or
     * `$hidden`/`$visible` cast, so two rows over identical content always digest identically
     * regardless of how the model reached this class.
     *
     * @return array<string, mixed>
     */
    private function sortedAttributes(Model $model): array
    {
        $attributes = $model->getAttributes();
        ksort($attributes);

        return $attributes;
    }

    /**
     * @param  array<mixed>  $digested  the content `content_digest` hashes
     */
    private function append(int $actorId, string $action, ?string $reason, Model $target, array $digested): void
    {
        AuditLogEntry::query()->forceCreate([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => class_basename($target),
            'target_id' => (string) $target->getKey(),
            'reason' => $reason,
            'content_digest' => hash('sha256', json_encode($digested, JSON_THROW_ON_ERROR)),
            'created_at' => now(),
        ]);
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
