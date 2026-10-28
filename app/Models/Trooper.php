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
 * Since 2026-10-24 `primary_section_id` holds the trooper's primary **unit**, not only a section: a section for the line,
 * the HQ node itself for platoon, company, battalion and regimental staff, or a group (a staff department, ELOA,
 * Reservists, New Recruits). The column keeps its name; anything that needs a section asks for a `section` type (see
 * {@see assignedWithin()}). `last_line_unit_id` is the ELOA "was": the line unit the sync moved them out of into ELOA,
 * written and cleared by the same placement step.
 *
 * `cav_position_id` *is* synced: the trooper's primary Cav billet, overwritten on every run, so
 * the roster-derived command scope (1.16, `unit_command_mappings`) follows a billet change.
 *
 * `username`, `forum_user_id` and `rank_short` are synced too (2.10): the forum identity 7Cav
 * names a member by. Render a trooper through `display_name`, never by hand. So is `rank_image_url`,
 * the rank insignia the rollcall template prints (3.10), only ever an https URL on 7cav.us.
 */
#[Fillable(['cav_id', 'username', 'forum_user_id', 'rank', 'rank_short', 'rank_image_url', 'billet', 'mos', 'tis', 'tig', 'status', 'cav_position_id'])]
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

    /**
     * The trooper's role in their section, for the rollcall and its fireteams: `ASL` when their billet title says
     * assistant section leader (all Cav tells us about the second-in-command), `SL` when they hold the section's
     * command billet (1.16's mapping, passed in as `$sectionLeader`), otherwise null. The assistant's billet maps to
     * the section too, so the title is read first: an assistant named while the Section Leader billet is vacant is
     * still the ASL. The roster and the readiness matrix tag every echelon's command team instead
     * (`Milpac\BilletTags`, #443), which reads the same SL and ASL for a section.
     */
    public function roleUnder(?self $sectionLeader): ?string
    {
        if ($this->billet !== null && preg_match('/\bassistant section leader\b/i', $this->billet)) {
            return 'ASL';
        }

        return $sectionLeader !== null && $sectionLeader->id === $this->id ? 'SL' : null;
    }

    public function primarySection(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'primary_section_id');
    }

    public function lastLineUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'last_line_unit_id');
    }

    /**
     * Troopers currently assigned within `$unit`'s subtree: the measured population of the KPIs (AD-21). Primary unit
     * is a **section** anywhere under it (the materialized-path prefix, AD-18) and serving by `TrooperStatus::serving()`,
     * so never `Inactive` (gone from every Cav roster, AD-4). An LOA trooper is still assigned. HQ staff placed on a
     * platoon, company, battalion or the regiment, and everyone in a group, are on no rollcall, so they are left out
     * here: a non-leaf's live KPIs stay the sum of its sections' (decided 2026-10-24). {@see placedWithin()} counts
     * them. The cache holds only the current placement, so this is "assigned now", not a history.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function assignedWithin(Builder $query, Unit $unit): void
    {
        $query->whereIn('troopers.status', TrooperStatus::servingValues())
            ->whereIn('troopers.primary_section_id', Unit::query()->select('id')
                ->where('path', 'like', "{$unit->path}%")
                ->where('type', UnitType::Section->value));
    }

    /**
     * Every serving trooper placed anywhere in `$unit`'s subtree, HQ staff and groups included: the headcount the org
     * page and roster show as strength (decided 2026-10-24). Not a KPI population, see {@see assignedWithin()}.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function placedWithin(Builder $query, Unit $unit): void
    {
        $query->whereIn('troopers.status', TrooperStatus::servingValues())
            ->whereIn('troopers.primary_section_id', Unit::query()->select('id')->where('path', 'like', "{$unit->path}%"));
    }
}
