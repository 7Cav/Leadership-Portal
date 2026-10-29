<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\GameIdentities\GameIdentityForbiddenException;
use App\Commands\GameIdentities\GameRetiredException;
use App\Commands\GameIdentities\InvalidGameIdentityException;
use App\Commands\GameIdentities\RemoveTrooperGameIdentity;
use App\Commands\GameIdentities\RemoveTrooperGameIdentityHandler;
use App\Commands\GameIdentities\SetTrooperGameIdentity;
use App\Commands\GameIdentities\SetTrooperGameIdentityHandler;
use App\Commands\GameIdentities\UnknownIdKindException;
use App\Models\Game;
use App\Models\IdKindFormat;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\GameRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link-identity screen (Story 7.2, binding design: mockups/v2/link-identity.html variant C,
 * "Needs-a-link queue"): a leader links the troopers in their scope to their in-game identities,
 * one per game (AD-19), and edits or removes those links.
 *
 * Reads go straight to the repositories; every write is an audited Command, scope-gated by the
 * ScopeResolver inside its handler (AD-8) — a trooper outside the viewer's scope is a 403 whatever
 * the page offered. A platform is rendered for every game an Admin keeps ({@see GameRepository}),
 * so a new game needs no change here. A game may ask for several kinds of player ID (#428, Steam, Epic, Xbox): the
 * page picks one for the form, and an identity says which it was entered as. A retired game is sent flagged: the page keeps showing (and
 * removing) the links it already has, and offers it for no new one (#388).
 *
 * Lists every serving trooper placed anywhere in the viewer's scope: a section, an HQ node (PL, PSG,
 * CO, XO, 1SG and up) or a group (ELOA, Reservists, New Recruits, a staff department), since the
 * placement change of 2026-10-24 puts HQ staff and groups off the sections. Linking someone who is
 * not in a section only lets the capture recognise them; it still marks attendance in sections only.
 *
 * Each game is sent with the hint for every kind that has a known shape (#450, {@see IdKindFormat}), and each identity
 * with whether its name mixes alphabets, for the "check this name" marker. The page shows these; the server decides.
 */
class GameIdentityController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GameRepository $games,
    ) {}

    public function index(Request $request): Response
    {
        $scope = $this->scope->resolve($request->user());
        $writable = $this->scope->writeScope($request->user())->flip();
        $inScope = $scope->flip();
        $units = $this->units->tree()->filter(fn (Unit $unit) => $inScope->has($unit->id))->keyBy('id');
        $troopers = $this->troopers->placedInAny($units->keys());
        $identities = $this->identities->forTroopers($troopers->modelKeys())->groupBy('trooper_id');
        $games = $this->games->all();
        $gameOf = $this->usualGames($this->units->tree()->keyBy('id'), $troopers, $identities, $games);

        return Inertia::render('GameIdentities', [
            'platforms' => $games->map(fn (Game $game) => [
                'value' => $game->slug,
                'label' => $game->name,
                'idKinds' => $game->id_kinds,
                // The shape each checked kind has, keyed by kind, for the hint under the ID field. A kind not here is not checked.
                'idHints' => (object) collect($game->id_kinds)
                    ->mapWithKeys(fn (string $kind) => [$kind => IdKindFormat::for($kind)?->hint])->filter()->all(),
                'retired' => $game->isRetired(),
            ])->values()->all(),
            'troopers' => $troopers->map(fn (Trooper $trooper) => [
                'id' => $trooper->id,
                'displayName' => $trooper->display_name,
                'billet' => $trooper->billet,
                'section' => $this->designation($units->get($trooper->primary_section_id)),
                // A staff grant shows the trooper but does not let the viewer change their links.
                'editable' => $writable->has($trooper->primary_section_id),
                // The game Add identity opens on while it is still missing: their unit's game label, else the game most
                // troopers placed with them have linked. Null when neither gives one.
                'game' => $gameOf->get((int) $trooper->primary_section_id),
                'identities' => ($identities->get($trooper->id) ?? collect())
                    ->mapWithKeys(fn (TrooperGameIdentity $identity) => [$identity->game->slug => [
                        'playerName' => $identity->player_name,
                        'platformId' => $identity->platform_id,
                        'idKind' => $identity->id_kind,
                        'mixedScripts' => $identity->nameMixesScripts(),
                    ]])->all(),
            ])->sortBy(fn (array $row) => mb_strtolower($row['displayName']))->values()->all(),
        ]);
    }

    /**
     * Link the trooper on the game `$platform` names (its slug), or correct the identity they
     * already hold there. An identifier another trooper already owns, or a retired game, comes
     * back as a validation error on `platform_id`, and a kind the game does not have on `id_kind`; no kind
     * keeps the identity's kind, or takes the game's first for a new one. A name or ID with nothing left once its hidden
     * characters are removed, or an ID without its kind's shape (#450), comes back on its field. An unknown game is a 404.
     */
    public function update(Request $request, int $trooper, string $platform, SetTrooperGameIdentityHandler $handler): RedirectResponse
    {
        $game = $this->games->findBySlug($platform) ?? abort(404);

        $data = $request->validate([
            'player_name' => ['required', 'string', 'max:255'],
            'platform_id' => ['required', 'string', 'max:255'],
            'id_kind' => ['nullable', 'string', 'max:50'],
        ], [
            'player_name.required' => 'Enter the player name as it shows in the game.',
            'player_name.max' => 'Keep the player name to :max characters.',
            'platform_id.required' => 'Enter the player ID.',
            'platform_id.max' => 'Keep the player ID to :max characters.',
        ]);

        try {
            $handler->handle($request->user(), new SetTrooperGameIdentity($trooper, $game, $data['player_name'], $data['platform_id'], idKind: $data['id_kind'] ?? null));
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (GameRetiredException $e) {
            throw ValidationException::withMessages(['platform_id' => $e->getMessage()]);
        } catch (UnknownIdKindException $e) {
            throw ValidationException::withMessages(['id_kind' => $e->getMessage()]);
        } catch (InvalidGameIdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        } catch (GameIdentityLinkException $e) {
            throw ValidationException::withMessages(['platform_id' => $this->collisionMessage($game, $e->platformId)]);
        }

        return back();
    }

    public function destroy(Request $request, int $trooper, string $platform, RemoveTrooperGameIdentityHandler $handler): RedirectResponse
    {
        $game = $this->games->findBySlug($platform) ?? abort(404);

        try {
            $handler->handle($request->user(), new RemoveTrooperGameIdentity($trooper, $game));
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        }

        return back();
    }

    /**
     * "Already linked to SGT Ortega.M on Hell Let Loose." — the owner is named so the leader knows
     * whose row to fix. Roster names are not secret within the portal, so an owner outside the
     * leader's scope is still named: the alternative is an error nobody can act on.
     */
    private function collisionMessage(Game $game, string $platformId): string
    {
        $owner = $this->identities->findByPlatformId($game, $platformId);
        $name = $owner === null ? null : $this->troopers->find($owner->trooper_id)?->display_name;

        return 'Already linked to '.($name ?? 'another trooper')." on {$game->name}.";
    }

    /**
     * Each unit's usual game, by unit id, as a game slug: the game or team label an Admin set on the unit or the nearest
     * unit above it, when it names a game that is not retired (by name or slug, any case), else the game most of the
     * troopers placed in the unit have an identity for, the game added first on a tie (as the capture picks a
     * section's game). Only units someone in `$troopers` is placed in are worked out.
     *
     * @param  Collection<int, Unit>  $tree  every unit, by id
     * @param  Collection<int, Trooper>  $troopers
     * @param  Collection<int, Collection<int, TrooperGameIdentity>>  $identities  by trooper id
     * @param  Collection<int, Game>  $games
     * @return Collection<int, string|null>
     */
    private function usualGames(Collection $tree, Collection $troopers, Collection $identities, Collection $games): Collection
    {
        $active = $games->reject(fn (Game $game) => $game->isRetired());
        $byName = $active->flatMap(fn (Game $game) => [mb_strtolower($game->name) => $game, mb_strtolower($game->slug) => $game]);

        return $troopers->groupBy('primary_section_id')->filter(fn ($placed, $unitId) => $unitId !== '')
            ->map(function (Collection $placed, $unitId) use ($tree, $identities, $active, $byName) {
                for ($unit = $tree->get((int) $unitId); $unit !== null; $unit = $tree->get((int) $unit->parent_id)) {
                    $label = mb_strtolower(trim((string) $unit->label));
                    if ($label !== '' && $byName->has($label)) {
                        return $byName->get($label)->slug;
                    }
                }

                $counts = $placed->flatMap(fn (Trooper $trooper) => $identities->get($trooper->id) ?? collect())->countBy('game_id');
                $best = $active->reduce(fn (?Game $best, Game $game) => ($counts[$game->id] ?? 0) > ($counts[$best?->id] ?? 0) ? $game : $best);

                return $best?->slug;
            });
    }

    private function designation(?Unit $unit): ?string
    {
        return $unit === null ? null : ($unit->callsign ?? $unit->name);
    }
}
