<?php

use App\Commands\Counseling\ImportStaging;
use App\Models\AuditLogEntry;
use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingSource;
use App\Models\CounselingStatus;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\CounselingRecordRepository\Eloquent as EloquentCounselingRecords;
use App\Repositories\StoredDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/fixture.php';

// #565: the counseling import. An Admin uploads the template CSV with the PDFs it names (and what their browser read
// from each), sees the dry run row by row, and imports; the console runs the same handler. Drive is faked throughout.

const IMPORT_DRIVE_ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz012345';

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->admin = $this->tree['users']['admin'];
    // The factory's rank is random; the plan names the trooper with theirs.
    $this->tree['rifleman']->forceFill(['rank_short' => 'SGT'])->save();
});

function importCsv(array $rows): UploadedFile
{
    $lines = ['drive_url,file,type,trooper,kind,issuer,counseled_on'];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn (string $cell) => str_contains($cell, ',') ? "\"{$cell}\"" : $cell, $row));
    }

    return UploadedFile::fake()->createWithContent('bravo-2025.csv', implode("\n", $lines)."\n");
}

function importFields(array $override = []): array
{
    return [
        'type' => 'dcs',
        'date' => '20OCT2025',
        'trooper' => 'SGT Brandt.L',
        'issuer' => 'Okafor.B',
        'form' => [
            'infraction' => 'Missed three section practices.',
            'regulation' => 'Platoon SOP, attendance.',
            'discussion' => 'Talked it through.',
            'plan_authority' => 'Weekly check-in.',
            'plan_trooper' => 'I will post an LOA next time.',
        ],
        ...$override,
    ];
}

/** Post the check and answer the batch's plan. */
function checkImport(object $test, UploadedFile $csv, array $files = [], array $fields = []): array
{
    $response = $test->actingAs($test->admin)->post('/settings/counseling-import/check', [
        'csv' => $csv,
        'files' => $files,
        'fields' => json_encode((object) $fields),
    ]);
    $response->assertSessionHasNoErrors()->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return app(ImportStaging::class)->plan($query['batch']);
}

function planRow(array $plan, int $line): array
{
    return collect($plan['rows'])->firstWhere('line', $line);
}

function drivePdfResponse(string $marker = 'drive-body'): array
{
    return [
        MockResponse::make('', 303, ['Location' => 'https://drive.usercontent.google.com/download?id='.IMPORT_DRIVE_ID.'&export=download']),
        MockResponse::make(counselingPdfBytes($marker), 200, ['Content-Type' => 'application/pdf']),
    ];
}

it('keeps the screen and every route to Admins', function () {
    $pl = $this->tree['users']['pl'];

    $this->actingAs($pl)->get('/settings/counseling-import')->assertForbidden();
    $this->actingAs($pl)->get('/settings/counseling-import/template')->assertForbidden();
    $this->actingAs($pl)->post('/settings/counseling-import/check', ['csv' => importCsv([])])->assertForbidden();
    $this->actingAs($pl)->post('/settings/counseling-import/'.Str::uuid())->assertForbidden();
    expect(Storage::disk('local')->allDirectories())->toBe([]);
});

it('shows the screen and hands out the template with exactly the template columns', function () {
    $this->actingAs($this->admin)->get('/settings/counseling-import')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/CounselingImport')
        ->where('columns', ['drive_url', 'file', 'type', 'trooper', 'kind', 'issuer', 'counseled_on'])
        ->where('check', null));

    $response = $this->actingAs($this->admin)->get('/settings/counseling-import/template')->assertOk();
    expect($response->getContent())->toBe("drive_url,file,type,trooper,kind,issuer,counseled_on\n")
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('refuses a CSV that is not the template before keeping anything', function () {
    $this->actingAs($this->admin)->post('/settings/counseling-import/check', [
        'csv' => UploadedFile::fake()->createWithContent('x.csv', "name,date\nBrandt.L,2025-01-01\n"),
    ])->assertSessionHasErrors('csv');

    expect(Storage::disk('local')->allDirectories())->toBe([]);
});

it('dry-runs an uploaded PDF with the fields the browser read, storing nothing', function () {
    $plan = checkImport($this, importCsv([['', 'Brandt DCS.pdf', '', '', '', '', '']]), [counselingPdf('Brandt DCS.pdf')], ['Brandt DCS.pdf' => importFields()]);

    expect(planRow($plan, 2))->toMatchArray([
        'outcome' => 'import',
        'source' => 'file',
        'label' => 'Brandt DCS.pdf',
        'type' => 'dcs',
        'trooper' => 'SGT Brandt.L',
        'issuer' => 'Okafor.B',
        'counseledOn' => '2025-10-20',
        'reason' => null,
    ])->and($plan['counts'])->toBe(['all' => 1, 'import' => 1, 'review' => 0, 'skipped' => 0])
        ->and(CounselingRecord::query()->count())->toBe(0)
        ->and(Storage::disk('counseling')->allFiles())->toBe([])
        ->and(AuditLogEntry::query()->count())->toBe(0);

    $this->actingAs($this->admin)->get('/settings/counseling-import?batch='.$plan['batch'])->assertInertia(fn (Assert $page) => $page
        ->where('check.batch', $plan['batch'])
        ->where('check.csvName', 'bravo-2025.csv')
        ->has('check.rows', 1));
});

it('says what each row is missing, and lets the CSV override the form', function () {
    Trooper::factory()->create(['username' => 'Novak.J']);
    Trooper::factory()->create(['username' => 'Novak.J']);

    $plan = checkImport($this, importCsv([
        ['', 'a.pdf', 'LOR', 'PFC Brandt.L', 'missed_practice', 'Nobody.X', '2025-03-12'],
        ['', 'scan.pdf', '', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'scan.pdf', 'dcs', 'Brandt.L', '', '', ''],
        ['', 'b.pdf', 'dcs', 'Ghost.Z', '', '', '2025-03-12'],
        ['', 'c.pdf', 'dcs', 'Novak.J', '', '', '2025-03-12'],
        ['', 'missing.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['', '', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'd.pdf', 'memo', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'd.pdf', 'dcs', 'Brandt.L', '', '', '2999-01-01'],
    ]), [counselingPdf('a.pdf', 'a'), counselingPdf('scan.pdf', 'scan'), counselingPdf('b.pdf', 'b'), counselingPdf('c.pdf', 'c'), counselingPdf('d.pdf', 'd')], [
        'a.pdf' => importFields(['type' => 'dcs', 'issuer' => 'Okafor.B']),
        'scan.pdf' => null,
    ]);

    expect(planRow($plan, 2))->toMatchArray(['outcome' => 'import', 'type' => 'lor', 'trooper' => 'SGT Brandt.L', 'issuer' => 'Hale.S', 'counseledOn' => '2025-03-12'])
        ->and(planRow($plan, 2)['notes'])->toBe(['No portal user is named Nobody.X: attributed to you.'])
        ->and(planRow($plan, 3))->toMatchArray(['outcome' => 'skipped', 'reason' => 'the type is unknown: give DCS or LOR in the type column'])
        ->and(planRow($plan, 4))->toMatchArray(['outcome' => 'skipped', 'reason' => 'no date: give it in the counseled_on column (YYYY-MM-DD)'])
        ->and(planRow($plan, 5))->toMatchArray(['outcome' => 'skipped', 'reason' => 'no trooper named Ghost.Z on the roster'])
        ->and(planRow($plan, 6))->toMatchArray(['outcome' => 'review', 'reason' => '2 troopers are named Novak.J: pick one'])
        ->and(planRow($plan, 6)['candidates'])->toHaveCount(2)
        ->and(planRow($plan, 7))->toMatchArray(['outcome' => 'skipped', 'reason' => 'missing.pdf was not uploaded'])
        ->and(planRow($plan, 8))->toMatchArray(['outcome' => 'skipped', 'reason' => 'give a drive_url or a file'])
        ->and(planRow($plan, 9))->toMatchArray(['outcome' => 'skipped', 'reason' => 'the type must be DCS or LOR'])
        ->and(planRow($plan, 10))->toMatchArray(['outcome' => 'skipped', 'reason' => 'the date cannot be in the future']);
});

it('flags a flattened PDF for review, and attributes it to the importer without an issuer', function () {
    $plan = checkImport($this, importCsv([['', 'scan-0412.pdf', 'dcs', 'Brandt.L', '', '', '2025-08-19']]), [counselingPdf('scan-0412.pdf')], ['scan-0412.pdf' => null]);

    expect(planRow($plan, 2))->toMatchArray([
        'outcome' => 'review',
        'reason' => 'No form fields could be read (a scanned or flattened PDF): fill them in after the import.',
        'issuer' => 'Hale.S',
    ]);
});

it('downloads a public Drive file in the check and keeps it for the import, which does not fetch it again', function () {
    $mock = MockClient::global(drivePdfResponse());

    $plan = checkImport($this, importCsv([['https://drive.google.com/file/d/'.IMPORT_DRIVE_ID.'/view', '', 'dcs', 'Brandt.L', 'conduct', 'Okafor.B', '2025-03-12']]));

    expect(planRow($plan, 2))->toMatchArray(['outcome' => 'review', 'source' => 'drive', 'reason' => 'The form\'s fields are not read from a Drive link: fill them in after the import.'])
        ->and($mock->getRecordedResponses())->toHaveCount(2);

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch'])->assertRedirect('/settings/counseling-import?batch='.$plan['batch']);

    $record = CounselingRecord::query()->sole();
    expect($record->import_key)->toBe(IMPORT_DRIVE_ID)
        ->and($record->needs_review)->toBeTrue()
        ->and($mock->getRecordedResponses())->toHaveCount(2);
});

it('skips folder links, private files and anything that is not a PDF', function () {
    MockClient::global([MockResponse::make('', 302, ['Location' => 'https://accounts.google.com/ServiceLogin'])]);

    $plan = checkImport($this, importCsv([
        ['https://drive.google.com/drive/folders/'.IMPORT_DRIVE_ID, '', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['https://drive.google.com/file/d/'.IMPORT_DRIVE_ID.'/view', '', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['https://example.test/file.pdf', '', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
    ]));

    expect(planRow($plan, 2))->toMatchArray(['outcome' => 'skipped', 'reason' => 'folder links are not supported'])
        ->and(planRow($plan, 3))->toMatchArray(['outcome' => 'skipped', 'reason' => 'not publicly shared / not a PDF'])
        ->and(planRow($plan, 4))->toMatchArray(['outcome' => 'skipped', 'reason' => 'not a Google Drive file link']);
});

it('imports the checked rows as shared and acknowledged on their date, audited per record, and clears the batch', function () {
    $plan = checkImport($this, importCsv([
        ['', 'Brandt DCS.pdf', '', '', '', '', ''],
        ['', 'scan.pdf', 'lor', 'Brandt.L', 'missed_practice', 'Mercer.J', '2025-06-02'],
    ]), [counselingPdf('Brandt DCS.pdf', 'one'), counselingPdf('scan.pdf', 'two')], ['Brandt DCS.pdf' => importFields(), 'scan.pdf' => null]);

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch'])
        ->assertRedirect('/settings/counseling-import?batch='.$plan['batch']);

    $records = CounselingRecord::query()->orderBy('id')->get();
    expect($records)->toHaveCount(2);

    [$dcs, $lor] = $records;
    expect($dcs->source)->toBe(CounselingSource::Import)
        ->and($dcs->status)->toBe(CounselingStatus::Shared)
        ->and($dcs->type)->toBe(CounselingType::Dcs)
        ->and($dcs->kind)->toBe(CounselingKind::Conduct)
        ->and($dcs->counseled_on->toDateString())->toBe('2025-10-20')
        ->and($dcs->shared_at->toDateString())->toBe('2025-10-20')
        ->and($dcs->responded_at)->not->toBeNull()
        ->and($dcs->trooper_id)->toBe($this->tree['rifleman']->id)
        ->and($dcs->issued_by)->toBe($this->tree['users']['sl']->id)
        ->and($dcs->needs_review)->toBeFalse()
        ->and($dcs->form_data['discussion'])->toBe('Talked it through.')
        ->and($dcs->form_data['plan_trooper'])->toBe('I will post an LOA next time.')
        ->and($dcs->import_key)->toBe(hash('sha256', counselingPdfBytes('one')))
        ->and(Storage::disk('counseling')->get($dcs->document_path))->toBe(counselingPdfBytes('one'))
        ->and($lor->type)->toBe(CounselingType::Lor)
        ->and($lor->kind)->toBe(CounselingKind::MissedPractice)
        ->and($lor->issued_by)->toBe($this->tree['users']['pl']->id)
        ->and($lor->needs_review)->toBeTrue()
        ->and($lor->form_data)->toBeNull();

    $entries = AuditLogEntry::query()->where('action', 'counseling.imported')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$this->admin->id])
        ->and($entries->first()->reason)->toBe('Counseling import, CSV line 2');

    // The outcome stays for the page; the CSV and the PDFs are gone.
    $run = app(ImportStaging::class)->status($plan['batch']);
    expect($run['state'])->toBe('finished')
        ->and($run['report']['counts'])->toBe(['all' => 2, 'import' => 1, 'review' => 1, 'skipped' => 0])
        ->and(collect($run['report']['rows'])->pluck('recordId')->all())->toBe([$dcs->id, $lor->id])
        ->and(Storage::disk('local')->allFiles('counseling-import/'.$plan['batch']))->toEqualCanonicalizing([
            'counseling-import/'.$plan['batch'].'/manifest.json',
            'counseling-import/'.$plan['batch'].'/plan.json',
            'counseling-import/'.$plan['batch'].'/status.json',
        ]);

    $this->actingAs($this->admin)->get('/settings/counseling-import?batch='.$plan['batch'])->assertInertia(fn (Assert $page) => $page
        ->where('run.state', 'finished')
        ->where('run.report.rows.0.recordId', $dcs->id)
        ->where('check.batch', $plan['batch']));
});

it('imports a batch once: a second import of the same check is refused', function () {
    $plan = checkImport($this, importCsv([['', 'a.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12']]), [counselingPdf('a.pdf')]);
    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch']);

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch'])->assertSessionHasErrors('batch');
    expect(CounselingRecord::query()->count())->toBe(1);
});

it('shows rows skipped only at import time in the import\'s own report', function () {
    Trooper::factory()->create(['username' => 'Novak.J']);
    Trooper::factory()->create(['username' => 'Novak.J']);
    $plan = checkImport($this, importCsv([
        ['', 'a.pdf', 'dcs', 'Novak.J', '', '', '2025-03-12'],
        ['', 'b.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
    ]), [counselingPdf('a.pdf', 'a'), counselingPdf('b.pdf', 'b')]);
    // Brandt.L leaves the roster between the check and the import.
    $this->tree['rifleman']->forceFill(['username' => 'Brandt.LX'])->save();

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch']);

    $run = app(ImportStaging::class)->status($plan['batch']);
    expect($run['state'])->toBe('finished')
        ->and(collect($run['report']['rows'])->firstWhere('line', 2))->toMatchArray(['outcome' => 'skipped', 'reason' => '2 troopers are named Novak.J and none was picked'])
        ->and(collect($run['report']['rows'])->firstWhere('line', 3))->toMatchArray(['outcome' => 'skipped', 'reason' => 'no trooper named Brandt.L on the roster'])
        ->and(CounselingRecord::query()->count())->toBe(0);
});

it('stops and says so when the counseling disk will not take a document, instead of skipping every row', function () {
    app()->instance(CounselingDocuments::class, new class extends CounselingDocuments
    {
        public function store(string $sourcePath, string $name): StoredDocument
        {
            throw new RuntimeException('disk full');
        }
    });
    $plan = checkImport($this, importCsv([
        ['', 'a.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'b.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
    ]), [counselingPdf('a.pdf', 'a'), counselingPdf('b.pdf', 'b')]);

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch']);

    $run = app(ImportStaging::class)->status($plan['batch']);
    expect($run['state'])->toBe('failed')
        ->and($run['reason'])->toContain('The import stopped at CSV line 2')
        ->and(collect($run['report']['rows'])->pluck('reason')->all())->toBe(['the record could not be stored: the import stopped here', 'not imported: the import stopped at line 2'])
        ->and(CounselingRecord::query()->count())->toBe(0);
});

/** A repository whose createImported fails; with `$raced`, as if another import stored the key in the meantime. */
function failingRecords(bool $raced): EloquentCounselingRecords
{
    return new class($raced) extends EloquentCounselingRecords
    {
        private bool $failed = false;

        public function __construct(private readonly bool $raced) {}

        public function createImported(int $trooperId, CounselingType $type, CounselingKind $kind, CarbonImmutable $counseledOn, int $issuedBy, ?int $issuerUnitId, StoredDocument $document, string $importKey, bool $needsReview, ?array $formData): CounselingRecord
        {
            $this->failed = true;

            throw new RuntimeException('database refused the row');
        }

        public function importedKeys(array $keys): array
        {
            return $this->raced && $this->failed ? $keys : parent::importedKeys($keys);
        }
    };
}

it('skips a row as already imported when another import stored its document meanwhile, leaving no file', function () {
    $plan = checkImport($this, importCsv([['', 'a.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12']]), [counselingPdf('a.pdf')]);
    app()->instance(CounselingRecordRepository::class, failingRecords(raced: true));

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch']);

    $run = app(ImportStaging::class)->status($plan['batch']);
    expect($run['state'])->toBe('finished')
        ->and($run['report']['rows'][0])->toMatchArray(['outcome' => 'skipped', 'reason' => 'already imported'])
        ->and(Storage::disk('counseling')->allFiles())->toBe([]);
});

it('stops the run, keeping no file, when the database refuses a record for another reason', function () {
    $plan = checkImport($this, importCsv([['', 'a.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12']]), [counselingPdf('a.pdf')]);
    app()->instance(CounselingRecordRepository::class, failingRecords(raced: false));

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch']);

    expect(app(ImportStaging::class)->status($plan['batch']))->toMatchArray(['state' => 'failed'])
        ->and(Storage::disk('counseling')->allFiles())->toBe([]);

    $this->actingAs($this->admin)->get('/settings/counseling-import?batch='.$plan['batch'])->assertInertia(fn (Assert $page) => $page
        ->where('run.state', 'failed')
        ->where('run.reason', fn (string $reason) => str_contains($reason, 'The import stopped at CSV line 2')));
});

it('skips a PDF with scripts or actions, an encrypted one and a text file named .pdf, keeping none of them', function () {
    $active = "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction 2 0 R >> endobj\n2 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    $encrypted = "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R /Encrypt 4 0 R >>\n%%EOF\n";
    $plan = checkImport($this, importCsv([
        ['', 'active.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'locked.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'notes.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
    ]), [
        UploadedFile::fake()->createWithContent('active.pdf', $active),
        UploadedFile::fake()->createWithContent('locked.pdf', $encrypted),
        UploadedFile::fake()->createWithContent('notes.pdf', "Just some notes about the counseling.\n"),
    ]);

    expect(planRow($plan, 2))->toMatchArray(['outcome' => 'skipped', 'reason' => 'this PDF has scripts, attachments or actions that run on their own, which the portal does not accept. Print it to a new PDF and attach that copy'])
        ->and(planRow($plan, 3))->toMatchArray(['outcome' => 'skipped', 'reason' => 'this PDF has a password or encryption. Attach a copy without one'])
        ->and(planRow($plan, 4))->toMatchArray(['outcome' => 'skipped', 'reason' => 'attach the signed document as a PDF']);

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch']);
    expect(CounselingRecord::query()->count())->toBe(0)
        ->and(Storage::disk('counseling')->allFiles())->toBe([]);
});

it('stores one record when two checks name the same PDF before either is imported', function () {
    $first = checkImport($this, importCsv([['', 'a.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12']]), [counselingPdf('a.pdf', 'same')]);
    $second = checkImport($this, importCsv([['', 'copy.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12']]), [counselingPdf('copy.pdf', 'same')]);
    expect(planRow($second, 2)['outcome'])->toBe('review');

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$first['batch']);
    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$second['batch']);

    expect(CounselingRecord::query()->count())->toBe(1)
        ->and(collect(app(ImportStaging::class)->status($second['batch'])['report']['rows'])->firstWhere('line', 2))->toMatchArray(['outcome' => 'skipped', 'reason' => 'already imported'])
        ->and(Storage::disk('counseling')->allFiles())->toHaveCount(1);
});

it('says when a PDF could not be opened in the browser, and which field of a form was too long', function () {
    $plan = checkImport($this, importCsv([
        ['', 'odd.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'long.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
    ]), [counselingPdf('odd.pdf', 'odd'), counselingPdf('long.pdf', 'long')], [
        'odd.pdf' => ['error' => 'unreadable'],
        'long.pdf' => importFields(['form' => ['discussion' => str_repeat('x', 3001)]]),
    ]);

    expect(planRow($plan, 2)['reason'])->toBe('The PDF could not be opened to read its form fields: fill them in after the import.')
        ->and(planRow($plan, 3)['reason'])->toBe('The form\'s discussion field could not be kept (Keep this to 3000 characters): fill them in after the import.');
});

it('says the files could not be kept when the staging area will not take them', function () {
    // A file where the batches' directory should be: nothing can be written under it.
    Storage::disk('local')->put('counseling-import', 'in the way');

    $this->actingAs($this->admin)->post('/settings/counseling-import/check', [
        'csv' => importCsv([['', 'a.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12']]),
        'files' => [counselingPdf('a.pdf')],
    ])->assertSessionHasErrors(['csv' => 'The import\'s files could not be kept on the server. Try again; if it keeps failing, the server\'s storage needs checking.']);
});

it('never imports the same document twice, across runs or within one CSV', function () {
    $first = checkImport($this, importCsv([['', 'a.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12']]), [counselingPdf('a.pdf', 'same')]);
    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$first['batch']);

    $again = checkImport($this, importCsv([
        ['', 'renamed.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'b.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
        ['', 'b-copy.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12'],
    ]), [counselingPdf('renamed.pdf', 'same'), counselingPdf('b.pdf', 'other'), counselingPdf('b-copy.pdf', 'other')]);

    expect(planRow($again, 2))->toMatchArray(['outcome' => 'skipped', 'reason' => 'already imported'])
        ->and(planRow($again, 3)['outcome'])->toBe('review')
        ->and(planRow($again, 4))->toMatchArray(['outcome' => 'skipped', 'reason' => 'the same document as line 3']);

    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$again['batch']);
    expect(CounselingRecord::query()->count())->toBe(2);
});

it('takes the Admin\'s pick for a Last.F several troopers share, only among that row\'s matches', function () {
    $one = Trooper::factory()->create(['username' => 'Novak.J']);
    Trooper::factory()->create(['username' => 'Novak.J']);
    $csv = fn () => importCsv([
        ['', 'a.pdf', 'dcs', 'Novak.J', '', '', '2025-03-12'],
        ['', 'b.pdf', 'dcs', 'Novak.J', '', '', '2025-03-12'],
        ['', 'c.pdf', 'dcs', 'Novak.J', '', '', '2025-03-12'],
    ]);

    $plan = checkImport($this, $csv(), [counselingPdf('a.pdf', 'a'), counselingPdf('b.pdf', 'b'), counselingPdf('c.pdf', 'c')]);
    $this->actingAs($this->admin)->post('/settings/counseling-import/'.$plan['batch'], ['picks' => [2 => $one->id, 3 => $this->tree['rifleman']->id]]);

    $stored = CounselingRecord::query()->sole();
    expect($stored->trooper_id)->toBe($one->id)
        ->and($stored->document_name)->toBe('a.pdf');
});

it('keeps a batch to the Admin who checked it', function () {
    $plan = checkImport($this, importCsv([['', 'a.pdf', 'dcs', 'Brandt.L', '', '', '2025-03-12']]), [counselingPdf('a.pdf')]);
    $other = User::factory()->admin()->create();

    $this->actingAs($other)->post('/settings/counseling-import/'.$plan['batch'])->assertNotFound();
    $this->actingAs($other)->get('/settings/counseling-import?batch='.$plan['batch'])->assertInertia(fn (Assert $page) => $page->where('check', null));
    expect(CounselingRecord::query()->count())->toBe(0);
});

it('runs the same import from the console, as the earliest Admin, flagging every row for review', function () {
    $dir = sys_get_temp_dir().'/counseling-import-test-'.Str::random(8);
    mkdir($dir);
    file_put_contents("{$dir}/a.pdf", counselingPdfBytes('console'));
    file_put_contents("{$dir}/import.csv", "drive_url,file,type,trooper,kind,issuer,counseled_on\n,A.PDF,dcs,Brandt.L,,Okafor.B,2025-03-12\n,b.pdf,dcs,Brandt.L,,,2025-03-12\n");

    $this->artisan('counseling:import', ['csv' => "{$dir}/import.csv", '--files' => $dir, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('2 rows: would import 0, 1 needing review, 1 skipped.')
        ->assertSuccessful();
    expect(CounselingRecord::query()->count())->toBe(0);

    $this->artisan('counseling:import', ['csv' => "{$dir}/import.csv", '--files' => $dir])->assertSuccessful();

    $record = CounselingRecord::query()->sole();
    expect($record->needs_review)->toBeTrue()
        ->and($record->issued_by)->toBe($this->tree['users']['sl']->id)
        ->and(AuditLogEntry::query()->where('action', 'counseling.imported')->sole())
        ->actor_id->toBe($this->admin->id)
        ->reason->toBe('Counseling import, CSV line 2, from the console')
        ->and(Storage::disk('local')->allDirectories())->toBe(['counseling-import']);

    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});

it('refuses a console run without an Admin to record', function () {
    User::query()->update(['is_admin' => false]);
    $csv = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($csv, "drive_url,file\n,a.pdf\n");

    $this->artisan('counseling:import', ['csv' => $csv])->expectsOutputToContain('There is no Admin')->assertFailed();
    unlink($csv);
});
