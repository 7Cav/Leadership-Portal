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
    public function all(): Collection
    {
        return ChangelogEntry::query()->orderByDesc('date')->orderByDesc('id')->get();
    }

    public function find(int $id): ?ChangelogEntry
    {
        return ChangelogEntry::query()->find($id);
    }

    public function create(string $date, string $body, ?string $details, int $userId, bool $major = false): ChangelogEntry
    {
        return ChangelogEntry::query()->create([
            'date' => $date,
            'body' => $body,
            'details' => $details,
            'major' => $major,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    public function update(ChangelogEntry $entry, string $date, string $body, ?string $details, int $userId, bool $major = false): ChangelogEntry
    {
        $entry->fill(['date' => $date, 'body' => $body, 'details' => $details, 'major' => $major, 'updated_by' => $userId])->save();

        return $entry;
    }

    public function delete(ChangelogEntry $entry): ChangelogEntry
    {
        $entry->delete();

        return $entry;
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

    public function latestCreatedAt(): ?DateTimeInterface
    {
        $latest = ChangelogEntry::query()->max('created_at');

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
}
