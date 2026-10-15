<?php

namespace App\Rollcall;

use App\Models\FireteamRole;
use App\Models\FireteamSetting;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\FireteamRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * What the rollcall needs to list a section leader-first (the owner's order, 2026-10-16): each trooper's role and
 * fireteam, and how the post shows fireteams. The ordering itself is done once, in the browser
 * (`resources/js/lib/fireteams.js`), for the post and the screen alike.
 *
 * A trooper's role is `sl` or `asl` from the roster ({@see Trooper::roleUnder()}, the same rule as the roster and the
 * readiness matrix), else `ftl` when the section's leaders marked them a fireteam leader, else null. The SL and ASL
 * outrank a fireteam role: a stored FTL row of a trooper who has since become the ASL is not read as an FTL, and their
 * members count as in no fireteam. A member's `fireteamLeaderId` is set only while that leader is an FTL placed in the
 * section, so a fireteam whose leader left reads as no fireteam.
 *
 * Read-only.
 */
final class SectionFireteams
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitCommandMappingRepository $commands,
        private readonly FireteamRepository $fireteams,
    ) {}

    /**
     * Each placed trooper's role and fireteam leader, keyed by trooper id.
     *
     * @param  Collection<int, Trooper>  $placed  the troopers placed in the section now
     * @return array<int, array{role: ?string, fireteamLeaderId: ?int}>
     */
    public function roles(Unit $section, Collection $placed): array
    {
        $leader = $this->commands->commandersOf([$section->id])->get($section->id);
        $assignments = $this->fireteams->assignmentsIn($section->id);

        $roles = $placed->mapWithKeys(function (Trooper $trooper) use ($leader, $assignments) {
            $command = $trooper->roleUnder($leader);
            $row = $assignments->get($trooper->id);

            return [$trooper->id => $command !== null ? strtolower($command) : ($row?->role === FireteamRole::Leader ? 'ftl' : null)];
        });

        return $placed->mapWithKeys(function (Trooper $trooper) use ($roles, $assignments) {
            $row = $assignments->get($trooper->id);
            $leaderId = $roles->get($trooper->id) === null && $row?->role === FireteamRole::Member && $roles->get($row->leader_trooper_id) === 'ftl'
                ? $row->leader_trooper_id
                : null;

            return [$trooper->id => ['role' => $roles->get($trooper->id), 'fireteamLeaderId' => $leaderId]];
        })->all();
    }

    /**
     * How the section's post shows fireteams: the effective `show` and `order` (the company's policy, else the
     * section's choice, else the default, {@see FireteamSetting::effective()}), the section's own choices, and the
     * company's policy (null without an owning company). `can` says what the viewer may change.
     *
     * @return array<string, mixed>
     */
    public function options(Unit $section, bool $canEdit, bool $canEditPolicy): array
    {
        $company = $this->units->owningCompany($section);
        $own = $this->fireteams->settingFor($section->id);
        $policy = $company === null ? null : $this->fireteams->settingFor($company->id);
        $effective = FireteamSetting::effective($policy, $own);

        return [
            'show' => $effective['show'],
            'order' => $effective['order']->value,
            'section' => ['show' => $own?->show_fireteams, 'order' => $own?->fireteam_order?->value],
            'company' => $company === null ? null : [
                'id' => $company->id,
                'designation' => $company->callsign ?? $company->name,
                'show' => $policy?->show_fireteams,
                'order' => $policy?->fireteam_order?->value,
            ],
            'can' => ['edit' => $canEdit, 'editPolicy' => $canEditPolicy && $company !== null],
        ];
    }

    /**
     * The company whose policy covers the section, or null.
     */
    public function companyOf(Unit $section): ?Unit
    {
        return $this->units->owningCompany($section);
    }
}
