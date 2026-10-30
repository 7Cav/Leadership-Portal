<?php

namespace App\Http\Controllers;

use App\Authorization\CounselingAccess;
use App\Authorization\CounselingPermissions;
use App\Authorization\ScopeResolver;
use App\Commands\Counseling\CorrectCounselingRecord;
use App\Commands\Counseling\CorrectCounselingRecordHandler;
use App\Commands\Counseling\CounselingForbiddenException;
use App\Commands\Counseling\CounselingForm;
use App\Commands\Counseling\CounselingFormParties;
use App\Commands\Counseling\CounselingFormPdf;
use App\Commands\Counseling\CounselingRecordNotFoundException;
use App\Commands\Counseling\CounselingRules;
use App\Commands\Counseling\DeleteCounselingRecord;
use App\Commands\Counseling\DeleteCounselingRecordHandler;
use App\Commands\Counseling\FileCounselingRecord;
use App\Commands\Counseling\FileCounselingRecordHandler;
use App\Commands\Counseling\InvalidCounselingRecordException;
use App\Commands\Counseling\OverturnCounselingRecord;
use App\Commands\Counseling\OverturnCounselingRecordHandler;
use App\Commands\Counseling\UploadCounselingDocument;
use App\Jobs\UploadCounselingDocumentJob;
use App\Models\CounselingKind;
use App\Models\CounselingType;
use App\Models\DriveUploadStatus;
use App\Models\Trooper;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\TrooperRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Developmental Counseling Statements and Letters of Reprimand on a trooper (#381): file one with its signed PDF,
 * correct its date or reason, delete it, overturn a frozen LOR (S6, 2026-10-29), and download its document. The trooper page lists them
 * (`TrooperDetailController`, `Milpac\CounselingHistory`).
 *
 * Orchestration only. Every write is an audited Command whose handler decides, through `CounselingAccess` (AD-8), who
 * may file which type and who may change a record; a refusal there is a 403. A trooper or record the viewer cannot see
 * answers 404, exactly like one that does not exist, on every route here, the download included. The form's own checks
 * (a known type, a date, a reason, a PDF of at most 10 MB by its content) run first; the handler checks them again.
 *
 * A trooper who has left the Cav has read-only counseling: filing, correcting and deleting are refused here (403)
 * before the form is read, and again in each handler; the download still works for whoever sees the record. So are
 * correcting and deleting an LOR filed 72 hours ago or more, Admins included: only the Regimental Executive Officer may
 * then overturn it, which keeps it on file.
 *
 * Instead of uploading, the leader may fill in the regiment's form in the portal (`source=form`): the portal renders
 * the PDF ({@see CounselingFormPdf}) and files it through the same handler, so it takes the upload's path from there.
 * `preview` shows that document before filing.
 *
 * Once a record is saved with Google Drive configured, its upload is queued (AD-23). Queuing never undoes the filing:
 * a queue that cannot take the job is reported, and the record shows its upload as pending.
 */
class CounselingController extends Controller
{
    /** How the document comes: an uploaded signed PDF, or made by the portal from its form. */
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_FORM = 'form';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly CounselingRecordRepository $records,
    ) {}

    public function store(Request $request, int $trooper, FileCounselingRecordHandler $handler, Dispatcher $bus): RedirectResponse
    {
        $found = $this->troopers->find($trooper);
        abort_unless($found !== null && $this->scope->canSee($request->user(), $found), 404);
        $this->refuseDeparted($found);

        $generated = $request->input('source') === self::SOURCE_FORM;
        $type = CounselingType::tryFrom((string) $request->input('type'));

        $data = $request->validate([
            'source' => ['nullable', Rule::in([self::SOURCE_UPLOAD, self::SOURCE_FORM])],
            'type' => ['required', Rule::enum(CounselingType::class)],
            'kind' => ['required', Rule::enum(CounselingKind::class)],
            'counseled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.CounselingRules::EARLIEST],
            'reason' => ['required', 'string', 'max:'.CounselingRules::REASON_MAX],
            // `mimetypes` sniffs the file's content (finfo), not its name or the type the browser sent.
            ...($generated
                ? CounselingForm::rules($type)
                : ['document' => ['required', 'file', 'max:'.(CounselingRules::DOCUMENT_MAX_BYTES / 1024), 'mimetypes:application/pdf']]),
        ], [
            'kind.required' => 'Choose what the record is for.',
            'kind.enum' => 'Choose what the record is for.',
            'document.required' => 'Attach the signed document as a PDF.',
            'document.mimetypes' => 'Attach the signed document as a PDF.',
            'document.max' => 'The document must be 10 MB or smaller.',
            'counseled_on.before_or_equal' => 'The date cannot be in the future.',
            'reason.required' => 'Give the reason for the record.',
            'reason.max' => 'Keep the reason to :max characters.',
            'counseled_on.after_or_equal' => 'The date is too far back.',
            ...CounselingForm::messages(),
        ]);

        $record = $this->handle(fn () => $handler->handle($request->user(), new FileCounselingRecord(
            trooperId: $found->id,
            type: CounselingType::from($data['type']),
            kind: CounselingKind::from($data['kind']),
            counseledOn: CarbonImmutable::createFromFormat('Y-m-d', $data['counseled_on'], 'UTC'),
            reason: $data['reason'],
            documentPath: $generated ? '' : $request->file('document')->getRealPath(),
            documentName: $generated ? '' : (string) $request->file('document')->getClientOriginalName(),
            form: $generated ? CounselingForm::fromInput($data['form'], CounselingType::from($data['type'])) : null,
        )));

        if ($record->drive_status === DriveUploadStatus::Pending) {
            // Sent here, inside `rescue`: `Job::dispatch()` returns a PendingDispatch that only sends when it is destroyed,
            // after `rescue` has returned, so a queue that throws would have failed the request after all.
            rescue(fn () => $bus->dispatch(new UploadCounselingDocumentJob(new UploadCounselingDocument($record->id))));
        }

        return redirect()->route('troopers.show', $found->id);
    }

    /**
     * The document the portal would generate from the form, as the HTML page the PDF is rendered from, for the preview
     * before filing. Asked of the same checks as filing: a trooper the viewer cannot see is a 404, a departed one or a
     * type the viewer may not file is a 403, and a view-as preview is refused like every write. Nothing is stored.
     */
    public function preview(Request $request, int $trooper, CounselingAccess $access, CounselingFormParties $parties, CounselingFormPdf $pdf): JsonResponse
    {
        $found = $this->troopers->find($trooper);
        abort_unless($found !== null && $this->scope->canSee($request->user(), $found), 404);
        $this->refuseDeparted($found);
        abort_if($this->scope->activeViewAs($request->user()) !== null, 403);

        $type = CounselingType::tryFrom((string) $request->input('type'));
        $data = $request->validate([
            'type' => ['required', Rule::enum(CounselingType::class)],
            'counseled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.CounselingRules::EARLIEST],
            ...CounselingForm::rules($type),
        ], [
            'counseled_on.before_or_equal' => 'The date cannot be in the future.',
            'counseled_on.after_or_equal' => 'The date is too far back.',
            ...CounselingForm::messages(),
        ]);

        $type = CounselingType::from($data['type']);
        if (! $access->for($request->user(), $found)->files($type)) {
            throw CounselingForbiddenException::file($type);
        }

        $form = CounselingForm::fromInput($data['form'], $type);
        $this->handle(fn () => $form->check($type));

        return response()->json([
            'html' => $pdf->html($type, CarbonImmutable::createFromFormat('Y-m-d', $data['counseled_on'], 'UTC'), $parties->for($found, $request->user()), $form),
        ]);
    }

    public function update(Request $request, int $counseling, CorrectCounselingRecordHandler $handler, CounselingAccess $access): RedirectResponse
    {
        $this->refuseReadOnlyRecord($request, $counseling, $access);

        $data = $request->validate([
            'counseled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.CounselingRules::EARLIEST],
            'reason' => ['required', 'string', 'max:'.CounselingRules::REASON_MAX],
            'audit_reason' => ['required', 'string', 'max:500'],
        ], [
            'counseled_on.before_or_equal' => 'The date cannot be in the future.',
            'counseled_on.after_or_equal' => 'The date is too far back.',
            'reason.required' => 'Give the reason for the record.',
            'reason.max' => 'Keep the reason to :max characters.',
            'audit_reason.required' => 'Say why the record is corrected.',
            'audit_reason.max' => 'Keep the reason for the correction to :max characters.',
        ]);

        $record = $this->handle(fn () => $handler->handle($request->user(), new CorrectCounselingRecord(
            recordId: $counseling,
            counseledOn: CarbonImmutable::createFromFormat('Y-m-d', $data['counseled_on'], 'UTC'),
            reason: $data['reason'],
            auditReason: $data['audit_reason'],
        )));

        return redirect()->route('troopers.show', $record->trooper_id);
    }

    public function destroy(Request $request, int $counseling, DeleteCounselingRecordHandler $handler, CounselingAccess $access): RedirectResponse
    {
        $this->refuseReadOnlyRecord($request, $counseling, $access);

        $data = $request->validate(['audit_reason' => ['required', 'string', 'max:500']], [
            'audit_reason.required' => 'Say why the record is deleted.',
            'audit_reason.max' => 'Keep the reason for deleting to :max characters.',
        ]);

        $record = $this->handle(fn () => $handler->handle($request->user(), new DeleteCounselingRecord($counseling, $data['audit_reason'])));

        return redirect()->route('troopers.show', $record->trooper_id);
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

        $record = $this->handle(fn () => $handler->handle($request->user(), new OverturnCounselingRecord($counseling, $data['reason'])));

        return redirect()->route('troopers.show', $record->trooper_id);
    }

    /**
     * The record's document, only for someone who may see the record: anyone else gets the same 404 as for a record
     * that does not exist. Served as an attachment named after the record, never after the uploaded file.
     */
    public function document(Request $request, int $counseling, CounselingAccess $access, CounselingDocuments $documents): StreamedResponse
    {
        $record = $this->records->find($counseling);
        abort_unless($record !== null && $access->for($request->user(), $record->trooper)->seesRecord($record), 404);
        abort_unless($documents->exists($record->document_path), 404);

        return $documents->download($record->document_path, $record->fileName($record->trooper));
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
     * The same for a correction or a delete, once the record is known to be one the viewer sees, and for an LOR past its
     * appeal window or overturned: a record they do not see, or that does not exist, answers 404 as before, so the
     * refusal gives nothing away.
     *
     * @throws CounselingForbiddenException
     */
    private function refuseReadOnlyRecord(Request $request, int $counseling, CounselingAccess $access): void
    {
        $record = $this->records->find($counseling);
        abort_unless($record !== null && $access->for($request->user(), $record->trooper)->seesRecord($record), 404);

        $this->refuseDeparted($record->trooper);

        if ($record->isOverturned()) {
            throw CounselingForbiddenException::overturned();
        }

        if (CounselingPermissions::frozen($record)) {
            throw CounselingForbiddenException::frozen();
        }
    }

    /**
     * Run a counseling Command, turning its refusals into answers: not found is a 404, a bad field a validation error
     * on that field. A 403 (`CounselingForbiddenException`) propagates as one.
     *
     * @template T
     *
     * @param  Closure(): T  $command
     * @return T
     */
    private function handle(Closure $command): mixed
    {
        try {
            return $command();
        } catch (CounselingRecordNotFoundException) {
            abort(404);
        } catch (InvalidCounselingRecordException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }
    }
}
