<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells whoever asked to edit a locked report that a leader above approved or denied it, for the header's bell. Stored
 * only (the database channel). Sent by the report edit request Command, which words the
 * report, the time it is unlocked until and its link.
 */
class ReportEditDecided extends Notification
{
    public function __construct(
        public readonly string $decider,
        public readonly string $report,
        public readonly bool $approved,
        public readonly ?string $until,
        public readonly ?string $note,
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
        $message = $this->approved
            ? "{$this->decider} approved your request to edit {$this->report}. You can edit it until {$this->until}."
            : "{$this->decider} denied your request to edit {$this->report}.";

        return [
            'kind' => 'report_edit_decided',
            'message' => $this->note === null ? $message : "{$message} {$this->note}",
            'url' => $this->url,
        ];
    }
}
