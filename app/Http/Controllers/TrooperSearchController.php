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
 * The shell's trooper search: the troopers whose username contains the typed text, for a jump to their milpac. The
 * text may be written the way 7Cav names a member: a leading rank is ignored and a space stands for the username's dot,
 * so "SGT Vasquez" and "Vasquez R" both find `Vasquez.R`. A number (with or without `#`) also finds the trooper with that
 * exact `cav_id`, which is how a trooper with no forum username is named (`SGT #1234`).
 *
 * It offers exactly the troopers the milpac page would open for this viewer (AD-8): placed in a section of their
 * effective scope, which a view-as preview narrows (AD-9), and the unplaced ones only to an Admin, the same rule as
 * {@see ScopeResolver::canSee()}, plus the viewer themselves ({@see ScopeResolver::isOwnTrooper()}). A trooper the page
 * would answer 404 for is never in the list, so the search cannot be used to find out who exists outside the viewer's
 * scope. Throttled per user by the `trooper-search` limiter.
 */
class TrooperSearchController extends Controller
{
    /** The most troopers one search returns: enough to pick from, small enough to keep typing instead. */
    public const LIMIT = 10;

    /** Fewer characters than this match too much of the roster to be worth a query. */
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 64;

    /** The rank abbreviations a search may start with, as `rank_short` holds them. */
    private const RANKS = [
        'RCT', 'PVT', 'PFC', 'SPC', 'CPL', 'SGT', 'SSG', 'SFC', 'MSG', '1SG', 'SGM', 'CSM',
        'WO1', 'CW2', 'CW3', 'CW4', 'CW5', '2LT', '1LT', 'CPT', 'MAJ', 'LTC', 'COL', 'BG', 'MG', 'LTG', 'GEN', 'GOA',
    ];

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
        $admin = $this->scope->actsAsAdmin($user);
        // The viewer's own trooper too, as the milpac page opens it for them (ScopeResolver::isOwnTrooper(): never in a
        // view-as preview, never while acting as someone). Nobody else outside the scope.
        $own = $this->scope->ownCavId($user);
        $found = $this->troopers->searchByUsername($this->usernameTerms($term), $this->cavIdIn($term), $this->scope->resolve($user), $admin, self::LIMIT + 1, $own);
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
            // Whether the list is limited to the viewer's units, so an empty answer can say so: everyone but an acting
            // Admin, who sees every trooper.
            'scoped' => ! $admin,
        ]);
    }

    /**
     * What a username should contain: the text with a leading rank dropped (only when more follows it), its words
     * joined by the username's dot and, for a username that has spaces of its own, by a space.
     *
     * @return list<string>
     */
    private function usernameTerms(string $term): array
    {
        $words = preg_split('/\s+/u', $term, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) > 1 && in_array(mb_strtoupper(rtrim($words[0], '.')), self::RANKS, true)) {
            array_shift($words);
        }

        return array_values(array_unique([implode('.', $words), implode(' ', $words)]));
    }

    /** The `cav_id` the text names, when it is a number, with or without a leading `#`. */
    private function cavIdIn(string $term): ?int
    {
        return preg_match('/^#?(\d{1,18})$/', $term, $match) === 1 ? (int) $match[1] : null;
    }
}
