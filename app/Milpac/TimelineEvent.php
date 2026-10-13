<?php

namespace App\Milpac;

use Carbon\CarbonImmutable;

/**
 * One event on the service-record timeline (Story 6.6). `$order` is the tie-break among events of the same type
 * on the same day (the source row's position or id), so a reload never reshuffles them.
 */
final readonly class TimelineEvent
{
    public function __construct(
        public TimelineEventType $type,
        public CarbonImmutable $date,
        public string $title,
        public ?string $detail,
        public int $order,
    ) {}

    /**
     * @return array{type: string, date: string, title: string, detail: ?string}
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'date' => $this->date->toDateString(), 'title' => $this->title, 'detail' => $this->detail];
    }
}
