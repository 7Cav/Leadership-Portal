<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A unit's guidon (#446): the flag in one of four `format`s. `band` is a rectangle with a slanted band of
 * `primary_color` across the top half and `secondary_color` below (the regiment's own guidon); `diagonal` puts `primary_color` on the top
 * left with `top_left_text` and `secondary_color` on the bottom right with `bottom_right_text`; `horizontal` puts the
 * first color and text on the top half and the second on the bottom half; `solid` is the first color only. In the top
 * band and the top and bottom formats each text sits `left` or `center` (`top_align`, `bottom_align`). `large_text` is an optional one
 * or two character mark at the hoist across both halves. UNIQUE on `unit_id`. Portal-owned
 * (AD-22): the Repository layer is its only writer, for the audited `SaveUnitGuidon` Command. The page draws the flag
 * from these fields (`resources/js/lib/guidon.js`); no image is stored.
 */
#[Fillable(['unit_id', 'format', 'primary_color', 'secondary_color', 'top_left_text', 'bottom_right_text', 'top_align', 'bottom_align', 'large_text', 'updated_by'])]
class UnitGuidon extends Model
{
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The design as the page draws it.
     *
     * @return array{format: string, primary: string, secondary: string, topLeft: string, bottomRight: string, topAlign: string, bottomAlign: string, large: string}
     */
    public function design(): array
    {
        return [
            'format' => $this->format ?? 'diagonal',
            'primary' => $this->primary_color,
            'secondary' => $this->secondary_color,
            'topLeft' => $this->top_left_text,
            'bottomRight' => $this->bottom_right_text,
            'topAlign' => $this->top_align ?? 'left',
            'bottomAlign' => $this->bottom_align ?? 'left',
            'large' => $this->large_text ?? '',
        ];
    }

    /**
     * The design to draw for `$unit` from every saved guidon keyed by unit id (read once by the page): its own, or with
     * `$inherit` the nearest ancestor's (a section's roll call shows its company's guidon), found by the unit's cached
     * `path`. Null when there is none. Runs no query of its own.
     *
     * @param  Collection<int, UnitGuidon>  $byUnit
     * @return array<string, string>|null
     */
    public static function designFor(Collection $byUnit, ?Unit $unit, bool $inherit = false): ?array
    {
        if ($unit === null) {
            return null;
        }
        if (! $inherit) {
            return $byUnit->get($unit->id)?->design();
        }

        $ancestors = array_reverse(array_map('intval', array_filter(explode('/', (string) $unit->path), 'strlen')));
        foreach ([$unit->id, ...$ancestors] as $id) {
            if ($byUnit->has($id)) {
                return $byUnit->get($id)->design();
            }
        }

        return null;
    }
}
