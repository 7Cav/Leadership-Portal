<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Commands\Guests\GuestAttendanceForbiddenException;
use App\Commands\Guests\GuestAttendanceRefusedException;
use App\Commands\Guests\RecordGuestAttendance;
use App\Commands\Guests\RecordGuestAttendanceHandler;
use App\Commands\Guests\RemoveGuestAttendance;
use App\Commands\Guests\RemoveGuestAttendanceHandler;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\RollcallGuests;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The rollcall's "Guests" area (decided by the product owner 2026-10-23): a host section's leader adds a trooper from
 * another section who attended this week's practice, or takes back one they added, and finds that trooper by username.
 * Each write is one audited Command whose handler checks the host section's write scope (AD-8, AD-16); a view-as preview
 * is refused before it gets here (`ReadOnlyWhileViewingAs`), and a staff grant is refused by the handler.
 *
 * Every write answers JSON for the screen: 403 outside write scope, 422 with `reason` and the readable message when the
 * guest or the week cannot change, 409 with `locked` once the host section's report for the week was sent (AD-12), the
 * same answers as the rollcall's other writes.
 */
class RollcallGuestController extends Controller
{
    /** The most troopers one guest search returns, as in the shell's trooper search. */
    public const LIMIT = TrooperSearchController::LIMIT;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly RollcallGuests $guests,
        private readonly MarkAttendanceHandler $makeups,
    ) {}

    /**
     * The guest picker: serving troopers placed in another section whose username contains `q`. Deliberately not the
     * shell's trooper search, whose scope rule (the milpac's, `ScopeResolver::canSee()`) stays as it is: a guest comes
     * from outside the leader's scope by definition. So it is narrower in every other way: only someone who may write
     * this section's rollcall may ask (a staff grant reads, it never adds guests), only serving troopers placed elsewhere
     * are offered, and each answer carries just what the picker shows (id, username, rank, own section designation), no
     * milpac link, no standing, no billet. Throttled per user by the same `trooper-search` limiter.
     */
    public function search(Request $request, int $unit): JsonResponse
    {
        if (! $this->scope->writeScope($request->user())->contains($unit)) {
            return response()->json(['message' => 'Only a leader of this section can add its guests.'], 403);
        }

        if ($this->units->find($unit)?->type !== UnitType::Section) {
            return response()->json(['message' => 'Guests are added only at a section practice.'], 404);
        }

        $term = $request->query('q');
        $term = is_string($term) ? trim($term) : '';

        if (mb_strlen($term) < TrooperSearchController::MIN_LENGTH || mb_strlen($term) > TrooperSearchController::MAX_LENGTH) {
            return response()->json(['message' => 'Search with '.TrooperSearchController::MIN_LENGTH.' to '.TrooperSearchController::MAX_LENGTH.' characters.'], 422);
        }

        // One row over the limit says whether there are more than shown, without counting them all.
        $found = $this->troopers->searchGuests($term, $unit, self::LIMIT + 1);
        $units = $this->units->tree()->keyBy('id');

        return response()->json([
            'troopers' => $found->take(self::LIMIT)->map(fn (Trooper $t) => [
                'id' => $t->id,
                'name' => $t->username,
                'rank' => $t->rank_short ?? $t->rank,
                'section' => ($section = $units->get($t->primary_section_id)) instanceof Unit ? ($section->callsign ?? $section->name) : null,
            ])->values(),
            'more' => $found->count() > self::LIMIT,
        ]);
    }

    /**
     * "X from another section attended our practice": records the guest and, through the capture's own makeup path, an
     * automatic makeup on their own section's rollcall. Answers the guest row and what became of that makeup
     * (`marked`, `kept` or `locked`), so the screen can say it. Adding a guest already listed by hand retries their
     * makeup, so a request that failed after the entry was saved can simply be sent again.
     */
    public function store(Request $request, int $unit, RecordGuestAttendanceHandler $handler): JsonResponse
    {
        return $this->attempt($request, 'adding this guest', function (CarbonImmutable $week, int $trooperId) use ($request, $unit, $handler) {
            $recorded = $handler->handle($request->user(), new RecordGuestAttendance($unit, $week, $trooperId));
            // Read again, so a retry that could not write the makeup still offers the next one.
            $missing = $this->makeups->makeupsMissing($recorded->practice, [$trooperId]) !== [];

            return [
                'guest' => $this->guests->one($recorded->sighting, $this->troopers->find($trooperId), $missing),
                'makeup' => $recorded->makeup,
            ];
            // Adding again is safe: a guest already saved is not listed twice, and their makeup is tried again.
        }, 'Add them again to finish. They will not be listed twice.');
    }

    /**
     * Undo a hand-added guest. Answers what became of the makeup it brought: `withdrawn`, `kept` (no longer the
     * automatic makeup, or another sighting that week still supports it) or `locked` (their own week may not change).
     */
    public function destroy(Request $request, int $unit, RemoveGuestAttendanceHandler $handler): JsonResponse
    {
        return $this->attempt($request, 'removing this guest', fn (CarbonImmutable $week, int $trooperId) => [
            'removed' => true,
            'makeup' => $handler->handle($request->user(), new RemoveGuestAttendance($unit, $week, $trooperId))->value,
        ]);
    }

    /**
     * Validate the shared body (`week`, `trooper_id`), refuse a future week, run `$write` and map its refusals.
     *
     * @param  Closure(CarbonImmutable, int): array<string, mixed>  $write
     */
    private function attempt(Request $request, string $doing, Closure $write, ?string $onError = null): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'trooper_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $currentWeek = WeekOf::start(CarbonImmutable::now());
        $week = WeekOf::start(CarbonImmutable::createFromFormat('!Y-m-d', $request->input('week'), 'UTC'));

        if ($week->greaterThan($currentWeek)) {
            return response()->json(['message' => 'A future week has no practice yet.'], 422);
        }

        try {
            return response()->json($write($week, (int) $request->input('trooper_id')));
        } catch (GuestAttendanceForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (GuestAttendanceRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422);
        } catch (RollcallWeekLockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'locked' => true], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "The portal hit an error {$doing}. ".($onError ?? 'Reload to see whether it was saved.')], 500);
        }
    }
}
