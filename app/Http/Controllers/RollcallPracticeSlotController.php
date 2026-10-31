<?php

namespace App\Http\Controllers;

use App\Commands\Attendance\RollcallWeekLockedException;
use App\Commands\Practices\ChangePracticeWeek;
use App\Commands\Practices\InvalidPracticeSlotException;
use App\Commands\Practices\PracticeSchedule;
use App\Commands\Practices\PracticeSlotForbiddenException;
use App\Commands\Practices\SetPracticeSlot;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * A section's practice slot on its roll call (#392): set or clear the weekly slot, and move or cancel one week's
 * practice. Each write is an audited Command gated by the section's write scope (AD-8); a view-as preview is refused
 * before it gets here (`ReadOnlyWhileViewingAs`). Answers JSON with the `practiceSlot` prop as it now stands, so the
 * page takes the server's word; 403 outside scope, 422 for a change that cannot be made, 409 for a sent week.
 */
class RollcallPracticeSlotController extends Controller
{
    public function __construct(private readonly PracticeSchedule $schedule) {}

    public function slot(Request $request, int $unit, SetPracticeSlot $command): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'weekday' => ['present', 'nullable', 'integer', 'between:0,6'],
            'start_minute' => ['present', 'nullable', 'integer', 'between:0,1439'],
            'week' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        return $this->answer($unit, $request->input('week'), fn () => $command->handle(
            $request->user(),
            $unit,
            $request->filled('weekday') ? (int) $request->input('weekday') : null,
            $request->filled('start_minute') ? (int) $request->input('start_minute') : null,
        ));
    }

    public function week(Request $request, int $unit, ChangePracticeWeek $command): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'cancelled' => ['required', 'boolean'],
            // An ISO 8601 instant; the page sends it in Zulu.
            'moved_to' => ['present', 'nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        return $this->answer($unit, $request->input('week'), fn () => $command->handle(
            $request->user(),
            $unit,
            CarbonImmutable::parse($request->input('week'), 'UTC'),
            $request->filled('moved_to') ? CarbonImmutable::parse($request->input('moved_to'))->utc() : null,
            $request->boolean('cancelled'),
        ));
    }

    private function answer(int $unit, string $week, callable $write): JsonResponse
    {
        try {
            $write();
        } catch (PracticeSlotForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidPracticeSlotException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RollcallWeekLockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'locked' => true], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error saving the practice slot. Try again in a moment.'], 500);
        }

        $weekStart = WeekOf::start(CarbonImmutable::parse($week, 'UTC'));

        return response()->json([
            'practiceSlot' => $this->schedule->props($unit, $weekStart, null),
        ]);
    }
}
