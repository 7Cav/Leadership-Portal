<?php

namespace App\Http\Controllers;

use App\Authorization\CounselingAccess;
use App\Authorization\CounselingPermissions;
use App\Authorization\ScopeResolver;
use App\Commands\Counseling\CounselingDocument;
use App\Commands\Counseling\CounselingForbiddenException;
use App\Commands\Counseling\CounselingForm;
use App\Commands\Counseling\CounselingFormParties;
use App\Commands\Counseling\CounselingMessenger;
use App\Commands\Counseling\CounselingNotReadyException;
use App\Commands\Counseling\CounselingPmException;
use App\Commands\Counseling\CounselingRecordNotFoundException;
use App\Commands\Counseling\CounselingResponse;
use App\Commands\Counseling\CounselingRules;
use App\Commands\Counseling\DecideCounselingAppeal;
use App\Commands\Counseling\DecideCounselingAppealHandler;
use App\Commands\Counseling\DeleteCounselingDraft;
use App\Commands\Counseling\DeleteCounselingDraftHandler;
use App\Commands\Counseling\InvalidCounselingRecordException;
use App\Commands\Counseling\OverturnCounselingRecord;
use App\Commands\Counseling\OverturnCounselingRecordHandler;
use App\Commands\Counseling\RespondToCounselingRecord;
use App\Commands\Counseling\RespondToCounselingRecordHandler;
use App\Commands\Counseling\SaveCounselingDraft;
use App\Commands\Counseling\SaveCounselingDraftHandler;
use App\Commands\Counseling\SendCounselingInvite;
use App\Commands\Counseling\SendCounselingInviteHandler;
use App\Commands\Counseling\ServeCounselingDocument;
use App\Commands\Counseling\ServeCounselingDocumentHandler;
use App\Commands\Counseling\ShareCounselingRecord;
use App\Commands\Counseling\ShareCounselingRecordHandler;
use App\Commands\Counseling\StartCounselingRecord;
use App\Commands\Counseling\StartCounselingRecordHandler;
use App\Commands\Counseling\WithdrawCounselingRecord;
use App\Commands\Counseling\WithdrawCounselingRecordHandler;
use App\Http\Controllers\Concerns\ReadsForumPmGrant;
use App\Milpac\CounselingRecordView;
use App\Models\AppealOutcome;
use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\TrooperRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Developmental Counseling Statements and Letters of Reprimand written in the portal (#381, #565): start a draft on a
 * trooper, open a record, save the draft from any step, delete it, send the meeting invite, share it, withdraw it, the
 * trooper's response, the appeal decision, the RXO's overturn, and the document. The trooper page lists the records
 * (`TrooperDetailController`, `Milpac\CounselingHistory`); the appeals queue is `CounselingAppealsController`.
 *
 * Orchestration only. Every write is an audited Command whose handler decides, through `CounselingAccess` (AD-8), who
 * may take the step; a refusal there is a 403. A trooper or record the viewer cannot see answers 404, exactly like one
 * that does not exist, on every route here, the document included. A step that sends a PM hands the handler the user's
 * PM grant from the session ({@see ReadsForumPmGrant}); a step refused for want of one comes back as a `pm_consent`
 * error, which the page turns into "Allow PMs", and a PM the forum did not take comes back as a `pm` error, the record
 * unchanged.
 */
class CounselingController extends Controller
{
    use ReadsForumPmGrant;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly CounselingRecordRepository $records,
    ) {}

    /** Start a draft and open it. */
    public function store(Request $request, int $trooper, StartCounselingRecordHandler $handler): RedirectResponse
    {
        $found = $this->troopers->find($trooper);
        abort_unless($found !== null && $this->scope->canSee($request->user(), $found), 404);
        $this->refuseDeparted($found);

        $data = $request->validate([
            'type' => ['required', Rule::enum(CounselingType::class)],
            'kind' => ['required', Rule::enum(CounselingKind::class)],
        ], [
            'kind.required' => 'Choose what the record is for.',
            'kind.enum' => 'Choose what the record is for.',
        ]);

        $record = $this->handle($request, fn () => $handler->handle($request->user(), new StartCounselingRecord(
            trooperId: $found->id,
            type: CounselingType::from($data['type']),
            kind: CounselingKind::from($data['kind']),
        )));

        return redirect()->route('counseling.show', $record->id);
    }

    /**
     * One record, for whoever sees it: the leader's case file while it is a draft, the record and the trooper's response
     * once shared, the appeal and its decision. What the viewer may do on it is decided here once, through
     * `CounselingAccess`; nothing is offered during a view-as preview, since every write is refused then (AD-9).
     */
    public function show(Request $request, int $counseling, CounselingAccess $access, CounselingFormParties $parties, CounselingMessenger $messenger): Response
    {
        $record = $this->records->find($counseling);
        abort_unless($record !== null, 404);
        $permissions = $access->for($request->user(), $record->trooper);
        abort_unless($permissions->seesRecord($record), 404);

        $writable = $this->scope->activeViewAs($request->user()) === null;
        $section1 = $parties->forRecord($record);
        // Who would hear an appeal, named on the trooper's choice.
        $authority = $messenger->commanderOf($record->appeal_unit_id === null ? null : (int) $record->appeal_unit_id);

        return Inertia::render('Counseling/Record', [
            'record' => CounselingRecordView::of($record),
            'trooper' => CounselingRecordView::trooper($record->trooper),
            'section1' => $section1,
            // The form's fields for the preview; a record kept with its source PDF shows that file instead.
            'document' => $record->hasSourceDocument() ? null : CounselingDocument::for($record, $section1),
            'missing' => $record->isDraft() ? SaveCounselingDraftHandler::missing($record) : [],
            'appealAuthority' => $authority === null ? null : trim(($authority->rank_short ?? '').' '.$authority->username),
            'can' => $this->can($permissions, $record, $writable),
            'pm' => [
                'allowed' => $this->pmGrant($request) !== null,
                'consentUrl' => route('auth.forum-pm', ['return' => route('counseling.show', $record->id, absolute: false)]),
            ],
            'limits' => [
                'long' => CounselingForm::LONG_MAX,
                'plan' => CounselingForm::PLAN_MAX,
                'short' => CounselingForm::SHORT_MAX,
                'comment' => CounselingForm::COMMENT_MAX,
                'reason' => CounselingRules::REASON_MAX,
                'window' => SendCounselingInviteHandler::WINDOW_MAX,
            ],
            'kinds' => collect(CounselingKind::cases())->map(fn (CounselingKind $kind) => ['value' => $kind->value, 'label' => $kind->label()])->all(),
            'status' => $request->session()->get('status'),
        ]);
    }

    /** Save the draft as it stands, from any step. */
    public function update(Request $request, int $counseling, SaveCounselingDraftHandler $handler): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(CounselingType::class)],
            'kind' => ['required', Rule::enum(CounselingKind::class)],
            'counseled_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.CounselingRules::EARLIEST],
            'meeting_confirmed' => ['boolean'],
            ...CounselingForm::rules(),
        ], [
            'counseled_on.before_or_equal' => 'The date cannot be in the future.',
            'counseled_on.after_or_equal' => 'The date is too far back.',
            ...CounselingForm::messages(),
        ]);

        $type = CounselingType::from($data['type']);

        $this->handle($request, fn () => $handler->handle($request->user(), new SaveCounselingDraft(
            recordId: $counseling,
            type: $type,
            kind: CounselingKind::from($data['kind']),
            counseledOn: filled($data['counseled_on'] ?? null) ? CarbonImmutable::createFromFormat('Y-m-d', $data['counseled_on'], 'UTC') : null,
            form: CounselingForm::fromInput($data['form'] ?? [], $type),
            meetingConfirmed: (bool) ($data['meeting_confirmed'] ?? false),
        )));

        return back()->with('status', 'Draft saved.');
    }

    /** Delete a draft. */
    public function destroy(Request $request, int $counseling, DeleteCounselingDraftHandler $handler): RedirectResponse
    {
        $record = $this->handle($request, fn () => $handler->handle($request->user(), new DeleteCounselingDraft($counseling)));

        return redirect()->route('troopers.show', $record->trooper_id);
    }

    public function invite(Request $request, int $counseling, SendCounselingInviteHandler $handler): RedirectResponse
    {
        $data = $request->validate([
            'windows' => ['required', 'array', 'size:3'],
            'windows.*' => ['required', 'string', 'max:'.SendCounselingInviteHandler::WINDOW_MAX],
        ], [
            'windows.*' => 'Give three times you can meet, in Zulu.',
            'windows.*.required' => 'Give three times you can meet, in Zulu.',
            'windows.*.max' => 'Keep each time to :max characters.',
        ]);

        $this->handle($request, fn () => $handler->handle($request->user(), new SendCounselingInvite($counseling, array_values($data['windows']), $this->pmGrant($request))));

        return back()->with('status', 'Meeting invite sent.');
    }

    public function share(Request $request, int $counseling, ShareCounselingRecordHandler $handler): RedirectResponse
    {
        $shared = $this->handle($request, fn () => $handler->handle($request->user(), new ShareCounselingRecord($counseling, $this->pmGrant($request))));

        // Shared either way; a copy that could not go to S1 is the leader's to send, and they are told so.
        return back()->with('status', $shared->followUp ?? 'Shared with the trooper.');
    }

    public function withdraw(Request $request, int $counseling, WithdrawCounselingRecordHandler $handler): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:'.CounselingRules::REASON_MAX]], [
            'reason.required' => 'Say why the record is withdrawn.',
            'reason.max' => 'Keep the reason to :max characters.',
        ]);

        $this->handle($request, fn () => $handler->handle($request->user(), new WithdrawCounselingRecord($counseling, $data['reason'], $this->pmGrant($request))));

        return back()->with('status', 'Withdrawn back to draft.');
    }

    public function respond(Request $request, int $counseling, RespondToCounselingRecordHandler $handler): RedirectResponse
    {
        $type = $this->records->find($counseling)?->type;
        $data = $request->validate(CounselingResponse::rules($type), CounselingResponse::messages());

        $this->handle($request, fn () => $handler->handle($request->user(), new RespondToCounselingRecord(
            $counseling,
            CounselingResponse::fromInput($data, $type ?? CounselingType::Dcs),
            $this->pmGrant($request),
        )));

        return back()->with('status', 'Response submitted.');
    }

    public function decide(Request $request, int $counseling, DecideCounselingAppealHandler $handler): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::enum(AppealOutcome::class)],
            'reason' => ['required', 'string', 'max:'.DecideCounselingAppealHandler::REASON_MAX],
            ...CounselingForm::rules(),
        ], [
            'outcome.required' => 'Choose your decision.',
            'reason.required' => 'Give the reason for your decision.',
            'reason.max' => 'Keep the reason to :max characters.',
            ...CounselingForm::messages(),
        ]);

        $outcome = AppealOutcome::from($data['outcome']);

        $this->handle($request, fn () => $handler->handle($request->user(), new DecideCounselingAppeal(
            recordId: $counseling,
            outcome: $outcome,
            reason: $data['reason'],
            amended: $outcome === AppealOutcome::Amended ? CounselingForm::fromInput($data['form'] ?? [], CounselingType::Lor) : null,
            pm: $this->pmGrant($request),
        )));

        return back()->with('status', 'Decision recorded.');
    }

    /**
     * Overturn a frozen LOR, with the reason shown on it. Who may, and when, is the handler's to decide.
     */
    public function overturn(Request $request, int $counseling, OverturnCounselingRecordHandler $handler): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:'.OverturnCounselingRecordHandler::REASON_MAX]], [
            'reason.required' => 'Say why the LOR is overturned.',
            'reason.max' => 'Keep the reason for overturning to :max characters.',
        ]);

        $this->handle($request, fn () => $handler->handle($request->user(), new OverturnCounselingRecord($counseling, $data['reason'])));

        return back();
    }

    /**
     * The record's document, only for someone who sees the record, audited every time. A record kept with its source
     * PDF downloads that file, always as an attachment. Every other record answers the official form's fields as JSON,
     * which the page fills into the regiment's template in the browser and saves; nothing is generated or kept here.
     */
    public function document(Request $request, int $counseling, ServeCounselingDocumentHandler $handler, CounselingDocuments $documents): StreamedResponse|JsonResponse
    {
        $served = $this->handle($request, fn () => $handler->handle($request->user(), new ServeCounselingDocument($counseling)));

        if ($served->isSourceDocument()) {
            return $documents->download((string) $served->path, $served->record->fileName($served->record->trooper));
        }

        return response()->json($served->form, 200, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * What the viewer may do on this record, for the page. Nothing during a view-as preview.
     *
     * @return array<string, bool>
     */
    private function can(CounselingPermissions $permissions, CounselingRecord $record, bool $writable): array
    {
        $draft = $writable && $permissions->editsDraft($record);

        return [
            'edit' => $draft,
            'delete' => $draft,
            // Sending the invite and sharing issue the record, so they need the right to issue its type.
            'invite' => $draft && $record->invited_at === null && $permissions->files($record->type),
            'share' => $draft && $permissions->files($record->type),
            'withdraw' => $writable && $permissions->withdraws($record),
            'respond' => $writable && $permissions->responds($record),
            'decide' => $writable && $permissions->decidesAppeal($record),
            'overturn' => $writable && $permissions->overturns($record),
            // Fill in the fields of a record kept with its source PDF (`counseling.review`).
            'review' => $writable && $permissions->reviews($record),
            // Changing the type to an LOR needs the right to file one.
            'fileLor' => $writable && $permissions->filesLor,
        ];
    }

    /**
     * A trooper who has left the Cav has read-only counseling (2026-10-28): refused before the form is even read. The
     * handlers refuse it again.
     *
     * @throws CounselingForbiddenException
     */
    private function refuseDeparted(Trooper $trooper): void
    {
        if (! ($trooper->status?->isServing() ?? false)) {
            throw CounselingForbiddenException::departed($trooper);
        }
    }

    /**
     * Run a counseling Command, turning its refusals into answers: not found is a 404, a bad field a validation error on
     * that field, a draft not ready to share a validation error per missing field, and a PM that could not go a `pm` or
     * `pm_consent` error (forgetting a grant the forum no longer takes). A 403 (`CounselingForbiddenException`)
     * propagates as one.
     *
     * @template T
     *
     * @param  Closure(): T  $command
     * @return T
     */
    private function handle(Request $request, Closure $command): mixed
    {
        try {
            return $command();
        } catch (CounselingRecordNotFoundException) {
            abort(404);
        } catch (InvalidCounselingRecordException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        } catch (CounselingNotReadyException $e) {
            throw ValidationException::withMessages($e->missing);
        } catch (CounselingPmException $e) {
            if ($e->needsConsent) {
                $this->forgetPmGrant($request);
            } elseif ($e->getPrevious() !== null) {
                // The forum's own answer is kept in the report, never shown: it may help diagnose a refused PM.
                report($e);
            }

            throw ValidationException::withMessages([$e->needsConsent ? 'pm_consent' : 'pm' => $e->getMessage()]);
        }
    }
}
