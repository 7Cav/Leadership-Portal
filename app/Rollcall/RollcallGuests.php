<?php

namespace App\Rollcall;

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * The rollcall's "Guests" list (decided 2026-10-23): everyone from another section recorded at this practice, by the
 * game-server capture or by the host leader's hand, in the shape the page and the guest endpoints share. A guest comes
 * from outside the section and often outside the leader's scope, so each row carries only what the list shows (name,
 * rank, own section designation, where the entry came from and who added it), never a milpac link.
 *
 * Staff who said they attended (a staff practice entry, source `staff`, from their staff report or their milpac) are
 * listed after the guests: as a staff guest (`staffGuest`) when the section is under the HQ they
 * were placed on when the week ended (their platoon or company), and as a guest otherwise. Shown only: they never count
 * in the section's numbers, KPIs, strength, JSAM counts or makeups, are never on its roster, and the host leader
 * cannot remove them.
 */
class RollcallGuests
{
    public function __construct(
        private readonly CrossSectionSightingRepository $sightings,
        private readonly UnitRepository $units,
        private readonly TrooperPlacementRepository $placements,
    ) {}

    /**
     * `$makeupMissing` are the troopers whose automatic makeup is missing, worked out by the caller from the makeup
     * rules: a hand-added one among them is offered "Retry makeup".
     *
     * @param  list<int>  $makeupMissing
     * @return list<array{trooperId: int, name: ?string, displayName: string, rank: ?string, section: ?string, source: string, recordedBy: ?string, makeupMissing: bool, staffGuest: bool}>
     */
    public function forPractice(?SectionPractice $practice, array $makeupMissing = []): array
    {
        if ($practice === null) {
            return [];
        }

        $rows = $this->sightings->forHostPractice($practice->id);
        $staff = $this->sightings->staffForHostPractice($practice->id);

        if ($rows->isEmpty() && $staff->isEmpty()) {
            return [];
        }

        $units = $this->units->tree()->keyBy('id');
        $host = $units->get($practice->section_id);

        return $rows->map(fn (CrossSectionSighting $row) => $this->shape($row, $row->trooper, $units, in_array($row->trooper_id, $makeupMissing, true)))
            ->concat($staff->map(fn (CrossSectionSighting $row) => [
                ...$this->shape($row, $row->trooper, $units),
                'recordedBy' => self::enteredBy($row),
                'staffGuest' => $host instanceof Unit && $this->underOwnHq($row->trooper, $practice, $host, $units),
            ]))
            ->values()
            ->all();
    }

    /**
     * Whether the host section is under the HQ the staff member was placed on when the practice's week ended: their own
     * platoon or company, which lists them as a staff guest.
     *
     * @param  Collection<int, Unit>  $units
     */
    private function underOwnHq(Trooper $trooper, SectionPractice $practice, Unit $host, Collection $units): bool
    {
        $hq = $units->get($this->placements->unitOfTrooperAtWeekEnd($trooper, $practice->week_start));

        return $hq instanceof Unit && $hq->id !== $host->id && str_starts_with($host->path, $hq->path);
    }

    /** Who entered a staff entry, as the milpac words it ("Hale.S for Vasquez.R" for an Admin acting as them). */
    private static function enteredBy(CrossSectionSighting $row): ?string
    {
        return match (true) {
            $row->recordedByAdmin !== null => "{$row->recordedByAdmin->username} for {$row->recordedBy?->username}",
            $row->recordedAsUnit !== null => "{$row->recordedBy?->username} acting as {$row->recordedAsUnit->billet()}",
            default => $row->recordedBy?->username,
        };
    }

    /**
     * One guest row; `$units` is the org tree keyed by id, for the guest's own section designation. `makeupMissing` is
     * set only on a hand-added guest, the one kind a leader can retry.
     *
     * @param  Collection<int, Unit>  $units
     * @return array{trooperId: int, name: ?string, displayName: string, rank: ?string, section: ?string, source: string, recordedBy: ?string, makeupMissing: bool, staffGuest: bool}
     */
    public function shape(CrossSectionSighting $row, Trooper $trooper, Collection $units, bool $makeupMissing = false): array
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
            'makeupMissing' => $makeupMissing && $row->isManual(),
            'staffGuest' => false,
        ];
    }

    /**
     * {@see shape()} for one entry just written, reading the org tree itself.
     *
     * @return array{trooperId: int, name: ?string, displayName: string, rank: ?string, section: ?string, source: string, recordedBy: ?string, makeupMissing: bool, staffGuest: bool}
     */
    public function one(CrossSectionSighting $row, Trooper $trooper, bool $makeupMissing = false): array
    {
        return $this->shape($row, $trooper, $this->units->tree()->keyBy('id'), $makeupMissing);
    }
}
