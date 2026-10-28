<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Jobs\SyncRosterJob;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The echelon leader's manual roster sync: refreshes only the troopers in one unit's subtree, through the same
 * queued job the scheduler and the Admin trigger use. Who may ask is {@see ScopeResolver::commandsUnit()}.
 *
 * Every unit sync fetches the whole roster from Cav (three requests on one member's API key) however small the
 * unit. A unit, its ancestors and its descendants overlap, so they count as one: while a sync of any of them is
 * pending or was accepted within the cooldown, a leader's sync of the others is refused. A leader clicking down a
 * company, its platoons and its sections gets one fetch per cooldown; unrelated units do not block each other.
 * Admins are exempt from the cooldown, and only a request that passed the leader check ever starts one.
 */
class UnitRosterSyncController extends Controller
{
    /** Seconds after an accepted unit sync before a leader may sync that unit, or one above or below it, again. */
    public const COOLDOWN_SECONDS = 300;

    /** Seconds a request waits for another one's check to finish before it is told to try again. */
    private const DECIDE_WAIT_SECONDS = 3;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
    ) {}

    public function store(Request $request, int $unit): RedirectResponse
    {
        $user = $request->user();
        $found = $this->units->find($unit);

        // One answer for "no such unit" and "not yours": a leader cannot probe for units outside their scope.
        if ($found === null || ! $this->scope->commandsUnit($user, $found)) {
            abort(403, 'Only that unit\'s leader or an Admin may sync it.');
        }

        // The checks and the cooldown they start are one step, taken one request at a time: otherwise two leaders
        // clicking overlapping units at once both read no cooldown before either writes one, and both fetch.
        try {
            Cache::lock('unit-roster-sync:deciding', 10)->block(self::DECIDE_WAIT_SECONDS, fn () => $this->startCooldown($user, $found));
        } catch (LockTimeoutException) {
            abort(429, 'Another roster sync is being started right now. Try again in a moment.', ['Retry-After' => 5]);
        }

        // The run is recorded later, by a worker with no request behind it: name who really asked, the Admin or leader
        // acting as this user, not the person acted as (#379, #432). Their own id when they act as nobody.
        $actor = $request->attributes->get(ActAs::ADMIN_ATTRIBUTE) ?? $user;
        dispatch(new SyncRosterJob($actor->id, $found->id));

        return back();
    }

    /**
     * Refuse the sync while one that overlaps it is pending or within the cooldown, otherwise start this unit's
     * cooldown. Runs under {@see store()}'s lock, so the read and the write cannot interleave with another request's.
     */
    private function startCooldown(User $user, Unit $unit): void
    {
        $related = $this->relatedUnitIds($unit);

        // A pending whole-roster sync already covers this unit, and a pending one for this unit is the same job, so
        // dispatching would be dropped silently; a pending one above or below it overlaps it. Say so rather than let
        // the page report a sync that never queued.
        $pending = (new SyncRosterJob)->isAlreadyQueued()
            || $related->contains(fn (int $id) => (new SyncRosterJob($user->id, $id))->isAlreadyQueued());

        if ($pending) {
            abort(409, 'A sync of this unit, or of a unit above or below it, is already queued or running.');
        }

        if (! $user->is_admin) {
            $until = collect(Cache::many($related->map(fn (int $id) => self::cooldownKey($id))->all()))->filter()->max();

            if ($until !== null) {
                abort(429, 'This unit, or a unit above or below it, was synced a moment ago. Try again in a few minutes.', [
                    'Retry-After' => max(1, (int) $until - now()->getTimestamp()),
                ]);
            }
        }

        Cache::put(self::cooldownKey($unit->id), now()->addSeconds(self::COOLDOWN_SECONDS)->getTimestamp(), self::COOLDOWN_SECONDS);
    }

    /**
     * The unit, every ancestor (from its materialized path, "1/5/9/") and every descendant.
     *
     * @return Collection<int, int>
     */
    private function relatedUnitIds(Unit $unit): Collection
    {
        return collect(explode('/', $unit->path))
            ->filter(fn (string $id) => $id !== '')
            ->map(fn (string $id) => (int) $id)
            ->merge($this->units->subtreeIds($unit)->map(fn ($id) => (int) $id))
            ->unique()
            ->values();
    }

    private static function cooldownKey(int $unitId): string
    {
        return "unit-roster-sync:synced:{$unitId}";
    }
}
