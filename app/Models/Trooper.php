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
 * The cached Cav roster (AD-5, AD-22): `Sync\SyncRosterHandler` is this table's sole writer of the
 * synced columns, upserting keyed by `cav_id` — the stable internal `id` PK is what `attendance` and
 * `weekly_reports` key off, and is never reissued by a resync.
 *
 * `primary_section_id` and `placement_source` are deliberately outside the synced columns: they are
 * populated by 2.5's mapping and 2.7's audited manual placement (the one audited exception to the
 * sync being the sole writer, touching only these two columns), so both are excluded from
 * `#[Fillable]` here to keep a future non-sync writer from being tempted to mass-assign them
 * alongside the synced fields. `placement_source` says which of the two set the section.
 *
 * `cav_position_id` *is* synced: the trooper's primary Cav billet, overwritten on every run, so
 * the roster-derived command scope (1.16, `unit_command_mappings`) follows a billet change.
 *
 * `username` and `cav_billet` (2.6) are synced display text: the milpac name and the billet title as
 * Cav reports them, so a leader can recognise an unplaced trooper and see which billet did not
 * resolve. Neither is ever used to match or place anyone (`cav_id` and `cav_position_id` are the keys).
 */
#[Fillable(['cav_id', 'username', 'rank', 'mos', 'tis', 'tig', 'status', 'cav_position_id', 'cav_billet'])]
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
            'cav_position_id' => 'integer',
            'placement_source' => PlacementSource::class,
        ];
    }

    public function primarySection(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'primary_section_id');
    }

    /**
     * Troopers currently assigned within `$unit`'s subtree: primary section anywhere under it (the
     * materialized-path prefix, AD-18) and not `Inactive` (gone from every Cav roster, AD-4). An LOA
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
