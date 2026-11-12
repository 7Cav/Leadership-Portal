<?php

namespace App\Http\Controllers;

use App\Commands\WeeklyReports\InvalidReportContentException;
use App\Commands\WeeklyReports\SendWeeklyReportForbiddenException;
use App\Commands\WeeklyReports\SetSectionTrainingSubject;
use App\Commands\WeeklyReports\SetSectionTrainingSubjectHandler;
use App\Commands\WeeklyReports\TrainingSubjectEnforcedException;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The week's training on a section's roll call (#703): its topic, which is the weekly report's training subject. Each
 * write is an audited Command gated by the section's write scope (AD-8); a view-as preview is refused before it gets
 * here (`ReadOnlyWhileViewingAs`). Answers JSON with the `training` prop as it now stands, so the page takes the
 * server's word: 403 outside scope, 422 for a value that cannot be saved, 409 with `locked` for a sent week (AD-12).
 */
class RollcallTrainingController extends Controller
{
    public function __construct(
        private readonly RollcallCycleProps $cycle,
        private readonly UnitRepository $units,
    ) {}

    public function topic(Request $request, int $unit, SetSectionTrainingSubjectHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'subject' => ['present', 'nullable', 'string', 'max:255'],
        ], ['subject.max' => 'Keep the training topic to :max characters.']);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $weekStart = WeekOf::start(CarbonImmutable::createFromFormat('!Y-m-d', $request->input('week'), 'UTC'));

        if ($weekStart->greaterThan(WeekOf::start(CarbonImmutable::now()))) {
            return response()->json(['message' => 'A future week cannot be changed yet.'], 422);
        }

        try {
            $handler->handle($request->user(), new SetSectionTrainingSubject($unit, $weekStart->toDateTimeImmutable(), $request->input('subject')));
        } catch (SendWeeklyReportForbiddenException) {
            return response()->json(['message' => 'This section is outside your scope.'], 403);
        } catch (InvalidReportContentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TrainingSubjectEnforcedException) {
            return response()->json(['message' => "Your platoon set this week's training topic and locked it, so it cannot be changed here. Reload the page to see it."], 422);
        } catch (WeeklyReportAlreadySentException) {
            return response()->json(['message' => 'The report for this week has been sent, so its roll call is locked.', 'locked' => true], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The portal hit an error saving the training topic. Try again in a moment.'], 500);
        }

        return response()->json(['training' => $this->cycle->training($this->units->find($unit), $weekStart, true)]);
    }
}
