<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit's guidon (#446): the swallowtail flag split on the diagonal, `primary_color` on the top left with
 * `top_left_text`, `secondary_color` on the bottom right with `bottom_right_text`. UNIQUE on `unit_id`. Portal-owned
 * (AD-22): the Repository layer is its only writer, for the audited `SaveUnitGuidon` Command. The page draws the flag
 * from these fields (`resources/js/lib/guidon.js`); no image is stored.
 */
#[Fillable(['unit_id', 'primary_color', 'secondary_color', 'top_left_text', 'bottom_right_text', 'updated_by'])]
class UnitGuidon extends Model
{
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The design as the page draws it.
     *
     * @return array{primary: string, secondary: string, topLeft: string, bottomRight: string}
     */
    public function design(): array
    {
        return [
            'primary' => $this->primary_color,
            'secondary' => $this->secondary_color,
            'topLeft' => $this->top_left_text,
            'bottomRight' => $this->bottom_right_text,
        ];
    }
}
