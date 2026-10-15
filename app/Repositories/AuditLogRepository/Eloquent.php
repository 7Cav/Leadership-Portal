<?php

namespace App\Repositories\AuditLogRepository;

use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\TemplateVersion;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\AuditedBatch;
use App\Repositories\AuditLogRepository;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Eloquent implements AuditLogRepository
{
    /** A deadlock between two overlapping batches is retried, not surfaced (see the interface). */
    private const BATCH_ATTEMPTS = 3;

    /**
     * The target types an entry can be linked to, by the `class_basename` the log stores, with the relations a link to
     * each needs (see {@see targetsOf()}).
     *
     * @var array<string, array{class-string<Model>, list<string>}>
     */
    private const TARGETS = [
        'SectionPractice' => [SectionPractice::class, ['section']],
        'WeeklyReport' => [WeeklyReport::class, ['unit']],
        'Unit' => [Unit::class, []],
        'Trooper' => [Trooper::class, []],
        'TrooperGameIdentity' => [TrooperGameIdentity::class, ['trooper']],
        'TrooperCourse' => [TrooperCourse::class, ['trooper']],
        'TemplateVersion' => [TemplateVersion::class, ['company']],
        'PortalRoleGrant' => [PortalRoleGrant::class, []],
    ];

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

            // Digest the rows as stored, not as the mutation's in-memory models happen to hold
            // them: a freshly created model lacks the columns it never set, so the same persisted
            // state would otherwise digest differently depending on whether it was created or found.
            $this->reloadStored($batch->target);
            $members = collect($batch->members)
                ->each(fn (Model $model) => $this->reloadStored($model))
                ->sort(fn (Model $a, Model $b) => [$a::class, $a->getKey()] <=> [$b::class, $b->getKey()])
                ->map(fn (Model $model) => $this->sortedAttributes($model))
                ->values()
                ->all();

            $this->append($actorId, $action, $reason, $batch->target, [
                'target' => $this->sortedAttributes($batch->target),
                'members' => $members,
            ]);

            return $batch;
        }, attempts: self::BATCH_ATTEMPTS);
    }

    /**
     * Raw attributes, sorted by key: independent of insertion order and of any loaded relation or
     * `$hidden`/`$visible` cast. Only as complete as the model's loaded attributes — the batch path
     * refreshes its models first so the digest reflects the stored row.
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
     * Re-read the stored row into `$model` with a locking read. Not `refresh()`: that is a plain
     * read, and under MySQL REPEATABLE READ it cannot see a row a racing submit committed after
     * this transaction's snapshot — a row the batch found through its repositories' locking
     * fallbacks and may not have modified itself.
     */
    private function reloadStored(Model $model): void
    {
        $stored = $model->newQueryWithoutScopes()->whereKey($model->getKey())->sharedLock()->firstOrFail();
        $model->setRawAttributes($stored->getAttributes(), sync: true);
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
        ?Unit $withinUnit = null,
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
            ->when($withinUnit, fn ($query, Unit $unit) => $query->where(fn ($query) => $this->aboutSubtree($query, $unit)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function targetsOf(iterable $entries): array
    {
        $idsByType = [];
        foreach ($entries as $entry) {
            if (isset(self::TARGETS[$entry->target_type]) && ctype_digit($entry->target_id)) {
                $idsByType[$entry->target_type][$entry->target_id] = (int) $entry->target_id;
            }
        }

        $targets = [];
        foreach ($idsByType as $type => $ids) {
            [$class, $with] = self::TARGETS[$type];

            foreach ($class::query()->with($with)->whereKey(array_values($ids))->get() as $model) {
                $targets["{$type}#{$model->getKey()}"] = $model;
            }
        }

        return $targets;
    }

    /**
     * Entries whose target sits in `$unit`'s subtree (see the interface). Every branch is a subquery over the path prefix,
     * so the filter costs the same however large the subtree. `target_id` is a string column, so ids are compared as text.
     *
     * @param  Builder<AuditLogEntry>  $query
     */
    private function aboutSubtree(Builder $query, Unit $unit): void
    {
        $units = fn () => Unit::query()->select('id')->where('path', 'like', "{$unit->path}%");
        $troopers = fn () => Trooper::query()->select('id')->whereIn('primary_section_id', $units());
        $about = fn (string $type, Builder $ids) => fn ($query) => $query->where('target_type', $type)->whereIn('target_id', $ids->toBase()->selectRaw('CAST(id AS CHAR)'));

        $query->where($about('Unit', Unit::query()->where('path', 'like', "{$unit->path}%")))
            ->orWhere($about('SectionPractice', SectionPractice::query()->whereIn('section_id', $units())))
            ->orWhere($about('WeeklyReport', WeeklyReport::query()->whereIn('unit_id', $units())))
            ->orWhere($about('TemplateVersion', TemplateVersion::query()->whereIn('company_id', $units())))
            ->orWhere($about('PortalRoleGrant', PortalRoleGrant::query()->whereIn('unit_id', $units())))
            ->orWhere($about('Trooper', Trooper::query()->whereIn('primary_section_id', $units())))
            ->orWhere($about('TrooperGameIdentity', TrooperGameIdentity::query()->whereIn('trooper_id', $troopers())))
            ->orWhere($about('TrooperCourse', TrooperCourse::query()->whereIn('trooper_id', $troopers())));
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
