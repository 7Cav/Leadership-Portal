<?php

namespace App\Http\Controllers;

use App\Commands\Units\InvalidGuidonException;
use App\Commands\Units\SaveUnitGuidon;
use App\Commands\Units\SaveUnitGuidonHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Saves a unit's guidon from the roster's generator (#446). Who may, and which units carry one, is checked in
 * {@see SaveUnitGuidonHandler} (a 403 for a unit the actor does not command, missing or not); the same rules on the
 * fields are checked here first, so the form shows them next to the field.
 */
class UnitGuidonController extends Controller
{
    public function update(Request $request, int $unit, SaveUnitGuidonHandler $handler): RedirectResponse
    {
        $text = ['nullable', 'string', 'max:'.SaveUnitGuidonHandler::MAX_TEXT, 'regex:'.SaveUnitGuidonHandler::TEXT_PATTERN];
        $color = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}\z/'];

        $data = $request->validate([
            'primary' => $color,
            'secondary' => $color,
            'topLeft' => $text,
            'bottomRight' => $text,
        ], [
            'primary.regex' => 'Pick a color as #rrggbb.',
            'secondary.regex' => 'Pick a color as #rrggbb.',
            'topLeft.regex' => 'Use letters, digits, /, - or spaces only.',
            'bottomRight.regex' => 'Use letters, digits, /, - or spaces only.',
        ]);

        try {
            $handler->handle($request->user(), new SaveUnitGuidon(
                unitId: $unit,
                primaryColor: $data['primary'],
                secondaryColor: $data['secondary'],
                topLeftText: (string) ($data['topLeft'] ?? ''),
                bottomRightText: (string) ($data['bottomRight'] ?? ''),
            ));
        } catch (InvalidGuidonException $e) {
            throw ValidationException::withMessages(['guidon' => $e->getMessage()]);
        }

        return back();
    }
}
