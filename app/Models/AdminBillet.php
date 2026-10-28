<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Regimental HQ billet whose holder may be an Admin (decided by the S6, 2026-10-28), by Cav's stable position id. An
 * Admin confirms its title and group (`title`, `group_title`); the org import and the roster sync record the names they
 * see now (`proposed_*`) and put it back to pending when those differ. Written only by `AdminBilletRepository`.
 */
#[Fillable(['cav_position_id', 'title', 'group_title', 'proposed_title', 'proposed_group_title', 'status', 'decided_by', 'decided_by_admin', 'decided_as_unit', 'decided_at'])]
class AdminBillet extends Model
{
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

    /**
     * Whether a holder whose billet title is `$billet` is an Admin by this billet: approved, the names seen now are the
     * ones confirmed, and the holder's own synced title is that title too. The placement half of the rule (serving, on
     * the regiment) is `ScopeResolver`'s.
     */
    public function confirmsHolder(?string $billet): bool
    {
        return $this->status === AdminBilletStatus::Approved
            && $this->title !== null
            && $this->group_title !== null
            && $this->proposed_title === $this->title
            && $this->proposed_group_title === $this->group_title
            && $billet === $this->title;
    }

    /** Whether the names seen now differ from the confirmed ones, or nothing was confirmed yet. */
    public function renamed(): bool
    {
        return $this->proposed_title !== $this->title || $this->proposed_group_title !== $this->group_title;
    }
}
