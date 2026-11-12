<?php

namespace App\Commands\ReportEdits;

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;

/**
 * Who a request to edit a locked report goes to, and how the bell names people: the leaders one level above the
 * report's unit (for a staff report, above the staff member's HQ). That is the unit's lead by billet (CO, PL, SL) and
 * its acting lead, with a portal account. When none of them has one, or the only one is the person asking, the next
 * unit up is tried, and so on to the top. Higher leaders and Admins may still decide on the report's page; only this
 * level is told.
 */
class ReportEditRecipients
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitCommandMappingRepository $commands,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly UserRepository $users,
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * @param  list<int>  $exceptUserIds  never told: the person asking
     * @return Collection<int, User>
     */
    public function above(Unit $unit, array $exceptUserIds): Collection
    {
        $current = $unit->parent_id === null ? null : $this->units->find($unit->parent_id);

        while ($current !== null) {
            $leads = $this->commands->commandersOf([$current->id])->values()
                ->merge($this->actingLeads->leadsOf([$current->id])->values());
            $cavIds = $leads->map(fn (Trooper $trooper) => $trooper->cav_id)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $users = $cavIds === [] ? collect() : $this->users->withCavIds($cavIds)
                ->reject(fn (User $user) => in_array($user->id, $exceptUserIds, true))
                ->values();

            if ($users->isNotEmpty()) {
                return $users;
            }

            $current = $current->parent_id === null ? null : $this->units->find($current->parent_id);
        }

        return collect();
    }

    /** The name the bell gives a user: their trooper's display name, else their username. */
    public function nameOf(User $user): string
    {
        $own = $user->cav_id === null ? null : $this->troopers->byCavId([(int) $user->cav_id])->first();

        return $own?->display_name ?? $user->username;
    }
}
