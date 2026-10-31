<?php

namespace App\Repositories\ChangelogRepository;

use App\Models\ChangelogEntry;
use App\Models\ChangelogState;
use App\Repositories\ChangelogRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements ChangelogRepository
{
    public function all(bool $includeTechnical = true): Collection
    {
        return ChangelogEntry::query()
            ->when(! $includeTechnical, fn ($query) => $query->where('technical', false))
            ->orderByDesc('date')->orderByDesc('id')->get();
    }

    public function find(int $id): ?ChangelogEntry
    {
        return ChangelogEntry::query()->find($id);
    }

    public function create(string $date, string $body, ?string $details, int $userId, bool $major = false, bool $technical = false): ChangelogEntry
    {
        return ChangelogEntry::query()->create([
            'date' => $date,
            'body' => $body,
            'details' => $details,
            'major' => $major,
            'technical' => $technical,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    public function update(ChangelogEntry $entry, string $date, string $body, ?string $details, int $userId, bool $major = false, bool $technical = false): ChangelogEntry
    {
        $entry->fill(['date' => $date, 'body' => $body, 'details' => $details, 'major' => $major, 'technical' => $technical, 'updated_by' => $userId])->save();

        return $entry;
    }

    public function delete(ChangelogEntry $entry): ChangelogEntry
    {
        $entry->forceDelete();

        return $entry;
    }

    public function deleteAll(DateTimeInterface $at): Collection
    {
        $at = CarbonImmutable::instance($at)->startOfSecond();

        // Through the base query, so `updated_at` stays as it was and a restore is exact.
        DB::transaction(function () use ($at) {
            ChangelogEntry::query()->toBase()->whereNull('deleted_at')->update(['deleted_at' => $at]);
            ChangelogEntry::onlyTrashed()->toBase()->where('deleted_at', '>', $this->cutoff($at))->update(['deleted_at' => $at]);
        });

        return $this->restorable($at);
    }

    public function restorable(DateTimeInterface $now): Collection
    {
        return ChangelogEntry::onlyTrashed()->where('deleted_at', '>', $this->cutoff($now))->orderBy('id')->get();
    }

    public function restore(DateTimeInterface $now): Collection
    {
        $ids = $this->restorable($now)->modelKeys();
        ChangelogEntry::onlyTrashed()->toBase()->whereIn('id', $ids)->update(['deleted_at' => null]);

        return ChangelogEntry::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    public function purgeDeleted(DateTimeInterface $now): int
    {
        return ChangelogEntry::onlyTrashed()->toBase()->where('deleted_at', '<=', $this->cutoff($now))->delete();
    }

    /** The moment before which a delete all can no longer be restored. */
    private function cutoff(DateTimeInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->subHours(ChangelogEntry::RESTORE_HOURS);
    }

    public function existingKeys(): array
    {
        return ChangelogEntry::query()->get(['date', 'body'])
            ->mapWithKeys(fn (ChangelogEntry $entry) => [self::key($entry->date->toDateString(), $entry->body) => true])
            ->all();
    }

    public static function key(string $date, string $body): string
    {
        return $date.'|'.mb_strtolower(trim($body));
    }

    public function latestCreatedAt(bool $includeTechnical = true): ?DateTimeInterface
    {
        $latest = ChangelogEntry::query()->when(! $includeTechnical, fn ($query) => $query->where('technical', false))->max('created_at');

        return $latest === null ? null : CarbonImmutable::parse($latest);
    }

    public function seenAt(int $userId): ?DateTimeInterface
    {
        $seen = DB::table('changelog_seen')->where('user_id', $userId)->value('seen_at');

        return $seen === null ? null : CarbonImmutable::parse($seen);
    }

    public function markSeen(int $userId, DateTimeInterface $at): void
    {
        DB::table('changelog_seen')->upsert(
            [['user_id' => $userId, 'seen_at' => CarbonImmutable::instance($at)->toDateTimeString()]],
            ['user_id'],
            ['seen_at'],
        );
    }

    public function acknowledgedDeployId(): ?string
    {
        return ChangelogState::query()->orderBy('id')->value('acknowledged_deploy_id');
    }

    public function acknowledge(?string $deployId): ChangelogState
    {
        $state = ChangelogState::query()->orderBy('id')->lockForUpdate()->first() ?? new ChangelogState;
        $state->acknowledged_deploy_id = $deployId;
        // Touched even when the id is unchanged, so the audit entry always points at a written row.
        $state->updated_at = now();
        $state->save();

        return $state;
    }

    public function technicalEnabled(): bool
    {
        return (bool) ChangelogState::query()->orderBy('id')->value('technical_enabled');
    }

    public function setTechnicalEnabled(bool $enabled): ChangelogState
    {
        $state = ChangelogState::query()->orderBy('id')->lockForUpdate()->first() ?? new ChangelogState;
        $state->technical_enabled = $enabled;
        // Touched even when unchanged, so the audit entry always points at a written row.
        $state->updated_at = now();
        $state->save();

        return $state;
    }
}
