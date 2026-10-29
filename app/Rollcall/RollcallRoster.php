<?php

namespace App\Rollcall;

use App\Models\Attendance;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SectionWeekRoster;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The section, week and troopers a rollcall is drawn from (3.7), shaped as the props the rollcall page reads and its
 * forum post is filled from (`lib/rollcallBbcode.js`). One builder, so the rollcall page and the template editor's preview
 * of a real section (Settings, Templates) send the same rows and the post they show cannot drift apart.
 *
 * Read-only. Who may open a section is the Controller's ScopeResolver gate (AD-8), checked before this is called.
 */
final class RollcallRoster
{
    public function __construct(
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly SectionFireteams $fireteams,
    ) {}

    /**
     * The sections a picker offers, with the platoon each sits under.
     *
     * @param  Collection<int, Unit>  $sections  sections with `parent` loaded ({@see UnitRepository::sectionsAmong()})
     * @return list<array{id: int, designation: string, name: string, platoon: ?string, label: ?string}>
     */
    public function options(Collection $sections): array
    {
        return $sections->map(fn (Unit $section) => $this->section($section, $sections))->values()->all();
    }

    /**
     * @param  Collection<int, Unit>  $sections  the sections in the viewer's scope, with `parent` loaded
     * @return array{id: int, designation: string, name: string, platoon: ?string, label: ?string}
     */
    public function section(Unit $section, Collection $sections): array
    {
        $parent = $sections->firstWhere('id', $section->id)?->parent;

        return [
            'id' => $section->id,
            'designation' => $section->callsign ?? $section->name,
            'name' => $section->name,
            'platoon' => $parent ? ($parent->callsign ?? $parent->name) : null,
            // The game or team label an Admin set (2026-10-24): the section's own, else its platoon's.
            'label' => $section->label ?? $parent?->label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function week(CarbonImmutable $weekStart, CarbonImmutable $currentWeek): array
    {
        return [
            // Saturday 00:00 UTC (AD-11); the rollcall thread's signatures close the Friday after.
            'start' => $weekStart->toIso8601String(),
            'date' => $weekStart->toDateString(),
            'closes' => $weekStart->addWeek()->subMinute()->toIso8601String(),
            'isCurrent' => $weekStart->equalTo($currentWeek),
            'previous' => $weekStart->subWeek()->toDateString(),
            'next' => $weekStart->lessThan($currentWeek) ? $weekStart->addWeek()->toDateString() : null,
            'current' => $currentWeek->toDateString(),
        ];
    }

    /**
     * The section's troopers with this week's marks and last week's practice marks (the pre-fill suggestion, 3.7).
     * For a week that has ended they are the troopers the placement history puts in the section when it ended
     * ({@see TrooperRepository::onRollcall()}), so a move or a departure after the week drops nobody from it.
     */
    public function forWeek(Unit $section, CarbonImmutable $weekStart): RollcallRosterWeek
    {
        $practice = $this->practices->find($section->id, $weekStart);
        $marks = $practice ? $this->attendance->forPractice($practice)->keyBy('trooper_id') : collect();

        $priorPractice = $this->practices->find($section->id, $weekStart->subWeek());
        $prior = $priorPractice
            ? $this->attendance->forPractice($priorPractice)->filter(fn (Attendance $row) => $row->status !== null)->keyBy('trooper_id')
            : collect();

        // Who is placed in the section now, or for a week that has ended, who was in it when that week ended.
        $roster = $this->troopers->onRollcall($section, $weekStart);
        $placed = $roster->troopers;
        $roles = $this->fireteams->roles($section, $placed);

        $troopers = $placed->map(fn (Trooper $trooper) => [
            'id' => $trooper->id,
            'cavId' => $trooper->cav_id,
            // The synced forum identity (2.10): `rankShort` + `name` render as `displayName`
            // ("SGT Vasquez.R", "SGT #1234" until the username syncs).
            'rank' => $trooper->rank,
            'rankShort' => $trooper->rank_short,
            // The synced rank insignia (an https URL on 7cav.us, or null), printed by the template's `{{rank_icon}}`.
            'rankIcon' => $trooper->rank_image_url,
            'name' => $trooper->username,
            'displayName' => $trooper->display_name,
            'rollcall' => $marks->get($trooper->id)?->rollcall->value ?? 'unsigned',
            'status' => $marks->get($trooper->id)?->status?->value,
            'source' => $marks->get($trooper->id)?->source?->value,
            'priorStatus' => $prior->get($trooper->id)?->status->value,
            // The Cav join date, so a week before it reads "not in the unit yet" as on the roster matrix.
            'joined' => $trooper->tis?->toDateString(),
            // `sl`, `asl`, `ftl` or null, whether they lead a fireteam, and the leader of the fireteam they are in:
            // the screen and the post list the section leader-first from these (resources/js/lib/fireteams.js).
            'role' => $roles[$trooper->id]['role'],
            'leadsFireteam' => $roles[$trooper->id]['leadsFireteam'],
            'fireteamLeaderId' => $roles[$trooper->id]['fireteamLeaderId'],
            // On a week that has ended, how the row differs from the section now: `departed` (left the Cav since),
            // `moved_out` (placed elsewhere now), `joined` (moved in partway through that week), else null.
            'placement' => $this->placementNote($section, $trooper, $roster),
        ])->sortBy(fn (array $row) => mb_strtolower($row['name'] ?? "~{$row['cavId']}"))->values()->all();

        return new RollcallRosterWeek($practice, $marks, $troopers, $prior->isNotEmpty());
    }

    private function placementNote(Unit $section, Trooper $trooper, SectionWeekRoster $roster): ?string
    {
        return match (true) {
            ! $roster->asPlaced => null,
            ! $trooper->status->isServing() => 'departed',
            (int) $trooper->primary_section_id !== $section->id => 'moved_out',
            in_array($trooper->id, $roster->joined, true) => 'joined',
            default => null,
        };
    }
}
