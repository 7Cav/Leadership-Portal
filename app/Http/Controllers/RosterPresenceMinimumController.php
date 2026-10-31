<?php

namespace App\Http\Controllers;

use App\Commands\Practices\InvalidPresenceMinimumException;
use App\Commands\Practices\PresenceMinimum;
use App\Commands\Practices\PresenceMinimumForbiddenException;
use App\Commands\Practices\SetPresenceMinimum;
use App\Repositories\UnitRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * A battalion's practice presence minimum on its roster page (#558): the audited {@see SetPresenceMinimum} Command,
 * gated on the battalion's HQ staff and Admins. A view-as preview is refused before it gets here
 * (`ReadOnlyWhileViewingAs`). Answers JSON with the `presenceMinimum` prop as it now stands; 403 for anyone else (one
 * answer whether or not the unit exists), 422 for minutes out of range.
 */
class RosterPresenceMinimumController extends Controller
{
    public function update(Request $request, int $unit, SetPresenceMinimum $command, PresenceMinimum $minimum, UnitRepository $units): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'minutes' => ['present', 'nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $command->handle($request->user(), $unit, $request->filled('minutes') ? (int) $request->input('minutes') : null);
        } catch (PresenceMinimumForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidPresenceMinimumException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error saving the presence minimum. Try again in a moment.'], 500);
        }

        return response()->json(['presenceMinimum' => $minimum->props($request->user(), $units->find($unit))]);
    }
}
