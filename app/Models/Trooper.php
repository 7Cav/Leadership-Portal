<?php

namespace App\Models;

use Database\Factories\TrooperFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * `username`, `forum_user_id` and `rank_short` are synced too (2.10): the forum identity 7Cav
 * names a member by. Render a trooper through `display_name`, never by hand.
 */
#[Fillable(['cav_id', 'username', 'forum_user_id', 'rank', 'rank_short', 'billet', 'mos', 'tis', 'tig', 'status', 'cav_position_id'])]
class Trooper extends Model
{
    /** The Cav milpac page for a trooper, keyed by their milpac id (`cav_id`). The one definition every screen links through. */
    public const MILPAC_PROFILE_URL = 'https://7cav.us/rosters/profile?uniqueid=';

    /** @use HasFactory<TrooperFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tis' => 'date',
            'tig' => 'date',
            'status' => TrooperStatus::class,
            'cav_position_id' => 'integer',
            'forum_user_id' => 'integer',
            'placement_source' => PlacementSource::class,
        ];
    }

    /**
     * How 7Cav names a member, `SGT Vasquez.R` — or `SGT #1234` (the `cav_id`) for a trooper with
     * no forum account linked. A missing short rank is dropped rather than left as a leading space.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => implode(' ', array_filter(
            [$this->rank_short, filled($this->username) ? $this->username : "#{$this->cav_id}"],
            fn (?string $part) => filled($part),
        )));
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
