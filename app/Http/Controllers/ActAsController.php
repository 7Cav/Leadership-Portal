<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Commands\ActAs\StartActingAs;
use App\Commands\ActAs\StopActingAs;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Start and stop acting as another person (#379). Only the real Admin behind the request may: while they already act
 * as someone the request runs as that person, so the Admin is read from what the `ActAsUser` middleware kept aside.
 * Whether the person may be acted as is {@see StartActingAs}'s call.
 */
class ActAsController extends Controller
{
    public function store(Request $request, StartActingAs $start): RedirectResponse
    {
        $admin = $this->realUser($request);
        abort_unless($admin->is_admin, 403);

        $data = $request->validate(['user' => ['required', 'integer']]);

        if (! $start->handle($admin, (int) $data['user'])) {
            throw ValidationException::withMessages([
                'act_as' => 'You can act as a serving member who is not an Admin, and never as yourself.',
            ]);
        }

        // Their home, not the page the Admin was on: it may be one the person cannot open.
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
