<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\Fireteams\FireteamForbiddenException;
use App\Commands\Fireteams\InvalidFireteamException;
use App\Commands\Fireteams\SetCompanyFireteamPolicy;
use App\Commands\Fireteams\SetCompanyFireteamPolicyHandler;
use App\Commands\Fireteams\SetFireteamRole;
use App\Commands\Fireteams\SetFireteamRoleHandler;
use App\Commands\Fireteams\SetSectionFireteamOptions;
use App\Commands\Fireteams\SetSectionFireteamOptionsHandler;
use App\Models\FireteamOrder;
use App\Models\FireteamRole;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\SectionFireteams;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The rollcall's fireteam controls (the owner's order, 2026-10-16): a section's leaders mark fireteam leaders (FTLs),
 * fill their fireteams and choose how the post shows them; the company's command sets a policy over its sections. Each
 * change is one audited Command whose handler checks the actor commands the unit (AD-8, AD-16); a view-as preview is
 * refused before it gets here (`ReadOnlyWhileViewingAs`).
 *
 * Every success answers the section's fireteams as they now stand, the same shape the page loaded with, so the screen
 * takes the server's word rather than guessing: 403 when the actor does not command the unit, 422 with the reason when
 * the change breaks a rule.
 */
class RollcallFireteamController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly SectionFireteams $fireteams,
    ) {}

    /**
     * Mark a trooper as an FTL, put them in an FTL's fireteam, or take them out (`role` null).
     */
    public function role(Request $request, int $unit, SetFireteamRoleHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'trooper_id' => ['required', 'integer'],
            'role' => ['present', 'nullable', Rule::enum(FireteamRole::class)],
            'leader_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        return $this->attempt($request->user(), $unit, fn () => $handler->handle($request->user(), new SetFireteamRole(
            $unit,
            (int) $request->input('trooper_id'),
            FireteamRole::tryFrom((string) $request->input('role')),
            $request->filled('leader_id') ? (int) $request->input('leader_id') : null,
        )));
    }

    /**
     * The section's own choices: whether FTLs are shown (`show`) and where (`order`). Either may be left out.
     */
    public function options(Request $request, int $unit, SetSectionFireteamOptionsHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'show' => ['required_without:order', 'boolean'],
            'order' => ['required_without:show', Rule::enum(FireteamOrder::class)],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        return $this->attempt($request->user(), $unit, fn () => $handler->handle($request->user(), new SetSectionFireteamOptions(
            $unit,
            $request->has('show') ? $request->boolean('show') : null,
            FireteamOrder::tryFrom((string) $request->input('order')),
        )));
    }

    /**
     * The policy of the company `$unit` sits under: `show` true, false or null, `order` `leaders_first` or null (null
     * leaves that choice to the sections), and `sl_asl_lead`, whether the SL and ASL may lead a fireteam. All three are
     * given each time.
     */
    public function policy(Request $request, int $unit, SetCompanyFireteamPolicyHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'show' => ['present', 'nullable', 'boolean'],
            'order' => ['present', 'nullable', Rule::enum(FireteamOrder::class)],
            'sl_asl_lead' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $section = $this->units->find($unit);

        // Authorization first: an id that names no unit, or a unit the actor does not command, is the same 403, so
        // ids cannot be probed (AD-8). Only someone who commands the unit learns that it has no company above it.
        if ($section === null || ! $this->scope->commandsUnit($request->user(), $section)) {
            return response()->json(['message' => (new FireteamForbiddenException($unit))->getMessage()], 403);
        }

        $company = $this->fireteams->companyOf($section);

        if ($company === null) {
            return response()->json(['message' => 'This section does not sit under a company, so there is no company policy to set.'], 422);
        }

        return $this->attempt($request->user(), $unit, fn () => $handler->handle($request->user(), new SetCompanyFireteamPolicy(
            $company->id,
            $request->input('show') === null ? null : $request->boolean('show'),
            FireteamOrder::tryFrom((string) $request->input('order')),
            $request->boolean('sl_asl_lead'),
        )));
    }

    /**
     * Run the Command and answer the section's fireteams, or the refusal.
     */
    private function attempt(User $user, int $sectionId, Closure $write): JsonResponse
    {
        try {
            $write();
        } catch (FireteamForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidFireteamException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error saving the fireteams. Try again in a moment.'], 500);
        }

        return response()->json($this->state($user, $this->units->find($sectionId)));
    }

    /**
     * @return array{fireteams: array<string, mixed>, troopers: list<array{id: int, role: ?string, leadsFireteam: bool, fireteamLeaderId: ?int}>}
     */
    private function state(User $user, Unit $section): array
    {
        $roles = $this->fireteams->roles($section, $this->troopers->placedIn($section->id));
        $company = $this->fireteams->companyOf($section);

        return [
            'fireteams' => $this->fireteams->options(
                $section,
                canEdit: $this->scope->commandsUnit($user, $section),
                canEditPolicy: $company !== null && $this->scope->commandsUnit($user, $company),
            ),
            'troopers' => collect($roles)->map(fn (array $role, int $id) => ['id' => $id, ...$role])->values()->all(),
        ];
    }
}
