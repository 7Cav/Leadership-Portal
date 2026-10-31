<?php

namespace App\Http\Controllers;

use App\Commands\Counseling\CounselingForm;
use App\Commands\Counseling\CounselingRecordNotFoundException;
use App\Commands\Counseling\CounselingResponse;
use App\Commands\Counseling\CounselingRules;
use App\Commands\Counseling\InvalidCounselingRecordException;
use App\Commands\Counseling\ReviewCounselingRecord;
use App\Commands\Counseling\ReviewCounselingRecordHandler;
use App\Models\CounselingKind;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Filling in a record kept with its source PDF (#565): an upload from before records were written in the portal, or an
 * import, flagged for review or not. The form is on the record page (`Counseling/Record`, `can.review`).
 *
 * Orchestration only: the request's shape is checked here, and {@see ReviewCounselingRecordHandler} decides who may
 * (the issuer's chain and Admins, `CounselingPermissions::reviews()`) and checks every field again. A record the viewer
 * cannot see is a 404, exactly like one that does not exist; one they see but may not fill in is a 403.
 */
class CounselingReviewController extends Controller
{
    /**
     * `type`, `kind`, `counseled_on` (Y-m-d) and `form`: the leader's fields ({@see CounselingForm}) plus the
     * trooper's `plan_trooper`, `comments_trooper` and `appeal`.
     */
    public function review(Request $request, int $counseling, ReviewCounselingRecordHandler $handler): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(CounselingType::class)],
            'kind' => ['required', Rule::enum(CounselingKind::class)],
            'counseled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.CounselingRules::EARLIEST],
            ...CounselingForm::rules(),
            'form.plan_trooper' => ['nullable', 'string', 'max:'.CounselingForm::PLAN_MAX],
            'form.comments_trooper' => ['nullable', 'string', 'max:'.CounselingForm::COMMENT_MAX],
            'form.appeal' => ['nullable', 'string', 'in:'.implode(',', CounselingResponse::APPEALS)],
        ], [
            'kind.required' => 'Choose what the record is for.',
            'kind.enum' => 'Choose what the record is for.',
            'counseled_on.required' => 'Give the Date of Letter.',
            'counseled_on.before_or_equal' => 'The date cannot be in the future.',
            'counseled_on.after_or_equal' => 'The date is too far back.',
            'form.appeal.in' => 'Choose whether the trooper appealed.',
            ...CounselingForm::messages(),
        ]);

        $type = CounselingType::from($data['type']);
        $form = $data['form'] ?? [];

        try {
            $handler->handle($request->user(), new ReviewCounselingRecord(
                recordId: $counseling,
                type: $type,
                kind: CounselingKind::from($data['kind']),
                counseledOn: CarbonImmutable::createFromFormat('Y-m-d', $data['counseled_on'], 'UTC'),
                form: CounselingForm::fromInput($form, $type),
                response: CounselingResponse::fromInput($form, $type),
            ));
        } catch (CounselingRecordNotFoundException) {
            abort(404);
        } catch (InvalidCounselingRecordException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return back()->with('status', 'Fields saved.');
    }
}
