<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells a battalion's HQ staff that someone above them (an Admin or Regimental HQ staff who is not on that battalion's
 * HQ) changed the accountability options of a company, platoon or section in their battalion. Stored only (the
 * database channel), for the header's bell. Sent by the accountability options Command.
 */
class AccountabilityChanged extends Notification
{
    /**
     * @param  list<string>|null  $options  the labels now ticked, in tick-list order; null when the unit went back to its default
     */
    public function __construct(
        public readonly int $unitId,
        public readonly string $unit,
        public readonly string $actor,
        public readonly ?array $options,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{kind: string, message: string, url: string} */
    public function toArray(object $notifiable): array
    {
        $message = $this->options === null
            ? "{$this->actor} set {$this->unit}'s accountability options back to the default."
            : "{$this->actor} changed {$this->unit}'s accountability options to: ".implode(', ', $this->options).'.';

        return [
            'kind' => 'accountability',
            'message' => $message,
            'url' => "/roster/{$this->unitId}?view=admin",
        ];
    }
}
