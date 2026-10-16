<?php

namespace App\Repositories;

use App\Models\FireteamAssignment;
use App\Models\FireteamOrder;
use App\Models\FireteamSetting;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `fireteam_assignments` and `fireteam_settings` (AD-2): a section's unofficial fireteams and how a
 * unit's rollcall post shows them. Written only by the audited fireteam Commands (`SetFireteamRole`,
 * `SetSectionFireteamOptions`, `SetCompanyFireteamPolicy`); read by the rollcall.
 */
interface FireteamRepository
{
    /**
     * Every fireteam row of the section, keyed by `trooper_id`. Includes rows of troopers no longer placed there; the
     * caller reads them against the section's current troopers.
     *
     * @return Collection<int, FireteamAssignment>
     */
    public function assignmentsIn(int $sectionId): Collection;

    /**
     * Make the trooper a fireteam leader of the section. A trooper who was a member of another fireteam leaves it.
     */
    public function makeLeader(int $sectionId, int $trooperId): FireteamAssignment;

    /**
     * Put the trooper in the fireteam the FTL `$leaderTrooperId` leads, moving them out of any other.
     */
    public function makeMember(int $sectionId, int $trooperId, int $leaderTrooperId): FireteamAssignment;

    /**
     * Take the trooper out of the section's fireteams. When they led one, its members are taken out with them, so no
     * row ever follows a trooper who is not an FTL. Returns the removed row (its attributes still set), or null when
     * the trooper had none.
     */
    public function remove(int $sectionId, int $trooperId): ?FireteamAssignment;

    /**
     * The unit's fireteam setting, or null when nothing was ever set for it.
     */
    public function settingFor(int $unitId): ?FireteamSetting;

    /**
     * Write the unit's fireteam setting, creating it if needed. Only the keys given change; a null value clears that
     * choice back to "not set".
     *
     * @param  array{fireteam_order?: ?FireteamOrder, show_fireteams?: ?bool, sl_asl_lead_fireteams?: ?bool}  $values
     */
    public function updateSetting(int $unitId, array $values): FireteamSetting;
}
