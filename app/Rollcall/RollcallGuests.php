<?php

namespace App\Rollcall;

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * The rollcall's "Guests" list (decided 2026-10-23): everyone from another section recorded at this practice, by the
 * game-server capture or by the host leader's hand, in the shape the page and the guest endpoints share. A guest comes
 * from outside the section and often outside the leader's scope, so each row carries only what the list shows (name,
 * rank, own section designation, where the entry came from and who added it), never a milpac link.
 */
class RollcallGuests
{
    public function __construct(
        private readonly CrossSectionSightingRepository $sightings,
        private readonly UnitRepository $units,
    ) {}

    /**
     * @return list<array{trooperId: int, name: ?string, displayName: string, rank: ?string, section: ?string, source: string, recordedBy: ?string}>
     */
    public function forPractice(?SectionPractice $practice): array
    {
        if ($practice === null) {
            return [];
        }

        $rows = $this->sightings->forHostPractice($practice->id);

        if ($rows->isEmpty()) {
            return [];
        }

        $units = $this->units->tree()->keyBy('id');

        return $rows->map(fn (CrossSectionSighting $row) => $this->shape($row, $row->trooper, $units))->values()->all();
    }

    /**
     * One guest row; `$units` is the org tree keyed by id, for the guest's own section designation.
     *
     * @param  Collection<int, Unit>  $units
     * @return array{trooperId: int, name: ?string, displayName: string, rank: ?string, section: ?string, source: string, recordedBy: ?string}
     */
    public function shape(CrossSectionSighting $row, Trooper $trooper, Collection $units): array
    {
        $section = $units->get($trooper->primary_section_id);

        return [
            'trooperId' => $trooper->id,
            'name' => $trooper->username,
            'displayName' => $trooper->display_name,
            'rank' => $trooper->rank_short ?? $trooper->rank,
            'section' => $section instanceof Unit ? ($section->callsign ?? $section->name) : null,
            'source' => $row->source,
            // "Hale.S for Vasquez.R" when an Admin acting as the leader added them, "Hale.S acting as Section Leader
            // 2/1/A/1-7" when an Admin holding the billet did (#379).
            'recordedBy' => ! $row->isManual() ? null : match (true) {
                $row->recordedByAdmin !== null => "{$row->recordedByAdmin->username} for {$row->recordedBy?->username}",
                $row->recordedAsUnit !== null => "{$row->recordedBy?->username} acting as {$row->recordedAsUnit->billet()}",
                default => $row->recordedBy?->username,
            },
        ];
    }

    /**
     * {@see shape()} for one entry just written, reading the org tree itself.
     *
     * @return array{trooperId: int, name: ?string, displayName: string, rank: ?string, section: ?string, source: string, recordedBy: ?string}
     */
    public function one(CrossSectionSighting $row, Trooper $trooper): array
    {
        return $this->shape($row, $trooper, $this->units->tree()->keyBy('id'));
    }
}
