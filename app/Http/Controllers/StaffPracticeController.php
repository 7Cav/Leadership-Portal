<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\StaffPractices\RecordStaffPractice;
use App\Commands\StaffPractices\RecordStaffPracticeHandler;
use App\Commands\StaffPractices\RemoveStaffPractice;
use App\Commands\StaffPractices\RemoveStaffPracticeHandler;
use App\Commands\StaffPractices\StaffPracticeForbiddenException;
use App\Commands\StaffPractices\StaffPracticeRefusedException;
use App\Repositories\TrooperRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The section practices a staff member attended (#448), entered and removed on their milpac. Orchestration only: each
 * write is one audited Command whose handler decides who may (`StaffPracticeAccess`: the staff member, their staff
 * leader, an Admin) and which practice and week; a view-as preview is refused before it gets here
 * (`ReadOnlyWhileViewingAs`). A trooper the viewer cannot see answers 404, like one that does not exist (AD-8); a
 * refusal of the actor is a 403, and a refused practice or week is a validation error on its field.
 */
class StaffPracticeController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
    ) {}

    public function store(Request $request, int $trooper, RecordStaffPracticeHandler $handler): RedirectResponse
    {
        return $this->attempt($request, $trooper, fn (int $section, CarbonImmutable $week) => $handler->handle(
            $request->user(),
            new RecordStaffPractice($trooper, $section, $week),
        ));
    }

    public function destroy(Request $request, int $trooper, RemoveStaffPracticeHandler $handler): RedirectResponse
    {
        return $this->attempt($request, $trooper, fn (int $section, CarbonImmutable $week) => $handler->handle(
            $request->user(),
            new RemoveStaffPractice($trooper, $section, $week),
        ));
    }

    /**
     * Gate on the trooper being visible, validate the shared body (`section_id`, `week`), run `$write` and map its
     * refusals, then go back to the milpac.
     *
     * @param  Closure(int, CarbonImmutable): mixed  $write
     */
    private function attempt(Request $request, int $trooper, Closure $write): RedirectResponse
    {
        $found = $this->troopers->find($trooper);
        abort_unless($found !== null && $this->scope->canSee($request->user(), $found), 404);

        $data = $request->validate([
            'section_id' => ['required', 'integer'],
            'week' => ['required', 'date_format:Y-m-d'],
        ], [
            'section_id.required' => 'Pick the section whose practice they attended.',
            'week.required' => 'Pick the Cav week.',
        ]);

        try {
            $write((int) $data['section_id'], CarbonImmutable::createFromFormat('!Y-m-d', $data['week'], 'UTC'));
        } catch (StaffPracticeForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (StaffPracticeRefusedException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('troopers.show', $found->id);
    }
}
