<?php

namespace App\Http\Controllers;

use App\Commands\Units\AccountabilityOptionsForbiddenException;
use App\Commands\Units\AccountabilityRules;
use App\Commands\Units\InvalidAccountabilityOptionsException;
use App\Commands\Units\SetAccountabilityOptions;
use App\Repositories\UnitRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * A unit's accountability options on its roster page: the audited {@see SetAccountabilityOptions} Command, gated by
 * {@see AccountabilityRules::mayChange()}. A view-as preview is refused before it gets here (`ReadOnlyWhileViewingAs`).
 * Answers JSON with the `accountability` prop as it now stands; 403 for anyone else (one answer whether or not the
 * unit exists), 422 for options it may not take. Null options go back to the inherited ones.
 */
class RosterAccountabilityController extends Controller
{
    public function update(Request $request, int $unit, SetAccountabilityOptions $command, AccountabilityRules $rules, UnitRepository $units): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'options' => ['present', 'nullable', 'array', 'max:'.count(AccountabilityRules::OPTIONS)],
            'options.*' => ['string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $command->handle($request->user(), $unit, $request->input('options'));
        } catch (AccountabilityOptionsForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidAccountabilityOptionsException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error saving the accountability options. Try again in a moment.'], 500);
        }

        return response()->json(['accountability' => $rules->props($request->user(), $units->find($unit))]);
    }
}
