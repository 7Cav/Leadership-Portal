<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shell's trooper search: the troopers whose username contains the typed text, for a jump to their milpac.
 *
 * It offers exactly the troopers the milpac page would open for this viewer (AD-8): placed in a section of their
 * effective scope, which a view-as preview narrows (AD-9), and the unplaced ones only to an Admin, the same rule as
 * {@see ScopeResolver::canSee()}. A trooper the page would answer 404 for is never in the list, so the search cannot
 * be used to find out who exists outside the viewer's scope. Throttled per user by the `trooper-search` limiter.
 */
class TrooperSearchController extends Controller
{
    /** The most troopers one search returns: enough to pick from, small enough to keep typing instead. */
    public const LIMIT = 10;

    /** Fewer characters than this match too much of the roster to be worth a query. */
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 64;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly UnitRepository $units,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $term = $request->query('q');
        $term = is_string($term) ? trim($term) : '';

        // Answered as JSON here rather than through validate(), which would redirect: the app renders JSON errors for
        // api/* only, and the only caller of this route is the shell's fetch.
        if (mb_strlen($term) < self::MIN_LENGTH || mb_strlen($term) > self::MAX_LENGTH) {
            return response()->json(['message' => 'Search with '.self::MIN_LENGTH.' to '.self::MAX_LENGTH.' characters.'], 422);
        }

        $user = $request->user();

        // One row over the limit says whether there are more than shown, without counting them all.
        $found = $this->troopers->searchByUsername($term, $this->scope->resolve($user), $this->scope->actsAsAdmin($user), self::LIMIT + 1);
        $units = $this->units->tree()->keyBy('id');

        return response()->json([
            'troopers' => $found->take(self::LIMIT)->map(fn (Trooper $t) => [
                'id' => $t->id,
                'rank' => $t->rank_short ?? $t->rank,
                'rankFull' => $t->rank,
                'name' => $t->username,
                'displayName' => $t->display_name,
                'section' => ($section = $units->get($t->primary_section_id)) instanceof Unit
                    ? ['id' => $section->id, 'designation' => $section->callsign ?? $section->name, 'name' => $section->name]
                    : null,
                'departed' => $t->status?->isServing() !== true,
            ])->values(),
            'more' => $found->count() > self::LIMIT,
        ]);
    }
}
