<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells the leaders one level above a report's unit that someone asked to edit it after it locked, for the header's
 * bell. Stored only (the database channel). Sent by the report edit request Command, which
 * words the report and its link.
 */
class ReportEditRequested extends Notification
{
    public function __construct(
        public readonly string $requester,
        public readonly string $report,
        public readonly string $reason,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{kind: string, message: string, url: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'report_edit_requested',
            'message' => "{$this->requester} asks to edit {$this->report}: {$this->reason}",
            'url' => $this->url,
        ];
    }
}
