<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A billet the Cav lists, by its stable position id, with the name it carries now (`title`) and the name its placement
 * was confirmed under (`confirmed_title`). The same seen-against-confirmed check the Admin billets use
 * ({@see AdminBillet::renamed()}), for every placed billet: a rename never moves or drops the placement, it only waits for an
 * Admin to confirm the mapping follows the new name. Written only by `CavBilletRepository`, from the org import and the
 * roster sync.
 */
#[Fillable(['cav_position_id', 'title', 'group_title', 'confirmed_title', 'rule', 'unplaced_at'])]
class CavBillet extends Model
{
    /**
     * Cav groups whose billets belong to nobody serving: a billet listed in one is never new, and never asks an Admin
     * to place it.
     */
    public const NOT_SERVING_GROUPS = ['Retired', 'Wall of Honor', 'Rest in Peace', 'Discharged', 'Arlington', 'Past Members'];

    protected function casts(): array
    {
        return [
            'cav_position_id' => 'integer',
            'unplaced_at' => 'datetime',
        ];
    }

    /** The billet's placement, when it has one. */
    public function mapping(): HasOne
    {
        return $this->hasOne(UnitBilletMapping::class, 'cav_position_id', 'cav_position_id');
    }

    /** Whether the Cav name differs from the one its placement was confirmed under. */
    public function renamed(): bool
    {
        return $this->confirmed_title !== null && AdminBillet::name($this->confirmed_title) !== AdminBillet::name($this->title);
    }

    /** Whether the billet sits in a group of troopers who no longer serve. */
    public function notServing(): bool
    {
        return in_array(AdminBillet::name($this->group_title), self::NOT_SERVING_GROUPS, true);
    }
}
