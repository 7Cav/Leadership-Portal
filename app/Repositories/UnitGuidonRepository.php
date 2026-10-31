<?php

namespace App\Repositories;

use App\Models\Unit;
use App\Models\UnitGuidon;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `unit_guidons` (AD-2, AD-22): the guidon design a unit's command saved (#446). One row per unit.
 */
interface UnitGuidonRepository
{
    /**
     * Every saved guidon, keyed by unit id. A handful of rows at most (companies and above), so the pages that show
     * them read them all at once.
     *
     * @return Collection<int, UnitGuidon>
     */
    public function all(): Collection;

    public function find(int $unitId): ?UnitGuidon;

    /**
     * The design to draw for each of `$units`, keyed by unit id (null for one with none), in one query however many
     * units: its own, or with `$inherit` the nearest ancestor's ({@see UnitGuidon::designFor()}).
     *
     * @param  iterable<Unit>  $units
     * @return array<int, array<string, string>|null>
     */
    public function designsFor(iterable $units, bool $inherit = false): array;

    /**
     * Save the unit's guidon, replacing any earlier one.
     *
     * @param  array{format: string, primary: string, secondary: string, topLeft: string, bottomRight: string, topAlign: string, bottomAlign: string, large: string}  $design
     */
    public function save(int $unitId, array $design, ?int $updatedBy): UnitGuidon;
}
