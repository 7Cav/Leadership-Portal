<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Units\AcknowledgeBilletConflict;
use App\Commands\Units\AssignBillet;
use App\Commands\Units\ConfirmBilletName;
use App\Commands\Units\InvalidBilletChangeException;
use App\Commands\Units\LeaveBilletUnplaced;
use App\Commands\Units\UnassignBillet;
use App\Commands\Units\UndoBilletAcknowledgement;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The billet writes on Org structure (2026-11-13): place a billet in a unit (a new one, one added to a unit's list, or
 * a placed one moved), undo an Admin's placement, confirm a placed billet's new Cav name, leave a new billet
 * unplaced, or acknowledge a billet in two units at once (and undo that). Admin-only, server-side (AD-6), checked before anything about
 * the billet is looked up; each write is audited by its Command (AD-16). The billet is named by its Cav position id. A
 * rule a Command enforces comes back as a form error on `billet`.
 */
class OrgBilletsController extends Controller
{
    private const MESSAGES = [
        'unit_id.required' => 'Choose the unit to place the billet in.',
        'unit_id.exists' => 'That unit no longer exists. Reload the page and choose another.',
        'reason.required' => 'Give a reason for the record.',
        'reason.max' => 'Keep the reason to :max characters.',
        'kind.required' => 'Reload the page and try again.',
        'kind.in' => 'Reload the page and try again.',
    ];

    public function __construct(private readonly ScopeResolver $scope) {}

    public function assign(Request $request, int $position, AssignBillet $command): RedirectResponse
    {
        $this->gate($request);

        $data = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'reason' => ['required', 'string', 'max:500'],
            // Set once the Admin confirmed moving a billet an Admin placed in another unit.
            'confirm_move' => ['sometimes', 'boolean'],
        ], self::MESSAGES);

        return $this->run(fn () => $command->handle($request->user(), $position, (int) $data['unit_id'], $data['reason'], (bool) ($data['confirm_move'] ?? false)));
    }

    public function unassign(Request $request, int $position, UnassignBillet $command): RedirectResponse
    {
        $this->gate($request);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], self::MESSAGES);

        return $this->run(fn () => $command->handle($request->user(), $position, $data['reason']));
    }

    public function confirmName(Request $request, int $position, ConfirmBilletName $command): RedirectResponse
    {
        $this->gate($request);

        return $this->run(fn () => $command->handle($request->user(), $position));
    }

    public function leaveUnplaced(Request $request, int $position, LeaveBilletUnplaced $command): RedirectResponse
    {
        $this->gate($request);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], self::MESSAGES);

        return $this->run(fn () => $command->handle($request->user(), $position, $data['reason']));
    }

    public function acknowledge(Request $request, int $position, AcknowledgeBilletConflict $command): RedirectResponse
    {
        $this->gate($request);

        $data = $this->validateConflict($request);

        return $this->run(fn () => $command->handle($request->user(), $position, $data['kind'], $data['reason']));
    }

    public function unacknowledge(Request $request, int $position, UndoBilletAcknowledgement $command): RedirectResponse
    {
        $this->gate($request);

        $data = $this->validateConflict($request);

        return $this->run(fn () => $command->handle($request->user(), $position, $data['kind'], $data['reason']));
    }

    /**
     * A conflict is named by one of its billets (the route) and its kind: one name in several units, or an Admin's
     * placement against the rules.
     *
     * @return array{kind: string, reason: string}
     */
    private function validateConflict(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'string', Rule::in(['title', 'rule'])],
            'reason' => ['required', 'string', 'max:500'],
        ], self::MESSAGES);
    }

    private function gate(Request $request): void
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may change where billets are placed.');
        }
    }

    private function run(callable $write): RedirectResponse
    {
        try {
            $write();
        } catch (InvalidBilletChangeException $e) {
            // A move the Admin may confirm comes back under its own key, so the page can ask before sending it again.
            throw ValidationException::withMessages([$e->needsConfirmation ? 'billet_move' : 'billet' => $e->getMessage()]);
        }

        return redirect()->route('settings.org-structure');
    }
}
