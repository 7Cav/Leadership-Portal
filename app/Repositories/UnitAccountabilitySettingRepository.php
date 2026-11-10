<?php

namespace App\Repositories;

use App\Models\UnitAccountabilitySetting;

/**
 * Sole DB access for `unit_accountability_settings`. Writes come only from the audited `SetAccountabilityOptions` Command.
 */
interface UnitAccountabilitySettingRepository
{
    /**
     * Every unit's own options, by unit id. Small: one row per unit that set any.
     *
     * @return array<int, list<string>>
     */
    public function all(): array;

    /**
     * Set the unit's own options; null clears them, so it follows its ancestors again. Returns the row as it stands
     * (a cleared one as it stood).
     *
     * @param  list<string>|null  $options
     */
    public function set(int $unitId, ?array $options): ?UnitAccountabilitySetting;
}
