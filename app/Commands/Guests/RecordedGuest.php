<?php

namespace App\Commands\Guests;

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;

/**
 * What {@see RecordGuestAttendanceHandler} did: the new `manual` entry at the host practice, and what became of the
 * automatic makeup on the guest's own rollcall: `marked` (written, or an earlier automatic one kept in place), `kept`
 * (their own mark stayed: present, attached, loa, a leader's mark, leave covering the week, or no longer placed there),
 * or `locked` (their own week may not change: report sent, practice confirmed).
 */
final readonly class RecordedGuest
{
    public function __construct(
        public CrossSectionSighting $sighting,
        public SectionPractice $practice,
        public string $makeup,
    ) {}
}
