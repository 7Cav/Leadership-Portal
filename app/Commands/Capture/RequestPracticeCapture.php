<?php

namespace App\Commands\Capture;

use DateTimeImmutable;

/**
 * A leader asks for a section's practice to be captured again (7.9: "Try again"). `$week` is any
 * moment in the practice's Cav week. Handled by {@see RequestPracticeCaptureHandler}, which answers
 * the {@see CaptureSectionPractice} to queue.
 */
final readonly class RequestPracticeCapture
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
    ) {}
}
