<?php

namespace App\Commands\ReportEdits;

use App\Support\Time\WeekLabel;
use DateTimeInterface;
use RuntimeException;

/**
 * A request to edit a report, or a decision on one, refused before anything is written. The message is the leader's
 * to read; `field` is the form field it belongs to.
 */
class ReportEditRefusedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'report')
    {
        parent::__construct($message);
    }

    public static function notSent(): self
    {
        return new self('This report has not been sent, so there is nothing to edit.');
    }

    public static function stillOpen(DateTimeInterface $until): self
    {
        return new self('This report can still be edited until '.WeekLabel::date($until).' '.WeekLabel::zulu($until).'. Choose Edit report instead.');
    }

    public static function reasonRequired(): self
    {
        return new self('Say why the report needs to change.', 'reason');
    }

    public static function pending(): self
    {
        return new self('An edit of this report is already requested and waiting on a leader above.');
    }

    public static function weekBeforeFloor(): self
    {
        return new self('This week is too far back to change.');
    }

    public static function unknown(): self
    {
        return new self('That request no longer exists.');
    }

    public static function decided(): self
    {
        return new self('That request was already decided.');
    }

    public static function noteTooLong(int $max): self
    {
        return new self("Keep the note to {$max} characters.", 'note');
    }

    public static function reasonTooLong(int $max): self
    {
        return new self("Keep the reason to {$max} characters.", 'reason');
    }
}
