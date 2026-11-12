<?php

namespace App\Authorization;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What {@see PageAccess} answered for one page: the thing the page shows (a unit, a trooper, the HQs a staff reports
 * page lists) when the viewer may open it, else the status and message the page refuses with. The page's controller
 * calls {@see orAbort()}; the sidebar's bookmarks ask {@see allowed()}.
 *
 * @template T
 */
final class PageDecision
{
    /** @param T $subject */
    private function __construct(
        public readonly mixed $subject,
        public readonly int $status,
        public readonly string $message,
    ) {}

    /**
     * @template S
     *
     * @param  S  $subject
     * @return self<S>
     */
    public static function allow(mixed $subject = true): self
    {
        return new self($subject, 200, '');
    }

    /** @return self<null> */
    public static function deny(int $status, string $message = ''): self
    {
        return new self(null, $status, $message);
    }

    public function allowed(): bool
    {
        return $this->status === 200;
    }

    /**
     * The subject, or the refusal as the HTTP error the page answers with.
     *
     * @return T
     *
     * @throws HttpException
     */
    public function orAbort(): mixed
    {
        abort_unless($this->allowed(), $this->status, $this->message);

        return $this->subject;
    }
}
