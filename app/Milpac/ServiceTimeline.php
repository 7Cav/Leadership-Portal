<?php

namespace App\Milpac;

use App\Models\CourseStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The service-record timeline (Story 6.6): what the milpac already holds, newest first. Three kinds of event
 * exist today: the current rank with its date in grade (the roster knows only the last promotion, not earlier
 * ones), earned awards and qualified courses that carry a date. A course with no award date cannot be placed in
 * time and is left to the courses panel; operations and discipline have no source yet, so they have no
 * {@see TimelineEventType} until one exists.
 *
 * A trooper whose grade date is their join date shows no rank event (they have not been promoted, as far as the
 * roster says), and a grade date after `$today` is a bad sync value, so it shows none either. Two events on one day
 * keep a fixed order: by type (the enum's case order), then by the source row, so a reload never reshuffles them.
 */
final class ServiceTimeline
{
    /**
     * @param  Collection<int, TrooperCourse>  $courseRows
     * @param  list<array{name: string, awardedAt: ?string, citation: ?string}>  $awards
     * @return array{entries: list<array{type: string, date: string, title: string, detail: ?string}>, legend: list<array{type: string, label: string}>}
     */
    public function build(Trooper $trooper, Collection $courseRows, array $awards, CarbonImmutable $today): array
    {
        $events = collect([
            ...$this->rankEvents($trooper, $today),
            ...$this->awardEvents($awards),
            ...$this->courseEvents($courseRows),
        ]);

        $priority = array_flip(array_column(TimelineEventType::cases(), 'value'));

        $sorted = $events->sort(fn (TimelineEvent $a, TimelineEvent $b) => $b->date <=> $a->date
            ?: $priority[$a->type->value] <=> $priority[$b->type->value]
            ?: $a->order <=> $b->order)->values();

        $present = $sorted->map(fn (TimelineEvent $event) => $event->type)->unique();

        return [
            'entries' => $sorted->map(fn (TimelineEvent $event) => $event->toArray())->all(),
            'legend' => collect(TimelineEventType::cases())
                ->filter(fn (TimelineEventType $type) => $present->contains($type))
                ->map(fn (TimelineEventType $type) => ['type' => $type->value, 'label' => $type->label()])
                ->values()
                ->all(),
        ];
    }

    /** @return list<TimelineEvent> */
    private function rankEvents(Trooper $trooper, CarbonImmutable $today): array
    {
        $since = $trooper->tig === null ? null : CarbonImmutable::parse($trooper->tig->toDateString(), 'UTC');

        if ($since === null || $trooper->rank === null || $since->toDateString() > $today->toDateString()
            || $since->toDateString() === $trooper->tis?->toDateString()) {
            return [];
        }

        return [new TimelineEvent(TimelineEventType::Rank, $since, "Current rank: {$trooper->rank}", null, 0)];
    }

    /**
     * @param  list<array{name: string, awardedAt: ?string, citation: ?string}>  $awards
     * @return list<TimelineEvent>
     */
    private function awardEvents(array $awards): array
    {
        $events = [];

        foreach ($awards as $index => $award) {
            if ($award['awardedAt'] !== null) {
                $events[] = new TimelineEvent(TimelineEventType::Award, CarbonImmutable::parse($award['awardedAt'], 'UTC'), $award['name'], $award['citation'], $index);
            }
        }

        return $events;
    }

    /**
     * @param  Collection<int, TrooperCourse>  $courseRows
     * @return list<TimelineEvent>
     */
    private function courseEvents(Collection $courseRows): array
    {
        return $courseRows
            ->filter(fn (TrooperCourse $row) => $row->status === CourseStatus::Qualified && $row->awarded_at !== null)
            ->map(fn (TrooperCourse $row) => new TimelineEvent(
                TimelineEventType::Course,
                CarbonImmutable::parse($row->awarded_at->toDateString(), 'UTC'),
                $row->course->name,
                $row->course->code,
                $row->id,
            ))
            ->values()
            ->all();
    }
}
