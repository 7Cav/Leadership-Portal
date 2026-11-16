<?php

namespace App\Commands\Attendance;

/**
 * What one run of {@see ReadRollcallRepliesHandler} did: the weeks whose thread it read, the signatures it set from a
 * reply and took back, the replies whose answer it did not recognise, and the weeks it could not read.
 */
final readonly class RollcallRepliesRead
{
    public function __construct(
        public int $weeks = 0,
        public int $signed = 0,
        public int $cleared = 0,
        public int $unrecognised = 0,
        public int $weeksFailed = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->weeks + $other->weeks,
            $this->signed + $other->signed,
            $this->cleared + $other->cleared,
            $this->unrecognised + $other->unrecognised,
            $this->weeksFailed + $other->weeksFailed,
        );
    }

    public function withWeeksFailed(int $weeksFailed): self
    {
        return new self($this->weeks, $this->signed, $this->cleared, $this->unrecognised, $weeksFailed);
    }

    public function summary(): string
    {
        return sprintf(
            'Read %d roll call %s: %d %s set, %d taken back, %d %s with no answer recognised%s.',
            $this->weeks,
            $this->weeks === 1 ? 'thread' : 'threads',
            $this->signed,
            $this->signed === 1 ? 'signature' : 'signatures',
            $this->cleared,
            $this->unrecognised,
            $this->unrecognised === 1 ? 'reply' : 'replies',
            $this->weeksFailed === 0 ? '' : ", {$this->weeksFailed} could not be read",
        );
    }
}
