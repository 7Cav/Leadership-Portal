<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Games\AddGame;
use App\Commands\Games\DeleteGame;
use App\Commands\Games\InvalidGameException;
use App\Commands\Games\RetireGame;
use App\Commands\Games\UpdateGame;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\TelemetryInterface;
use App\Repositories\GameInUseException;
use App\Repositories\GameRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The games the regiment plays (#388): an Admin adds a game, renames it, sets the kinds of player id it asks for, picks
 * which built-in telemetry interface reads its attendance (AD-3: the interfaces stay code), and retires or deletes
 * it. Reads `GameRepository` directly; every write is an audited Command (AD-16). Viewing is open to anyone signed
 * in, matching the other Settings screens; every write is Admin-only, checked here against the real `is_admin`
 * flag (AD-6) before anything else, so a non-Admin gets a 403 whether or not the game exists.
 */
class GamesController extends Controller
{
    public function __construct(private readonly GameRepository $games) {}

    public function index(Request $request, ScopeResolver $scope): Response
    {
        $inUse = array_flip($this->games->inUseIds());

        return Inertia::render('Settings/Games', [
            'games' => $this->games->all()->map(fn (Game $game) => [
                'id' => $game->id,
                'slug' => $game->slug,
                'name' => $game->name,
                'idKinds' => $game->id_kinds,
                'interface' => $game->interface->value,
                'retired' => $game->isRetired(),
                // A game in use can be retired, not deleted: the page offers only what the server would accept.
                'inUse' => isset($inUse[$game->id]),
            ])->values()->all(),
            'interfaces' => array_map(fn (TelemetryInterface $interface) => [
                'value' => $interface->value,
                'label' => $interface->label(),
            ], TelemetryInterface::cases()),
            // Follows a view-as preview (Story 1.13), like the other Settings screens; the write gates read the real flag.
            'isAdmin' => $scope->actsAsAdmin($request->user()),
        ]);
    }

    public function store(Request $request, AddGame $command): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate($this->rules());

        try {
            $command->handle($request->user(), $data['name'], $data['id_kinds'], TelemetryInterface::from($data['interface']));
        } catch (InvalidGameException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return redirect()->route('settings.games');
    }

    public function update(Request $request, int $game, UpdateGame $command): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $game = $this->games->find($game) ?? abort(404);

        $data = $request->validate($this->rules($game));

        try {
            $command->handle($request->user(), $game, $data['name'], $data['id_kinds'], TelemetryInterface::from($data['interface']));
        } catch (InvalidGameException $e) {
            throw ValidationException::withMessages(['id_kinds' => $e->getMessage()]);
        }

        return redirect()->route('settings.games');
    }

    public function retire(Request $request, int $game, RetireGame $command): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $command->handle($request->user(), $this->games->find($game) ?? abort(404), true);

        return redirect()->route('settings.games');
    }

    public function reinstate(Request $request, int $game, RetireGame $command): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $command->handle($request->user(), $this->games->find($game) ?? abort(404), false);

        return redirect()->route('settings.games');
    }

    public function destroy(Request $request, int $game, DeleteGame $command): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $game = $this->games->find($game) ?? abort(404);

        try {
            $command->handle($request->user(), $game);
        } catch (GameInUseException $e) {
            throw ValidationException::withMessages(['game' => $e->getMessage()]);
        }

        return redirect()->route('settings.games');
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user()->is_admin) {
            abort(403, 'Only an Admin may manage games.');
        }
    }

    /**
     * Two games may not share a name, or the identity screen's game picker could not tell them apart.
     *
     * @return array<string, list<mixed>>
     */
    private function rules(?Game $game = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('games', 'name')->ignore($game?->id)],
            // The kinds of player ID the game asks for ("Steam ID", "Epic ID"), the first being the default (#428).
            'id_kinds' => ['required', 'array', 'min:1', 'max:8'],
            'id_kinds.*' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'interface' => ['required', Rule::enum(TelemetryInterface::class)],
        ];
    }
}
