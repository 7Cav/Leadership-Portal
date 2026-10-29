<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The regimental staff officer who oversees a unit (decided by the S6, 2026-10-29): an Adjutant General over a
 * battalion, the RDC Commander over ACD, DEVCOM and the Reservists. Portal-owned (AD-22), one per unit. ScopeResolver
 * reads it as read-only scope over the unit's subtree while the overseer serves, exactly like a staff grant, never as
 * write. Written only by the audited `SetUnitOversightHandler`.
 */
#[Fillable(['unit_id', 'trooper_id', 'set_by', 'set_by_admin', 'set_as_unit'])]
class UnitOversight extends Model
{
    protected function casts(): array
    {
        return [
            'unit_id' => 'integer',
            'trooper_id' => 'integer',
            'set_by' => 'integer',
            'set_by_admin' => 'integer',
            'set_as_unit' => 'integer',
        ];
    }

    /**
     * Whether `$unit` takes an overseer: a battalion (1-7, 2-7, 3-7, ACD, DEVCOM) or the Reservists. Nothing else for now
     * (decided 2026-10-29, the smallest set the S6 named).
     */
    public static function canOversee(Unit $unit): bool
    {
        return $unit->type === UnitType::Battalion || $unit->kind === UnitKind::Reserve;
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }
}
