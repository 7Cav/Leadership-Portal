<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The view-as switcher (Story 1.13, AD-9). Not a page of its own: the shell's picker reads its
 * options here and posts the choice back. Whether a target is allowed is never decided here —
 * {@see ScopeResolver::evaluate()} makes that call, at selection and again on every request.
 */
class ViewAsController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly ViewAs $viewAs,
    ) {}

    /**
     * What the picker may offer: the units inside the viewer's real scope (a leaf leader gets
     * only their own section, AC #6) and, for an Admin, every other portal user.
     */
    public function options(
        Request $request,
        UnitRepository $units,
        UserRepository $users,
        TrooperRepository $troopers,
        UnitCommandMappingRepository $commands,
    ): JsonResponse {
        $viewer = $request->user();
        $real = $this->scope->realScope($viewer)->flip();

        $unitOptions = $units->tree()
            ->filter(fn ($unit) => $real->has($unit->id))
            ->map(fn ($unit) => [
                'id' => $unit->id,
                'designation' => $unit->callsign ?? $unit->name,
                'name' => $unit->name,
                'type' => $unit->type->value,
            ])->values();

        $people = null;
        if ($viewer->is_admin) {
            $others = $users->all()->reject(fn ($user) => $user->id === $viewer->id);
            $cavIds = $others->pluck('cav_id')->filter()->values()->all();
            $ranks = $troopers->ranksByCavId($cavIds);
            $commanded = $commands->commandedUnitsByCavId($cavIds);

            $people = $others->map(function ($user) use ($ranks, $commanded) {
                $unit = $user->cav_id !== null ? $commanded->get($user->cav_id) : null;

                return [
                    'id' => $user->id,
                    'label' => trim(($user->cav_id !== null ? $ranks->get($user->cav_id) : '').' '.$user->username),
                    'designation' => $unit ? ($unit->callsign ?? $unit->name) : null,
                    'admin' => $user->is_admin,
                ];
            })->values();
        }

        $active = $this->scope->activeViewAs($viewer);

        return response()->json([
            'units' => $unitOptions,
            'people' => $people,
            'current' => $active ? ['kind' => $active->target->kind->value, 'id' => $active->target->id] : null,
        ]);
    }

    /**
     * Start viewing as a unit or person. A target outside real authority — however the request
     * was built — is refused with a validation error and nothing is stored (AC #3).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ViewAsKind::class)],
            'id' => ['required', 'integer'],
        ]);

        $target = new ViewAsTarget(ViewAsKind::from($data['kind']), (int) $data['id']);

        if ($this->scope->evaluate($request->user(), $target) === null) {
            throw ValidationException::withMessages([
                'view_as' => $target->kind === ViewAsKind::User
                    ? 'Only an Admin can view the portal as another person.'
                    : 'You can only view as a unit inside your own scope.',
            ]);
        }

        $this->viewAs->enter($request->user(), $target);

        return redirect()->back();
    }

    /**
     * Back to the viewer's own, full real scope (AC #5).
     */
    public function destroy(): RedirectResponse
    {
        $this->viewAs->exit();

        return redirect()->back();
    }
}
