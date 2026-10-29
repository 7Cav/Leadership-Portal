<?php

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the chain of command (AD-18): adjacency list (`parent_id`) plus a cached
 * materialized `path`. Portal-owned (AD-22) — the write side (Commands/Units) is the sole
 * author; there is no synced writer.
 *
 * `path` and `parent_id` are not mass-assignable: both are computed by the Repository layer
 * inside a transaction, never taken from caller-supplied form data directly. Deliberately
 * not naming that class here — Deptrac forbids `Model → Repository` (AD-1), and pint's
 * `fully_qualified_strict_types` fixer turns any `{@see}` reference into a real `use` import,
 * which would quietly create that reverse edge.
 *
 * `kind` says whether the node is on the line or a group off it ({@see UnitKind}); it is written once, by the creating
 * repository call, and never mass-assigned. `label` is an optional team label an Admin sets by hand, shown next to the
 * name: nothing in Cav's data names it. `game_id` is the game the unit plays, an Admin's pick from the Games list;
 * a unit without one plays the game of the nearest unit above it that has one
 * (worked out by `UnitGame` in the repository layer). Not mass-assigned. `lead_position_id` is the billet whose holder
 * leads a regimental staff portfolio the import made (shown as its lead, or vacant); written by the import only.
 */
#[Fillable(['type', 'name', 'callsign', 'label'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
            'kind' => UnitKind::class,
            'lead_position_id' => 'integer',
            'game_id' => 'integer',
        ];
    }

    /**
     * What the unit's leader is called, with the unit: `Section Leader 2/1/A/1-7`. Cav's data names no billet title,
     * so it follows the unit's type. Used where an Admin acts as the billet (#379).
     */
    public function billet(): string
    {
        $title = match ($this->type) {
            UnitType::Regiment => 'Regimental Commander',
            UnitType::Battalion => 'Battalion Commander',
            UnitType::Company => 'Company Commander',
            UnitType::Platoon => 'Platoon Leader',
            UnitType::Section => 'Section Leader',
            default => 'Leader',
        };

        return $title.' '.($this->callsign ?? $this->name);
    }

    public function isLine(): bool
    {
        return $this->kind === null || $this->kind->isLine();
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Unit::class, 'parent_id');
    }
}
