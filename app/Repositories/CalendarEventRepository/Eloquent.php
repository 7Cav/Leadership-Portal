<?php

namespace App\Repositories\CalendarEventRepository;

use App\Models\CalendarEvent;
use App\Repositories\CalendarEventRepository;
use App\Repositories\CalendarOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements CalendarEventRepository
{
    /** The length of the `title` column (Laravel's default `string`), so a long title is cut rather than refused. */
    private const TITLE_LENGTH = 255;

    /** The length of the `url` column; a longer link is dropped, not cut, since a cut link goes nowhere. */
    private const URL_LENGTH = 500;

    public function sync(array $occurrences, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::transaction(function () use ($occurrences, $from, $to) {
            // A weekly event read twice in one list (the calendar repeating itself) is written once.
            $read = [];
            foreach ($occurrences as $occurrence) {
                $read[$occurrence->key()] = $occurrence;
            }

            $uids = array_values(array_unique(array_map(fn (CalendarOccurrence $occurrence) => $occurrence->uid, $read)));
            $stored = [];
            foreach (array_chunk($uids, 500) as $chunk) {
                foreach (CalendarEvent::query()->whereIn('uid', $chunk)->get() as $event) {
                    $stored[$event->uid.'@'.$event->starts_at->utc()->format('Y-m-d H:i:s')] = $event;
                }
            }

            $inserted = 0;
            $updated = 0;

            foreach ($read as $key => $occurrence) {
                $values = [
                    'title' => mb_substr($occurrence->title, 0, self::TITLE_LENGTH),
                    'ends_at' => $occurrence->endsAt?->utc(),
                    'url' => $occurrence->url !== null && strlen($occurrence->url) <= self::URL_LENGTH ? $occurrence->url : null,
                ];

                $event = $stored[$key] ?? null;

                if ($event === null) {
                    CalendarEvent::query()->create(['uid' => $occurrence->uid, 'starts_at' => $occurrence->startsAt->utc(), ...$values]);
                    $inserted++;

                    continue;
                }

                $event->fill($values);

                if ($event->isDirty()) {
                    $event->save();
                    $updated++;
                }
            }

            $deleted = 0;
            $gone = CalendarEvent::query()
                ->where('starts_at', '>=', $from->utc())
                ->where('starts_at', '<', $to->utc())
                ->get()
                ->reject(fn (CalendarEvent $event) => isset($read[$event->uid.'@'.$event->starts_at->utc()->format('Y-m-d H:i:s')]))
                ->modelKeys();

            foreach (array_chunk($gone, 500) as $chunk) {
                $deleted += CalendarEvent::query()->whereKey($chunk)->delete();
            }

            return ['inserted' => $inserted, 'updated' => $updated, 'deleted' => $deleted];
        });
    }

    public function startingBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return CalendarEvent::query()
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->orderBy('starts_at')
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }
}
