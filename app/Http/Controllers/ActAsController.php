<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\ActAs\StartActingAs;
use App\Commands\ActAs\StopActingAs;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Start and stop acting as another person or a billet (#379). Only the real Admin behind the request may: while they already act
 * as someone the request runs as that person, so the Admin is read from what the `ActAsUser` middleware kept aside.
 * Whether the person or billet may be acted as is {@see StartActingAs}'s call.
 */
class ActAsController extends Controller
{
    public function store(Request $request, StartActingAs $start): RedirectResponse
    {
        $admin = $this->realUser($request);
        abort_unless($admin->is_admin, 403);

        $data = $request->validate([
            'kind' => ['required', Rule::enum(ViewAsKind::class)],
            'id' => ['required', 'integer'],
        ]);
        $target = new ViewAsTarget(ViewAsKind::from($data['kind']), (int) $data['id']);

        if (! $start->handle($admin, $target)) {
            throw ValidationException::withMessages([
                'act_as' => $target->kind === ViewAsKind::User
                    ? 'You can act as a serving member who is not an Admin, and never as yourself.'
                    : 'That unit no longer exists.',
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
