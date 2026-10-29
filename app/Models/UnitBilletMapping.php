<?php

namespace App\Models;

use Database\Factories\UnitBilletMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A curated billet → primary unit mapping (2.5, AD-18; any unit since 2026-10-24, not only a section): Cav's roster API names a trooper's billet
 * (`cav_position_id`), never their section, so this portal-owned table is the documented
 * bridge `Sync\SyncRosterHandler` reads to resolve `troopers.primary_section_id`.
 * Not synced: the Admin-triggered org-structure import (2.9) seeds it additively from the Cav
 * position groups, and repoints only a row it owns (`imported_unit_id` still equal to `unit_id`), never one
 * corrected by hand.
 */
#[Fillable(['unit_id', 'imported_unit_id', 'cav_position_id'])]
class UnitBilletMapping extends Model
{
    /** @use HasFactory<UnitBilletMappingFactory> */
    use HasFactory;

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
