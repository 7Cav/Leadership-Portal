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
 */
#[Fillable(['type', 'name', 'callsign'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
        ];
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
