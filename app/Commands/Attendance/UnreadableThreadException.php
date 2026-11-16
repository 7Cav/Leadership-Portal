<?php

namespace App\Commands\Attendance;

use RuntimeException;

/**
 * A roll call thread the reply reader could not read as a whole: the link a leader gave is not a thread on the forum,
 * or the thread is longer than one run reads. Nothing of that week is written.
 */
class UnreadableThreadException extends RuntimeException
{
    public static function notAThread(): self
    {
        return new self('the roll call link is not a thread on the forum');
    }

    public static function tooLong(int $pages): self
    {
        return new self("the thread has more than {$pages} pages of replies");
    }
}
