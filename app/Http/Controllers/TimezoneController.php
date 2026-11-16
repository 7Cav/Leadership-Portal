<?php

namespace App\Http\Controllers;

use App\Authorization\ActAs;
use App\Repositories\PracticeSlotConfirmationRepository;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The time zone the signed-in person's browser reports (16NOV26), sent by the page once when it differs from the one
 * kept. It only tells the portal when clocks change where a platoon leader lives ({@see ConfirmClockChange}), so it is
 * kept as a plain setting of the account, not audited. Never while viewing as or acting as someone: the zone is theirs.
 */
class TimezoneController extends Controller
{
    public function update(Request $request, PracticeSlotConfirmationRepository $repository): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'timezone' => ['required', 'string', 'max:64', fn (string $attribute, mixed $value, \Closure $fail) => in_array($value, DateTimeZone::listIdentifiers(), true) ? null : $fail('Unknown time zone.')],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        // While an Admin acts as someone, the request runs as them: their zone is not the Admin's browser's.
        if ($request->attributes->get(ActAs::ADMIN_ATTRIBUTE) === null) {
            $repository->setTimezone($request->user()->id, (string) $request->input('timezone'));
        }

        return response()->json(['timezone' => $request->input('timezone')]);
    }
}
