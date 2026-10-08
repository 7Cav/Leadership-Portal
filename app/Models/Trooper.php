<?php

namespace App\Models;

use Database\Factories\TrooperFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cached Cav roster (AD-5, AD-22): `Sync\SyncRosterHandler` is this table's sole writer,
 * upserting keyed by `cav_id` — the stable internal `id` PK is what `attendance` and
 * `weekly_reports` key off, and is never reissued by a resync.
 *
 * `primary_section_id` is deliberately outside the synced columns: it is populated by 2.5's
 * mapping and 2.7's audited manual placement, never by the sync handler, so it is excluded from
 * `#[Fillable]` here to keep a future non-sync writer from being tempted to mass-assign it
 * alongside the synced fields.
 */
#[Fillable(['cav_id', 'rank', 'mos', 'tis', 'tig', 'status'])]
class Trooper extends Model
{
    /** @use HasFactory<TrooperFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tis' => 'date',
            'tig' => 'date',
            'status' => TrooperStatus::class,
        ];
    }

    public function primarySection(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'primary_section_id');
    }

    /**
     * Troopers currently assigned within `$unit`'s subtree: primary section anywhere under it (the
     * materialized-path prefix, AD-18) and not `Inactive` (discharged / transferred out). An LOA
     * or ELOA trooper is still assigned. The cache holds only the current placement, so this is
     * "assigned now", not a history.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function assignedWithin(Builder $query, Unit $unit): void
    {
        $query->where('troopers.status', '!=', TrooperStatus::Inactive->value)
            ->whereIn('troopers.primary_section_id', Unit::query()->select('id')->where('path', 'like', "{$unit->path}%"));
    }
}
