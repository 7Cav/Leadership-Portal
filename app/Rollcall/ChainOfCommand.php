<?php

namespace App\Rollcall;

use App\Models\CommandRole;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitLead;
use App\Repositories\UnitLeadRepository;
use App\Repositories\UnitRepository;

/**
 * A section's chain of command, for the rollcall template's `{{coc}}` code: the command billets of the section and of
 * each unit above it, up to and including its battalion (the ACD and DEVCOM HQs are battalions too), bottom-up. Within
 * an echelon the senior NCO comes first, then the second in command, then the lead: ASL, SL; PSG, PL; 1SG, XO, CO;
 * SGM, XO, CO. An echelon the section has no unit at is left out; a billet nobody serving holds has no holders, and the
 * post prints it as Vacant.
 *
 * Who holds a billet is the org structure's own answer, so the post and the org page agree: the lead is the unit's
 * lead ({@see UnitLeadRepository}, an acting lead marked as one), the rest of the team is whoever's billet maps to the
 * unit ({@see UnitCommandMappingRepository::teamsOf()}), named by {@see CommandRole::fromBillet()}. DEVCOM has a 1IC
 * and a 2IC and no sergeant major billet, so it has no sergeant major line.
 */
final class ChainOfCommand
{
    /** Each echelon's lines, bottom-up, as the post labels them. */
    private const LINES = [
        'section' => [[CommandRole::Asl, 'Assistant Section Leader'], [CommandRole::Sl, 'Section Leader']],
        'platoon' => [[CommandRole::Psg, 'Platoon Sergeant'], [CommandRole::Pl, 'Platoon Leader']],
        'company' => [[CommandRole::FirstSergeant, 'Company First Sergeant'], [CommandRole::Xo, 'Company Executive Officer'], [CommandRole::Co, 'Company Commanding Officer']],
        'battalion' => [[CommandRole::SergeantMajor, 'Battalion Sergeant Major'], [CommandRole::Xo, 'Battalion Executive Officer'], [CommandRole::Co, 'Battalion Commanding Officer']],
    ];

    /** The battalion whose command team is a 1IC and a 2IC only (#377). */
    private const DEVCOM = 'DEVCOM';

    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitCommandMappingRepository $commands,
        private readonly UnitLeadRepository $leads,
    ) {}

    /**
     * The lines of `$section`'s chain, bottom-up: each a role, the serving troopers holding it (none when vacant) and
     * whether they hold it as an Admin-assigned acting lead.
     *
     * @return list<array{role: string, holders: list<array{rank: string, name: string, url: string}>, acting: bool}>
     */
    public function lines(Unit $section): array
    {
        $chain = [];

        foreach ($this->units->chainOf($section) as $unit) {
            if (isset(self::LINES[$unit->type->value]) && $unit->isLine()) {
                $chain[] = $unit;
            }

            if ($unit->type === UnitType::Battalion) {
                break;
            }
        }

        if ($chain === []) {
            return [];
        }

        $ids = array_map(fn (Unit $unit) => $unit->id, $chain);
        $teams = $this->commands->teamsOf($ids);
        $leads = $this->leads->leadsOf($ids);
        $lines = [];

        foreach ($chain as $unit) {
            $leadRole = CommandRole::leadOf($unit->type);
            $lead = $leads->get($unit->id);
            $team = $teams->get($unit->id, collect());

            foreach (self::LINES[$unit->type->value] as [$role, $label]) {
                if ($role === CommandRole::SergeantMajor && $unit->callsign === self::DEVCOM) {
                    continue;
                }

                if ($role === $leadRole) {
                    $lines[] = [
                        'role' => $label,
                        'holders' => $lead === null ? [] : [self::holder($lead->trooper)],
                        'acting' => $lead?->source === UnitLead::ACTING,
                    ];

                    continue;
                }

                $lines[] = [
                    'role' => $label,
                    'holders' => $team
                        ->filter(fn (Trooper $trooper) => CommandRole::fromBillet($trooper->billet, $unit->type) === $role)
                        ->map(fn (Trooper $trooper) => self::holder($trooper))
                        ->values()
                        ->all(),
                    'acting' => false,
                ];
            }
        }

        return $lines;
    }

    /**
     * A holder as the post names them: the full rank and the milpac name, with their milpac page to link to.
     *
     * @return array{rank: string, name: string, url: string}
     */
    private static function holder(Trooper $trooper): array
    {
        return [
            'rank' => (string) $trooper->rank,
            'name' => filled($trooper->username) ? (string) $trooper->username : "#{$trooper->cav_id}",
            'url' => $trooper->milpacUrl(),
        ];
    }
}
