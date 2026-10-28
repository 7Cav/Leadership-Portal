<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\ViewAs\EnterViewAs;
use App\Commands\ViewAs\ExitViewAs;
use App\Kpi\Headcount;
use App\Models\UnitType;
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
    ) {}

    /**
     * What the picker may offer: the units inside the viewer's real scope (a leaf leader gets
     * only their own section, AC #6) and, for an Admin, every other portal user and every serving
     * trooper who has no account yet (`kind` says which; `account` is false for the latter).
     *
     * A line unit with no troopers is left out, as the roster and Home leave it out (#442, #496), unless it is the
     * one the viewer views as now. Only the list: a unit named by id is still checked by {@see EnterViewAs} alone.
     */
    public function options(
        Request $request,
        UnitRepository $units,
        UserRepository $users,
        TrooperRepository $troopers,
        UnitCommandMappingRepository $commands,
        Headcount $headcount,
    ): JsonResponse {
        $viewer = $request->user();
        $real = $this->scope->realScope($viewer)->flip();
        $active = $this->scope->activeViewAs($viewer);
        $current = $active?->target->kind === ViewAsKind::Unit ? [$active->target->id] : [];

        $unitOptions = $headcount->withoutEmptyLineUnits($units->tree()->filter(fn ($unit) => $real->has($unit->id)), keep: $current)
            ->map(fn ($unit) => [
                'id' => $unit->id,
                'designation' => $unit->callsign ?? $unit->name,
                'name' => $unit->name,
                'type' => $unit->type->value,
            ])->values();

        $people = null;
        if ($this->scope->isAdmin($viewer)) {
            $others = $users->all()->reject(fn ($user) => $user->id === $viewer->id);
            // Troopers who have never signed in have no account, but can still be previewed from their billet.
            $unregistered = $troopers->servingWithoutAccount();
            $cavIds = $others->pluck('cav_id')->merge($unregistered->pluck('cav_id'))->filter()->values()->all();
            $ranks = $troopers->ranksByCavId($cavIds);
            $commanded = $commands->commandedUnitsByCavId($cavIds);
            // Admins by flag or by an approved Admin billet, read for everyone at once.
            $admins = $this->scope->adminIdsAmong($others)->flip();
            $designation = fn (?int $cavId) => ($unit = $cavId !== null ? $commanded->get($cavId) : null) ? ($unit->callsign ?? $unit->name) : null;

            $people = $others->map(fn ($user) => [
                'kind' => 'user',
                'id' => $user->id,
                'label' => trim(($user->cav_id !== null ? $ranks->get($user->cav_id) : '').' '.$user->username),
                'designation' => $designation($user->cav_id),
                'admin' => $admins->has($user->id),
                'account' => true,
            ])->merge($unregistered->map(fn ($trooper) => [
                'kind' => 'trooper',
                'id' => $trooper->id,
                'label' => $trooper->display_name,
                'designation' => $designation($trooper->cav_id),
                'admin' => false,
                'account' => false,
            ]))->values();
        }

        return response()->json([
            'units' => $unitOptions,
            'people' => $people,
            'current' => $active ? ['kind' => $active->target->kind->value, 'id' => $active->target->id] : null,
        ]);
    }

    /**
     * Start viewing as a unit or person, recorded in the audit log. A target outside real
     * authority — however the request was built — is refused with a validation error and nothing
     * is stored or logged (AC #3).
     */
    public function store(Request $request, EnterViewAs $enter, UnitRepository $units): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ViewAsKind::class)],
            'id' => ['required', 'integer'],
        ]);

        $target = new ViewAsTarget(ViewAsKind::from($data['kind']), (int) $data['id']);

        if (! $enter->handle($request->user(), $target)) {
            throw ValidationException::withMessages([
                'view_as' => $target->kind === ViewAsKind::Unit
                    ? 'You can only view as a unit inside your own scope.'
                    : 'Only an Admin can view the portal as another person.',
            ]);
        }

        return redirect()->to($this->openingPage($request, $target, $units));
    }

    /**
     * Back to the viewer's own, full real scope (AC #5), recorded in the audit log. The page they were on stays open:
     * real scope holds everything the preview did. Only a page on this site is gone back to; anything else opens Home.
     */
    public function destroy(Request $request, ExitViewAs $exit): RedirectResponse
    {
        $exit->handle($request->user());

        $previous = $this->previousPage($request);

        return redirect()->to($previous === null
            ? route('home', absolute: false)
            : $previous['path'].($previous['query'] === '' ? '' : '?'.$previous['query']));
    }

    /**
     * Where a new preview opens (#426). The page the viewer was on is often one the narrower preview cannot open (a
     * battalion's roster, once they view as a section), so they get the same page for the unit they now view as when
     * there is one: its roster, its rollcall (a section) or the rollcall landing, its report for the same week or the
     * reports landing, its readiness matrix, and its roster from a trooper page. A person preview has no unit of its
     * own, so it gets each page's landing, which opens where that person would. Anything else opens Home. Only the
     * Referer's path is read, against these known shapes, and the address is built here from the target's id and a
     * checked week, so it never points anywhere this list does not.
     */
    private function openingPage(Request $request, ViewAsTarget $target, UnitRepository $units): string
    {
        $home = route('home', absolute: false);
        $previous = $this->previousPage($request);
        if ($previous === null) {
            return $home;
        }

        parse_str($previous['query'], $query);
        $path = $previous['path'];
        $unit = $target->kind === ViewAsKind::Unit ? $units->find($target->id) : null;
        $line = $unit !== null && $unit->isLine();
        $week = is_string($query['week'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query['week']) === 1
            ? '?week='.$query['week']
            : '';

        return match (true) {
            preg_match('#^/roster(/\d+)?$#', $path) === 1 => ($unit === null ? '/roster' : "/roster/{$unit->id}")
                .(in_array($query['view'] ?? null, ['orbat', 'matrix'], true) ? '?view='.$query['view'] : ''),
            preg_match('#^/troopers/\d+$#', $path) === 1 => $unit === null ? '/roster' : "/roster/{$unit->id}",
            preg_match('#^/rollcall(/\d+)?$#', $path) === 1 => ($unit?->type === UnitType::Section ? "/rollcall/{$unit->id}" : '/rollcall').$week,
            preg_match('#^/reports/\d+/(\d{4}-\d{2}-\d{2})$#', $path, $match) === 1 => $line ? "/reports/{$unit->id}/{$match[1]}" : '/reports',
            preg_match('#^/reports(/\d+)?$#', $path) === 1 => ($line ? "/reports/{$unit->id}" : '/reports').$week,
            preg_match('#^/reports/\d+/plans(/[0-9-]+)?$#', $path) === 1 => '/reports',
            preg_match('#^/readiness/\d+$#', $path) === 1 && $unit !== null => "/readiness/{$unit->id}",
            default => $home,
        };
    }

    /**
     * The path and query of the page the request came from, read from its Referer, when that page is on this site: no
     * other host, and a path that cannot be read as another host (`//...`). Null otherwise, or with no Referer.
     *
     * @return array{path: string, query: string}|null
     */
    private function previousPage(Request $request): ?array
    {
        $referer = $request->headers->get('referer');
        $parts = is_string($referer) ? parse_url($referer) : false;

        if ($parts === false || (isset($parts['host']) && strcasecmp($parts['host'], $request->getHost()) !== 0)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        return ['path' => $path, 'query' => $parts['query'] ?? ''];
    }
}
