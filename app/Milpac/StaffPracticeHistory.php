<?php

namespace App\Milpac;

use App\Models\CrossSectionSighting;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\PracticeCapturePlayerRepository;

/**
 * The section practices a staff member attended (#448), newest week first, for their milpac and the roster sheet. Two
 * kinds of entry, one per host practice:
 *
 *  - `entered`: a `cross_section_sightings` row with source `staff`, entered by the staff member themselves (a
 *    self-report, flagged so their leader can tell), their staff leader or an Admin;
 *  - `capture`: a practice whose latest game-server capture saw them through a linked game identity
 *    (`practice_capture_players`, the capture's own record of who it saw but did not credit). The capture reads the
 *    whole Cav week so far, so it says they were on the server that week, not that they were at that practice; the
 *    screens say so. Shown only for someone who is staff now.
 *
 * A practice with both shows the entry. Read only: who may add or remove one is the controller's to say.
 */
class StaffPracticeHistory
{
    public function __construct(
        private readonly CrossSectionSightingRepository $sightings,
        private readonly PracticeCapturePlayerRepository $captures,
    ) {}

    /**
     * @return list<array{week: string, sectionId: int, section: ?string, source: string, enteredBy: ?string, selfReported: bool}>
     */
    public function for(Trooper $trooper, bool $withCaptures): array
    {
        $entries = $this->sightings->staffEntriesFor($trooper->id);
        $entered = $entries->pluck('section_practice_id')->flip();

        $rows = $entries->map(fn (CrossSectionSighting $row) => [
            ...$this->practice($row->practice),
            'source' => 'entered',
            'enteredBy' => match (true) {
                $row->recordedByAdmin !== null => "{$row->recordedByAdmin->username} for {$row->recordedBy?->username}",
                $row->recordedAsUnit !== null => "{$row->recordedBy?->username} acting as {$row->recordedAsUnit->billet()}",
                default => $row->recordedBy?->username,
            },
            // Their own word: entered while signed in as themselves, not by someone acting as them.
            'selfReported' => $row->recordedByAdmin === null && $row->recordedBy?->cav_id !== null
                && (int) $row->recordedBy->cav_id === (int) $trooper->cav_id,
        ]);

        if ($withCaptures) {
            $seen = $this->captures->seenTrooper($trooper->id)
                ->reject(fn (PracticeCapturePlayer $row) => $entered->has($row->section_practice_id))
                ->map(fn (PracticeCapturePlayer $row) => [
                    ...$this->practice($row->practice),
                    'source' => 'capture',
                    'enteredBy' => null,
                    'selfReported' => false,
                ]);
            $rows = $rows->concat($seen);
        }

        return $rows
            ->sortBy([['week', 'desc'], ['section', 'asc']])
            ->values()
            ->all();
    }

    /** @return array{week: string, sectionId: int, section: ?string} */
    private function practice(SectionPractice $practice): array
    {
        return [
            'week' => $practice->week_start->toDateString(),
            'sectionId' => $practice->section_id,
            'section' => $practice->section === null ? null : ($practice->section->callsign ?? $practice->section->name),
        ];
    }
}
