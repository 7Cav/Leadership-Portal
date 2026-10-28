<?php

namespace App\Http\Controllers;

use App\Authorization\CounselingAccess;
use App\Authorization\ScopeResolver;
use App\Commands\Counseling\CorrectCounselingRecord;
use App\Commands\Counseling\CorrectCounselingRecordHandler;
use App\Commands\Counseling\CounselingRecordNotFoundException;
use App\Commands\Counseling\CounselingRules;
use App\Commands\Counseling\DeleteCounselingRecord;
use App\Commands\Counseling\DeleteCounselingRecordHandler;
use App\Commands\Counseling\FileCounselingRecord;
use App\Commands\Counseling\FileCounselingRecordHandler;
use App\Commands\Counseling\InvalidCounselingRecordException;
use App\Commands\Counseling\UploadCounselingDocument;
use App\Jobs\UploadCounselingDocumentJob;
use App\Models\CounselingType;
use App\Models\DriveUploadStatus;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\TrooperRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Developmental Counseling Statements and Letters of Reprimand on a trooper (#381): file one with its signed PDF,
 * correct its date or reason, delete it, and download its document. The trooper page lists them
 * (`TrooperDetailController`, `Milpac\CounselingHistory`).
 *
 * Orchestration only. Every write is an audited Command whose handler decides, through `CounselingAccess` (AD-8), who
 * may file which type and who may change a record; a refusal there is a 403. A trooper or record the viewer cannot see
 * answers 404, exactly like one that does not exist, on every route here, the download included. The form's own checks
 * (a known type, a date, a reason, a PDF of at most 10 MB by its content) run first; the handler checks them again.
 *
 * Once a record is saved with Google Drive configured, its upload is queued (AD-23). Queuing never undoes the filing:
 * a queue that cannot take the job is reported, and the record shows its upload as pending.
 */
class CounselingController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperRepository $troopers,
        private readonly CounselingRecordRepository $records,
    ) {}

    public function store(Request $request, int $trooper, FileCounselingRecordHandler $handler): RedirectResponse
    {
        $found = $this->troopers->find($trooper);
        abort_unless($found !== null && $this->scope->canSee($request->user(), $found), 404);

        $data = $request->validate([
            'type' => ['required', Rule::enum(CounselingType::class)],
            'counseled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.CounselingRules::EARLIEST],
            'reason' => ['required', 'string', 'max:'.CounselingRules::REASON_MAX],
            // `mimetypes` sniffs the file's content (finfo), not its name or the type the browser sent.
            'document' => ['required', 'file', 'max:'.(CounselingRules::DOCUMENT_MAX_BYTES / 1024), 'mimetypes:application/pdf'],
        ], [
            'document.required' => 'Attach the signed document as a PDF.',
            'document.mimetypes' => 'Attach the signed document as a PDF.',
            'document.max' => 'The document must be 10 MB or smaller.',
            'counseled_on.before_or_equal' => 'The date cannot be in the future.',
            'reason.required' => 'Give the reason for the record.',
        ]);

        $record = $this->handle(fn () => $handler->handle($request->user(), new FileCounselingRecord(
            trooperId: $found->id,
            type: CounselingType::from($data['type']),
            counseledOn: CarbonImmutable::createFromFormat('Y-m-d', $data['counseled_on'], 'UTC'),
            reason: $data['reason'],
            documentPath: $request->file('document')->getRealPath(),
            documentName: (string) $request->file('document')->getClientOriginalName(),
        )));

        if ($record->drive_status === DriveUploadStatus::Pending) {
            rescue(fn () => UploadCounselingDocumentJob::dispatch(new UploadCounselingDocument($record->id)));
        }

        return redirect()->route('troopers.show', $found->id);
    }

    public function update(Request $request, int $counseling, CorrectCounselingRecordHandler $handler): RedirectResponse
    {
        $data = $request->validate([
            'counseled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.CounselingRules::EARLIEST],
            'reason' => ['required', 'string', 'max:'.CounselingRules::REASON_MAX],
            'audit_reason' => ['required', 'string', 'max:500'],
        ], ['counseled_on.before_or_equal' => 'The date cannot be in the future.', 'audit_reason.required' => 'Say why the record is corrected.']);

        $record = $this->handle(fn () => $handler->handle($request->user(), new CorrectCounselingRecord(
            recordId: $counseling,
            counseledOn: CarbonImmutable::createFromFormat('Y-m-d', $data['counseled_on'], 'UTC'),
            reason: $data['reason'],
            auditReason: $data['audit_reason'],
        )));

        return redirect()->route('troopers.show', $record->trooper_id);
    }

    public function destroy(Request $request, int $counseling, DeleteCounselingRecordHandler $handler): RedirectResponse
    {
        $data = $request->validate(['audit_reason' => ['required', 'string', 'max:500']], ['audit_reason.required' => 'Say why the record is deleted.']);

        $record = $this->handle(fn () => $handler->handle($request->user(), new DeleteCounselingRecord($counseling, $data['audit_reason'])));

        return redirect()->route('troopers.show', $record->trooper_id);
    }

    /**
     * The record's document, only for someone who may see the record: anyone else gets the same 404 as for a record
     * that does not exist. Served as an attachment named after the record, never after the uploaded file.
     */
    public function document(Request $request, int $counseling, CounselingAccess $access, CounselingDocuments $documents): StreamedResponse
    {
        $record = $this->records->find($counseling);
        abort_unless($record !== null && $access->for($request->user(), $record->trooper)->sees($record->type), 404);
        abort_unless($documents->exists($record->document_path), 404);

        return $documents->download($record->document_path, $record->fileName($record->trooper));
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
