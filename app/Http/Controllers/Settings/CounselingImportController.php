<?php

namespace App\Http\Controllers\Settings;

use App\Authorization\ScopeResolver;
use App\Commands\Counseling\CounselingRules;
use App\Commands\Counseling\ImportCounselingBatch;
use App\Commands\Counseling\ImportCounselingCheck;
use App\Commands\Counseling\ImportCounselingCheckHandler;
use App\Commands\Counseling\ImportCsv;
use App\Commands\Counseling\ImportRefusedException;
use App\Commands\Counseling\ImportStaging;
use App\Http\Controllers\Controller;
use App\Jobs\ImportCounselingRecordsJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The counseling import (#565): older DCSs and LORs brought in from their Drive links or PDFs. An Admin downloads the
 * CSV template, uploads the filled CSV with the PDFs it names and the fields their browser read from each PDF, sees
 * the dry run row by row (import, needs review, skipped with the reason), picks the trooper where a Last.F matched
 * several, and imports. The import is queued ({@see ImportCounselingRecordsJob}); the console command
 * `counseling:import` runs the same handler.
 *
 * Orchestration only: the check and the import are Commands ({@see ImportCounselingCheckHandler},
 * `ImportCounselingBatchHandler`), which check the Admin rule again. The whole screen is Admin-only, like the Servers
 * screen: viewing follows a view-as preview (an Admin previewing a leader gets the 403 that leader would); every write
 * checks the real Admin rule, `ScopeResolver::isAdmin()` (AD-6), before anything else. A batch is its checker's: another
 * Admin's batch id answers 404, as one that is gone does.
 */
class CounselingImportController extends Controller
{
    /** The most PDFs one check takes, matching the CSV's row cap. */
    public const MAX_FILES = ImportCsv::MAX_ROWS;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly ImportStaging $staging,
    ) {}

    /** The screen, with the result of the check `?batch=` names, when it is the viewer's and still waiting. */
    public function show(Request $request): Response
    {
        if (! $this->scope->actsAsAdmin($request->user())) {
            abort(403, 'Only an Admin may import counseling records.');
        }

        $batch = $request->query('batch');
        $plan = is_string($batch) && $this->ownsBatch($request, $batch) ? $this->staging->plan($batch) : null;

        return Inertia::render('Settings/CounselingImport', [
            'columns' => ImportCsv::COLUMNS,
            'limits' => [
                'rows' => ImportCsv::MAX_ROWS,
                'files' => self::MAX_FILES,
                'fileBytes' => CounselingRules::DOCUMENT_MAX_BYTES,
            ],
            'check' => $plan,
            'status' => $request->session()->get('status'),
        ]);
    }

    /** The CSV template: the header line alone. */
    public function template(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAdmin($request);

        return response(ImportCsv::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="counseling-import-template.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Keep the CSV and the PDFs as a batch and run the dry run; back to the screen with its result. `fields` is a JSON
     * object of what the browser read from each PDF, by file name (null for a PDF with no form fields).
     */
    public function check(Request $request, ImportCounselingCheckHandler $handler): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'csv' => ['required', 'file', 'max:1024'],
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'max:'.intdiv(CounselingRules::DOCUMENT_MAX_BYTES, 1024)],
            'fields' => ['nullable', 'string', 'max:2000000', 'json'],
        ], [
            'csv.required' => 'Choose the CSV file.',
            'csv.max' => 'The CSV must be 1 MB or smaller.',
            'files.max' => 'Upload at most :max PDFs at a time.',
            'files.*.max' => 'Each PDF must be 10 MB or smaller.',
            'fields.json' => 'The PDFs could not be read. Reload the page and try again.',
        ]);

        $uploads = [];
        foreach ($data['files'] ?? [] as $file) {
            /** @var UploadedFile $file */
            $uploads[$file->getClientOriginalName()] = $file->getRealPath();
        }

        $fields = json_decode($data['fields'] ?? '{}', true);

        try {
            $checked = $handler->handle($request->user(), new ImportCounselingCheck(
                csvName: $data['csv']->getClientOriginalName(),
                csv: (string) file_get_contents($data['csv']->getRealPath()),
                uploads: $uploads,
                fields: is_array($fields) ? $fields : [],
            ));
        } catch (ImportRefusedException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('settings.counseling-import', ['batch' => $checked['batch']]);
    }

    /**
     * Queue the import of a checked batch. `picks` names the trooper picked for each row whose Last.F matched several,
     * as CSV line => trooper id; the import takes a pick only if it is one of that row's matches.
     */
    public function import(Request $request, string $batch): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->ownsBatch($request, $batch) && $this->staging->plan($batch) !== null, 404);

        $data = $request->validate([
            'picks' => ['nullable', 'array', 'max:'.ImportCsv::MAX_ROWS],
            'picks.*' => ['integer', 'min:1'],
        ]);

        $picks = [];
        foreach ($data['picks'] ?? [] as $line => $trooper) {
            if (ctype_digit((string) $line)) {
                $picks[(int) $line] = (int) $trooper;
            }
        }

        ImportCounselingRecordsJob::dispatch(new ImportCounselingBatch($batch, $request->user()->id, $picks));

        return redirect()->route('settings.counseling-import')
            ->with('status', 'Import started. The records appear on each trooper\'s milpac as they are stored; the audit log lists each one.');
    }

    private function ownsBatch(Request $request, string $batch): bool
    {
        $manifest = $this->staging->manifest($batch);

        return $manifest !== null && (int) $manifest['actor_id'] === $request->user()->id;
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! $this->scope->isAdmin($request->user())) {
            abort(403, 'Only an Admin may import counseling records.');
        }
    }
}
