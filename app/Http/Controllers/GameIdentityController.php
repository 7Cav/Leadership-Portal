<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\GameIdentities\FillIdentityFromServer;
use App\Commands\GameIdentities\GameIdentityForbiddenException;
use App\Commands\GameIdentities\GameRetiredException;
use App\Commands\GameIdentities\IdentityImportException;
use App\Commands\GameIdentities\IdentityImportParser;
use App\Commands\GameIdentities\ImportGameIdentities;
use App\Commands\GameIdentities\InvalidGameIdentityException;
use App\Commands\GameIdentities\LinkSharedAccount;
use App\Commands\GameIdentities\LinkSharedAccountHandler;
use App\Commands\GameIdentities\LookUpPlayerPlatform;
use App\Commands\GameIdentities\PlatformLookup;
use App\Commands\GameIdentities\PlatformLookupResult;
use App\Commands\GameIdentities\RemoveKeptGameIdentity;
use App\Commands\GameIdentities\RemoveSharedAccount;
use App\Commands\GameIdentities\RemoveSharedAccountHandler;
use App\Commands\GameIdentities\RemoveTrooperGameIdentity;
use App\Commands\GameIdentities\RemoveTrooperGameIdentityHandler;
use App\Commands\GameIdentities\SetTrooperGameIdentity;
use App\Commands\GameIdentities\SetTrooperGameIdentityHandler;
use App\Commands\GameIdentities\SharedAccountException;
use App\Commands\GameIdentities\UnknownIdKindException;
use App\Models\Game;
use App\Models\Gamertag;
use App\Models\IdKindFormat;
use App\Models\KeptGameIdentity;
use App\Models\SharedAccount;
use App\Models\TelemetryInterface;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\GameRepository;
use App\Repositories\KeptGameIdentityRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;
use Illuminate\Http\JsonResponse;
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
        private readonly KeptGameIdentityRepository $kept,
    ) {}

    public function index(Request $request): Response
    {
        $scope = $this->scope->resolve($request->user());
        $writable = $this->scope->writeScope($request->user())->flip();
        $inScope = $scope->flip();
        $tree = $this->units->tree();
        $units = $tree->filter(fn (Unit $unit) => $inScope->has($unit->id))->keyBy('id');
        $troopers = $this->troopers->placedInAny($units->keys());
        $identities = $this->identities->forTroopers($troopers->modelKeys())->groupBy('trooper_id');
        $games = $this->games->all();
        $unitGames = UnitGame::resolve($tree);
        $gameOf = $this->usualGames($unitGames, $troopers, $identities, $games);
        $gamesById = $games->keyBy('id');

        return Inertia::render('GameIdentities', [
            // Import identities: for anyone who may change at least one trooper here; each line is checked on its own.
            'canImport' => $writable->isNotEmpty(),
            // Kept identities: IDs kept on file for Cav names with no trooper in the portal, for Admins only (null otherwise).
            'kept' => $this->scope->isAdmin($request->user()) ? $this->kept->all()->map(fn (KeptGameIdentity $row) => [
                'id' => $row->id,
                'cavName' => $row->cav_name,
                'game' => $row->game->name,
                'idKind' => $row->id_kind,
                'platformId' => $row->platform_id,
                'gamertag' => $row->gamertag,
                'addedBy' => $row->creator?->username,
                'addedAt' => $row->created_at?->toIso8601String(),
                'conflict' => $row->conflict,
            ])->values()->all() : null,
            'platforms' => $games->map(fn (Game $game) => [
                'value' => $game->slug,
                'label' => $game->name,
                // The short name the trooper rows show on their game chips.
                'abbreviation' => $game->abbreviation,
                'idKinds' => $game->id_kinds,
                // The shape each checked kind has, keyed by kind, for the hint under the ID field. A kind not here is not checked.
                'idHints' => (object) collect($game->id_kinds)
                    ->mapWithKeys(fn (string $kind) => [$kind => IdKindFormat::for($kind)?->hint])->filter()->all(),
                // The kinds that are a shared Steam or Epic account (#174), keyed by kind: the form offers to link those
                // to every game asking for the same account.
                'accounts' => (object) collect($game->id_kinds)
                    ->mapWithKeys(fn (string $kind) => [$kind => SharedAccount::of($kind)?->value])->filter()->all(),
                'retired' => $game->isRetired(),
                // Whether "Fill in from game server" can ask this game's servers: it is read through CRCON.
                'lookup' => $game->interface === TelemetryInterface::Crcon,
            ])->values()->all(),
            'troopers' => $troopers->map(fn (Trooper $trooper) => [
                'id' => $trooper->id,
                'displayName' => $trooper->display_name,
                'billet' => $trooper->billet,
                'section' => $this->designation($units->get($trooper->primary_section_id)),
                // A staff grant shows the trooper but does not let the viewer change their links.
                'editable' => $writable->has($trooper->primary_section_id),
                // The game Add identity opens on while it is still missing: the game their unit plays, else the game most
                // troopers placed with them have linked. Null when neither gives one.
                'game' => $gameOf->get((int) $trooper->primary_section_id),
                // The game their line unit plays, as the roll call's missing-identity warning reads it: the row's first
                // tab says whether they are linked on it. Null when their unit plays no game (or a retired one).
                'mainGame' => $this->mainGame($units->get($trooper->primary_section_id), $unitGames, $gamesById),
                'identities' => ($identities->get($trooper->id) ?? collect())
                    ->mapWithKeys(fn (TrooperGameIdentity $identity) => [$identity->game->slug => [
                        'platformId' => $identity->platform_id,
                        'idKind' => $identity->id_kind,
                        // How the row was made (#174): typed, from_account, from_rollcall or imported.
                        'source' => $identity->source->value,
                        // The player's in-game name, shown only, and whether a game server gave it.
                        'gamertag' => $identity->gamertag,
                        'gamertagFromServer' => $identity->gamertag_from_server,
                    ]])->all(),
            ])->sortBy(fn (array $row) => mb_strtolower($row['displayName']))->values()->all(),
        ]);
    }

    /**
     * Link the trooper on the game `$platform` names (its slug), or correct the identity they
     * already hold there. An identifier another trooper already owns, or a retired game, comes
     * back as a validation error on `platform_id`, and a kind the game does not have on `id_kind`; no kind
     * keeps the identity's kind, or takes the game's first for a new one. An ID with nothing left once its hidden
     * characters are removed, an ID without its kind's shape (#450), or a Steam ID Steam has no account for (#174), comes
     * back on `platform_id`. An unknown game is a 404.
     */
    public function update(Request $request, int $trooper, string $platform, SetTrooperGameIdentityHandler $handler, PlatformLookup $lookup): RedirectResponse
    {
        $game = $this->games->findBySlug($platform) ?? abort(404);

        $data = $request->validate([
            'platform_id' => ['required', 'string', 'max:255'],
            'id_kind' => ['nullable', 'string', 'max:50'],
            'gamertag' => ['sometimes', 'nullable', 'string', 'max:'.Gamertag::MAX],
            'gamertag_from_server' => ['sometimes', 'boolean'],
        ], [
            'platform_id.required' => 'Enter the player ID.',
            'platform_id.max' => 'Keep the player ID to :max characters.',
            'gamertag.max' => 'Keep the gamertag to :max characters.',
        ]);

        $gamertag = null;

        if ($request->has('gamertag')) {
            $typed = new Gamertag($data['gamertag'] ?? null);
            // "From game server" only when a lookup of this ID just gave exactly this name; anything else was typed.
            $cached = $typed->value === null || ! ($data['gamertag_from_server'] ?? false) ? null : $lookup->cached($game, $data['platform_id']);
            $gamertag = new Gamertag($typed->value, fromServer: $cached?->isFound() === true && $cached->name !== null && (new Gamertag($cached->name))->value === $typed->value);
        }

        try {
            $handler->handle($request->user(), new SetTrooperGameIdentity($trooper, $game, $data['platform_id'], idKind: $data['id_kind'] ?? null, gamertag: $gamertag));
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

    /**
     * The add form's "Fill in from game server": which kind the typed ID is and the player's current name, from the
     * game's CRCON servers. Writes nothing. A trooper the viewer may not change is a 403; a game not read through
     * CRCON, or an ID that is not 32 characters, comes back as not checked without asking anyone.
     */
    public function lookup(Request $request, int $trooper, string $platform, LookUpPlayerPlatform $handler): JsonResponse
    {
        $game = $this->games->findBySlug($platform) ?? abort(404);
        $data = $request->validate(['platform_id' => ['required', 'string', 'max:255']], ['platform_id.required' => 'Enter the player ID.']);
        $id = trim($data['platform_id']);

        try {
            $result = preg_match(PlatformLookup::LOOKUP_SHAPE, $id) === 1
                ? $handler->handle($request->user(), $trooper, $game, strtolower($id))
                : PlatformLookupResult::notChecked();
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json($result->toArray());
    }

    /**
     * "Fill in from game server" on a saved identity: its kind and gamertag from the game's CRCON servers. Says what
     * happened in the page's notice; a change is audited, nothing new is not.
     */
    public function fill(Request $request, int $trooper, string $platform, FillIdentityFromServer $handler): RedirectResponse
    {
        $game = $this->games->findBySlug($platform) ?? abort(404);

        try {
            [$result, $changed] = $handler->handle($request->user(), $trooper, $game);
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (GameRetiredException|InvalidGameIdentityException|UnknownIdKindException $e) {
            throw ValidationException::withMessages(['platform_id' => $e->getMessage()]);
        }

        $notice = match (true) {
            $result->status === PlatformLookupResult::NOT_SEEN => 'Not seen on our servers. Check the ID, or choose the kind yourself.',
            ! $result->isFound() => 'The game server could not be asked. Try again later.',
            $result->kind === null && ! $changed => 'Seen on our servers, but on a platform '.$game->name.' has no ID kind for.',
            $changed => 'Filled in from the game server.',
            default => 'Already up to date with the game server.',
        };

        return back()->with('notice', $notice);
    }

    /**
     * Link the trooper's Steam or Epic account, entered on the game `$platform` names as `id_kind`, to every active game
     * that asks for that account (#174). A refusal (the ID is another trooper's on a game, or the trooper has a different
     * one there) comes back on `platform_id` and names each trooper and game; nothing is written.
     */
    public function linkAccount(Request $request, int $trooper, string $platform, LinkSharedAccountHandler $handler): RedirectResponse
    {
        $game = $this->games->findBySlug($platform) ?? abort(404);

        $data = $request->validate([
            'platform_id' => ['required', 'string', 'max:255'],
            'id_kind' => ['required', 'string', 'max:50'],
        ], [
            'platform_id.required' => 'Enter the player ID.',
            'platform_id.max' => 'Keep the player ID to :max characters.',
        ]);

        try {
            $handler->handle($request->user(), new LinkSharedAccount($trooper, $game, $data['id_kind'], $data['platform_id']));
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (UnknownIdKindException $e) {
            throw ValidationException::withMessages(['id_kind' => $e->getMessage()]);
        } catch (InvalidGameIdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        } catch (GameRetiredException|SharedAccountException $e) {
            throw ValidationException::withMessages(['platform_id' => $e->getMessage()]);
        } catch (GameIdentityLinkException $e) {
            throw ValidationException::withMessages(['platform_id' => $this->collisionMessage($e->game ?? $game, $e->platformId)]);
        }

        return back();
    }

    /** Remove the trooper's Steam or Epic ID on `$platform` from every game it is linked on (#174); the page confirms first. */
    public function destroyAccount(Request $request, int $trooper, string $platform, RemoveSharedAccountHandler $handler): RedirectResponse
    {
        $game = $this->games->findBySlug($platform) ?? abort(404);

        try {
            $handler->handle($request->user(), new RemoveSharedAccount($trooper, $game));
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        }

        return back();
    }

    /** Delete an identity kept on file for a Cav name with no trooper (Admins only), with an optional reason. */
    public function destroyKept(Request $request, int $kept, RemoveKeptGameIdentity $command): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $command->handle($request->user(), $kept, $data['reason'] ?? null);
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
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
     * The bulk import's preview: what each line of the pasted or uploaded text would do (#174 follow-up). The page
     * reads a file into text before sending it, so no file reaches the server or is stored. Writes nothing.
     */
    public function importPreview(Request $request, ImportGameIdentities $command): JsonResponse
    {
        $data = $this->importInput($request);

        try {
            return response()->json($command->preview($request->user(), $data['text'], (bool) ($data['replace'] ?? false)));
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (IdentityImportException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        }
    }

    /** Save every line the preview showed as to be added or changed, in one audited batch, or none of them. */
    public function import(Request $request, ImportGameIdentities $command): RedirectResponse
    {
        $data = $this->importInput($request, [
            'expected' => ['required', 'array', 'max:'.IdentityImportParser::MAX_LINES],
            'expected.*' => ['integer', 'min:1'],
        ]);

        try {
            $saved = $command->import($request->user(), $data['text'], (bool) ($data['replace'] ?? false), $data['expected']);
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (IdentityImportException|UnknownIdKindException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        } catch (GameIdentityLinkException $e) {
            throw ValidationException::withMessages(['text' => ($e->game === null ? $e->getMessage() : $this->collisionMessage($e->game, $e->platformId)).' Preview the file again.']);
        }

        return back()->with('notice', $saved === 1 ? 'Imported 1 identity.' : "Imported {$saved} identities.");
    }

    /**
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function importInput(Request $request, array $more = []): array
    {
        return $request->validate([
            // In characters; the parser also holds the text to 256 KB in bytes.
            'text' => ['required', 'string', 'max:'.IdentityImportParser::MAX_BYTES],
            'replace' => ['sometimes', 'boolean'],
            ...$more,
        ], [
            'text.required' => 'Choose a file or paste the identities.',
            'text.max' => 'The file is larger than 256 KB. Split it and import each part.',
        ]);
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
     * Each unit's usual game, by unit id, as a game slug: the game the unit plays, its own or the nearest ancestor's,
     * when it is not retired; else the game most of the troopers placed in the unit have an
     * identity for, the game added first on a tie (as the capture picks a section's game). Only units someone in
     * `$troopers` is placed in are worked out.
     *
     * @param  array<int, UnitGame>  $unitGames  by unit id
     * @param  Collection<int, Trooper>  $troopers
     * @param  Collection<int, Collection<int, TrooperGameIdentity>>  $identities  by trooper id
     * @param  Collection<int, Game>  $games
     * @return Collection<int, string|null>
     */
    private function usualGames(array $unitGames, Collection $troopers, Collection $identities, Collection $games): Collection
    {
        $byId = $games->keyBy('id');
        $active = $games->reject(fn (Game $game) => $game->isRetired());

        return $troopers->groupBy('primary_section_id')->filter(fn ($placed, $unitId) => $unitId !== '')
            ->map(function (Collection $placed, $unitId) use ($unitGames, $byId, $identities, $active) {
                $played = isset($unitGames[(int) $unitId]) ? $byId->get($unitGames[(int) $unitId]->gameId) : null;

                if ($played !== null && ! $played->isRetired()) {
                    return $played->slug;
                }

                $counts = $placed->flatMap(fn (Trooper $trooper) => $identities->get($trooper->id) ?? collect())->countBy('game_id');
                $best = $active->reduce(fn (?Game $best, Game $game) => ($counts[$game->id] ?? 0) > ($counts[$best?->id] ?? 0) ? $game : $best);

                return $best?->slug;
            });
    }

    /**
     * @param  array<int, UnitGame>  $unitGames  by unit id
     * @param  Collection<int, Game>  $gamesById
     */
    private function mainGame(?Unit $unit, array $unitGames, Collection $gamesById): ?string
    {
        $game = $unit !== null && $unit->isLine() && isset($unitGames[$unit->id]) ? $gamesById->get($unitGames[$unit->id]->gameId) : null;

        return $game === null || $game->isRetired() ? null : $game->slug;
    }

    private function designation(?Unit $unit): ?string
    {
        return $unit === null ? null : ($unit->callsign ?? $unit->name);
    }
}
