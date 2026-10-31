<?php

namespace App\Http\Controllers;

use App\Authorization\CounselingAccess;
use App\Http\Controllers\Concerns\ReadsForumPmGrant;
use App\Milpac\CounselingRecordView;
use App\Models\CounselingRecord;
use App\Repositories\CounselingRecordRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The appeals queue (#565): the LORs appealed to the viewer as the commander one level above the issuing unit, waiting
 * ones first with their 72-hour due time, then the ones decided in the last 90 days. Deciding is
 * `CounselingController::decide()`, which the handler judges again. A viewer who leads no unit sees an empty queue: the
 * sidebar does not offer them the page (the `counselingAppeals` shared prop).
 */
class CounselingAppealsController extends Controller
{
    use ReadsForumPmGrant;

    public function index(Request $request, CounselingAccess $access, CounselingRecordRepository $records): Response
    {
        $unitId = $access->leadsUnit($request->user());
        $now = CarbonImmutable::now();

        // What the viewer may do, worked out once per trooper however many of their LORs are listed.
        $permissions = [];
        $for = fn (CounselingRecord $record) => $permissions[$record->trooper_id] ??= $access->for($request->user(), $record->trooper);

        $appeals = $unitId === null ? collect() : $records->appealsFor($unitId)
            ->filter(fn (CounselingRecord $record) => $for($record)->seesRecord($record))
            ->values();

        return Inertia::render('Counseling/Appeals', [
            'appeals' => $appeals->map(fn (CounselingRecord $record) => [
                ...CounselingRecordView::of($record, $now),
                'trooper' => CounselingRecordView::trooper($record->trooper),
                'canDecide' => $for($record)->decidesAppeal($record, $now),
            ])->all(),
            'pm' => [
                'allowed' => $this->pmGrant($request) !== null,
                'consentUrl' => route('auth.forum-pm', ['return' => route('counseling.appeals', absolute: false)]),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }
}
