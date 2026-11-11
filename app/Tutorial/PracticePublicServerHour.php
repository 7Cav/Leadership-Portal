<?php

namespace App\Tutorial;

use App\Commands\StaffReports\PublicServerHour;
use App\Models\StaffReport;
use App\Models\Unit;
use Carbon\CarbonImmutable;

/**
 * The public server hour in the tutorial: the practice copy has no public game server to read, and the sandbox sends
 * nothing out, so a staff member who answers that they did a public server hour is shown one, as the servers would.
 * Bound by {@see TutorialSandbox::enter()} for the request.
 */
final class PracticePublicServerHour extends PublicServerHour
{
    /** What the practice servers show for the week. */
    public const MINUTES = 75;

    public function __construct() {}

    public function check(int $trooperId, Unit $hq, CarbonImmutable $weekStart): array
    {
        return ['check' => StaffReport::PRESENCE_CONFIRMED, 'minutes' => self::MINUTES, 'reason' => null];
    }
}
