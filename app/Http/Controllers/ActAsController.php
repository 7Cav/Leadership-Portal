<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\ActAs\StartActingAs;
use App\Commands\ActAs\StopActingAs;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Start and stop acting as another person or a billet (#379, #432). Only the real user behind the request may: while
 * they already act as someone the request runs as that person, so they are read from what the `ActAsUser` middleware
 * kept aside. An Admin may act as a person or a billet; a leader, someone who commands units, only as a person below
 * them; anyone else, a staff grant included, is refused. Whether that person or billet may be acted as is
 * {@see StartActingAs}'s call.
 */
class ActAsController extends Controller
{
    public function __construct(
        private readonly ActAs $actAs,
        private readonly ScopeResolver $scope,
    ) {}

    /**
     * Who the picker offers to the signed-in leader: serving billet holders strictly below them (#432), the same list
     * {@see StartActingAs} would accept. A convenience only: every choice is checked again when it is made.
     */
    public function options(Request $request, TrooperRepository $troopers, UnitCommandMappingRepository $commands): JsonResponse
    {
        $actor = $this->realUser($request);
        abort_unless($this->actAs->offers($actor), 403);

        $people = $this->actAs->candidates($actor);
        $cavIds = $people->pluck('cav_id')->all();
        $ranks = $troopers->ranksByCavId($cavIds);
        $commanded = $commands->commandedUnitsByCavId($cavIds);

        return response()->json([
            'people' => $people->map(fn (User $user) => [
                'id' => $user->id,
                'label' => trim("{$ranks->get($user->cav_id)} {$user->username}"),
                'designation' => ($unit = $commanded->get($user->cav_id)) ? ($unit->callsign ?? $unit->name) : null,
            ])->values(),
        ]);
    }

    public function store(Request $request, StartActingAs $start): RedirectResponse
    {
        $actor = $this->realUser($request);

        $data = $request->validate([
            'kind' => ['required', Rule::enum(ViewAsKind::class)],
            'id' => ['required', 'integer'],
        ]);
        $target = new ViewAsTarget(ViewAsKind::from($data['kind']), (int) $data['id']);

        // A billet is an Admin's; a person, an Admin's or a leader's. Anyone else has no act-as to refuse a choice of.
        abort_unless($this->scope->isAdmin($actor) || ($target->kind === ViewAsKind::User && $this->actAs->leads($actor)), 403);

        $held = ! $this->scope->isAdmin($actor) || $this->actAs->viaFor($actor) !== null;
        if (! $start->handle($actor, $target)) {
            throw ValidationException::withMessages([
                'act_as' => match (true) {
                    $held && $target->kind === ViewAsKind::Unit => 'Stop acting as someone first: only an Admin acting as nobody can act as a billet.',
                    $held => 'You can only act as a serving billet holder below you in your own scope, and only while not already acting as someone.',
                    $target->kind === ViewAsKind::Trooper => 'They hold no billet to act as. Ask them to sign in once, then act as them by name.',
                    $target->kind === ViewAsKind::User => 'You can act as a serving trooper, never as yourself.',
                    default => 'That unit no longer exists.',
                },
            ]);
        }

        // Their home, not the page the Admin was on: it may be one they cannot open.
        return redirect()->route('home');
    }

    public function destroy(Request $request, StopActingAs $stop): RedirectResponse
    {
        $stop->handle($this->realUser($request));

        return redirect()->route('home');
    }

    private function realUser(Request $request): User
    {
        return $request->attributes->get(ActAs::ADMIN_ATTRIBUTE) ?? $request->user();
    }
}
