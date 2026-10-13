<?php

namespace App\Milpac;

/**
 * The kinds of event on a trooper's service-record timeline (Story 6.6). Case order is the order that settles two
 * events on the same date, and the legend's order. A new kind (operations, discipline) is a new case here once
 * it has a source. `Rank` is the roster's current rank and date in grade: what we know of the last promotion.
 */
enum TimelineEventType: string
{
    case Rank = 'promotion';
    case Award = 'award';
    case Course = 'course';

    public function label(): string
    {
        return match ($this) {
            self::Rank => 'Rank',
            self::Award => 'Award',
            self::Course => 'Course',
        };
    }
}
