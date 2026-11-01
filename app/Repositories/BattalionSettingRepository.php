<?php

namespace App\Repositories;

use App\Models\BattalionSetting;
use App\Models\Unit;

/**
 * Sole DB access for `battalion_settings` (#558). Writes come only from the audited `SetPresenceMinimum` Command.
 */
interface BattalionSettingRepository
{
    /** The battalion `$unitId` sits in (itself when it is one), or null for a unit under no battalion. */
    public function battalionOf(int $unitId): ?Unit;

    /** The battalion's own presence minimum in minutes, or null when it has none set. */
    public function presenceMinimum(int $battalionId): ?int;

    /** Set the battalion's presence minimum; null goes back to the default. */
    public function setPresenceMinimum(int $battalionId, ?int $minutes): BattalionSetting;
}
