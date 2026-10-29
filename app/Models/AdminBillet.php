<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A billet whose holder may be an Admin (decided by the S6, 2026-10-28), by Cav's stable position id: a billet in one of
 * the Cav groups `portal.admin_billet_groups` names (Regimental HQ and S6 HQ). An Admin confirms its title and group
 * (`title`, `group_title`); the org import and the roster sync record the names they see now (`proposed_*`) and put it
 * back to pending when those differ. Written only by `AdminBilletRepository`.
 */
#[Fillable(['cav_position_id', 'title', 'group_title', 'proposed_title', 'proposed_group_title', 'status', 'decided_by', 'decided_by_admin', 'decided_as_unit', 'decided_at'])]
class AdminBillet extends Model
{
    /** The Cav group of the regiment's own staff, whose billets the org import places on the regiment node. */
    public const REGIMENTAL_HQ = 'Regimental HQ';

    protected function casts(): array
    {
        return [
            'cav_position_id' => 'integer',
            'status' => AdminBilletStatus::class,
            'decided_by' => 'integer',
            'decided_by_admin' => 'integer',
            'decided_as_unit' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** Whether a billet in the Cav group `$group` may make its holder an Admin (`portal.admin_billet_groups`). */
    public static function isAdminGroup(?string $group): bool
    {
        $group = self::name($group);

        return $group !== null && in_array($group, array_map(fn ($name) => trim((string) $name), (array) config('portal.admin_billet_groups', [])), true);
    }

    /**
     * A billet title or group name as it is compared: trimmed, and null when nothing is left. Cav's two feeds do not
     * always agree on stray spaces, and a space must never read as a rename.
     */
    public static function name(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Whether a holder whose billet title is `$billet` is an Admin by this billet: approved, the names seen now are the
     * ones confirmed, the confirmed group is still one that may make an Admin, and the holder's own synced title is that
     * title too. The serving half of the rule is `ScopeResolver`'s; where the holder is placed does not matter.
     */
    public function confirmsHolder(?string $billet): bool
    {
        return $this->status === AdminBilletStatus::Approved
            && self::name($this->title) !== null
            && self::name($this->group_title) !== null
            && ! $this->renamed()
            && self::isAdminGroup($this->group_title)
            && self::name($billet) === self::name($this->title);
    }

    /**
     * Whether Cav lists this billet under Regimental HQ now (the group last seen, whatever was confirmed): what keeps its
     * holder's Regimental HQ read scope (`ScopeResolver`), approved or not.
     */
    public function inRegimentalHq(): bool
    {
        return trim((string) $this->proposed_group_title) === self::REGIMENTAL_HQ;
    }

    /** Whether the names seen now differ from the confirmed ones, or nothing was confirmed yet. */
    public function renamed(): bool
    {
        return self::name($this->proposed_title) !== self::name($this->title)
            || self::name($this->proposed_group_title) !== self::name($this->group_title);
    }
}
