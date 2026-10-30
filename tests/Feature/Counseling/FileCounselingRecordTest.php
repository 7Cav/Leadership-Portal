<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\Counseling\CounselingForbiddenException;
use App\Commands\Counseling\CounselingRules;
use App\Commands\Counseling\FileCounselingRecord;
use App\Commands\Counseling\FileCounselingRecordHandler;
use App\Commands\Counseling\InvalidCounselingRecordException;
use App\Integration\GoogleDrive\GoogleDriveApi;
use App\Integration\GoogleDrive\Requests\RequestAccessTokenRequest;
use App\Jobs\UploadCounselingDocumentJob;
use App\Models\AuditLogEntry;
use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\DriveUploadStatus;
use App\Models\TrooperStatus;
use App\Support\Audit\ActingFor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/fixture.php';

// #381: a leader files a DCS or LOR on a trooper with the signed PDF attached. The portal stores the file privately,
// audits the filing, and queues the Drive copy when Drive is configured.

beforeEach(function () {
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->file = fn (array $overrides = []) => [
        'type' => 'dcs',
        'kind' => 'missed_practice',
        'counseled_on' => now()->subDays(3)->toDateString(),
        'reason' => 'Missed two section practices without notice.',
        'document' => counselingPdf(),
        ...$overrides,
    ];
});

function nothingFiled(): void
{
    expect(CounselingRecord::query()->count())->toBe(0)
        ->and(Storage::disk('counseling')->allFiles())->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'counseling.filed')->count())->toBe(0);
}

it('files a DCS with its document: the record, a private copy under a name the portal made, and an audit entry', function () {
    Queue::fake();

    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)())
        ->assertRedirect(route('troopers.show', $this->rifleman->id));

    $record = CounselingRecord::query()->sole();
    expect($record->type)->toBe(CounselingType::Dcs)
        ->and($record->kind)->toBe(CounselingKind::MissedPractice)
        ->and($record->trooper_id)->toBe($this->rifleman->id)
        ->and($record->counseled_on->toDateString())->toBe(now()->subDays(3)->toDateString())
        ->and($record->reason)->toBe('Missed two section practices without notice.')
        ->and($record->issued_by)->toBe($this->tree['users']['sl']->id)
        ->and($record->issued_by_admin)->toBeNull()
        ->and($record->issuer_unit_id)->toBe($this->tree['units']['section']->id)
        ->and($record->document_name)->toBe('Brandt DCS.pdf')
        ->and($record->document_size)->toBe(strlen(counselingPdfBytes()))
        ->and($record->document_sha256)->toBe(hash('sha256', counselingPdfBytes()))
        ->and($record->document_path)->toMatch('/^[0-9a-f-]{36}\.pdf$/')
        // Google Drive is not configured in the test env: the record stands, the upload is skipped.
        ->and($record->drive_status)->toBe(DriveUploadStatus::Skipped);

    Storage::disk('counseling')->assertExists($record->document_path);
    expect(Storage::disk('counseling')->get($record->document_path))->toBe(counselingPdfBytes());

    $entry = AuditLogEntry::query()->where('action', 'counseling.filed')->sole();
    expect($entry->actor_id)->toBe($this->tree['users']['sl']->id)
        ->and($entry->target_type)->toBe('CounselingRecord')
        ->and($entry->target_id)->toBe((string) $record->id)
        ->and($entry->content_digest)->toMatch('/^[0-9a-f]{64}$/');

    // Nothing of the file but its hash is in the log.
    expect(json_encode(AuditLogEntry::query()->get()->toArray()))->not->toContain('signed-dcs-body');
    Queue::assertNothingPushed();
});

it('queues the Drive upload when Google Drive is configured, and the record waits as pending', function () {
    Queue::fake();
    config(['services.google_drive' => ['service_account_email' => 'sa@example.iam.gserviceaccount.com', 'private_key' => 'not-used-here', 'folder_id' => 'folder-1']]);
    app()->forgetInstance(GoogleDriveApi::class);

    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)(['type' => 'lor']))
        ->assertRedirect();

    $record = CounselingRecord::query()->sole();
    expect($record->drive_status)->toBe(DriveUploadStatus::Pending)
        ->and($record->issuer_unit_id)->toBe($this->tree['units']['platoon']->id);

    Queue::assertPushed(UploadCounselingDocumentJob::class, fn (UploadCounselingDocumentJob $job) => $job->command->recordId === $record->id);
    // The payload is the record id: no document and no credential on the queue.
    Queue::assertPushed(UploadCounselingDocumentJob::class, fn (UploadCounselingDocumentJob $job) => ! str_contains(serialize($job), 'PDF') && ! str_contains(serialize($job), 'not-used-here'));
});

it('refuses an LOR from a Section Leader, and stores nothing', function () {
    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)(['type' => 'lor']))
        ->assertForbidden();

    nothingFiled();
});

it('refuses a staff grant holder, who reads the trooper but commands nothing', function () {
    $this->actingAs($this->tree['users']['grant'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)())
        ->assertForbidden();

    nothingFiled();
});

it('answers 404 for a trooper outside the filer\'s scope, as for one that does not exist', function () {
    $this->actingAs($this->tree['users']['otherSl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)())
        ->assertNotFound();
    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', 999999), ($this->file)())
        ->assertNotFound();

    nothingFiled();
});

it('refuses every write during a view-as preview', function () {
    $admin = $this->tree['users']['admin'];
    app(ViewAs::class)->enter($admin, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['section']->id));

    $this->actingAs($admin)->post(route('counseling.store', $this->rifleman->id), ($this->file)())->assertForbidden();

    nothingFiled();
});

it('judges the document by its bytes: a renamed file, a lie about its type and an oversize file are refused', function (Closure $document) {
    $this->actingAs($this->tree['users']['sl'])
        ->from(route('troopers.show', $this->rifleman->id))
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)(['document' => $document()]))
        ->assertSessionHasErrors('document');

    nothingFiled();
})->with([
    'a text file named .pdf' => [fn () => UploadedFile::fake()->createWithContent('dcs.pdf', 'just some text, not a pdf')],
    'html claiming to be a pdf' => [fn () => UploadedFile::fake()->createWithContent('dcs.pdf', '<html><script>alert(1)</script></html>')],
    'a png named .pdf' => [fn () => UploadedFile::fake()->createWithContent('dcs.pdf', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))],
    'over 10 MB' => [fn () => UploadedFile::fake()->create('dcs.pdf', 10 * 1024 + 1, 'application/pdf')],
    'no file' => [fn () => null],
    'a real pdf without the .pdf extension' => [fn () => UploadedFile::fake()->createWithContent('dcs.exe', counselingPdfBytes())],
]);

it('refuses a PDF it cannot trust, saying why in plain words, and stores nothing', function (string $bytes, string $message) {
    $this->actingAs($this->tree['users']['sl'])
        ->from(route('troopers.show', $this->rifleman->id))
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)(['document' => UploadedFile::fake()->createWithContent('dcs.pdf', $bytes)]))
        ->assertSessionHasErrors(['document' => $message]);

    nothingFiled();
})->with([
    'a script' => [str_replace('/Type /Catalog', '/Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >>', counselingPdfBytes()), 'This PDF has scripts, attachments or actions that run on their own, which the portal does not accept. Print it to a new PDF and attach that copy.'],
    'an attachment' => [str_replace('/Type /Catalog', '/Type /Catalog /Names << /EmbeddedFiles 9 0 R >>', counselingPdfBytes()), 'This PDF has scripts, attachments or actions that run on their own, which the portal does not accept. Print it to a new PDF and attach that copy.'],
    'a password' => [str_replace('/Root 1 0 R', '/Root 1 0 R /Encrypt 9 0 R', counselingPdfBytes()), 'This PDF has a password or encryption. Attach a copy without one.'],
    'no end marker' => [str_replace('%%EOF', '', counselingPdfBytes()), 'This PDF looks incomplete or damaged. Save it again and attach the new copy.'],
]);

it('refuses a future date, a missing reason and an unknown type', function (array $overrides, string $field) {
    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)($overrides))
        ->assertSessionHasErrors($field);

    nothingFiled();
})->with([
    'tomorrow' => [['counseled_on' => '2999-01-01'], 'counseled_on'],
    'not a date' => [['counseled_on' => '../../etc'], 'counseled_on'],
    'no reason' => [['reason' => '   '], 'reason'],
    'reason too long' => [['reason' => str_repeat('x', 2001)], 'reason'],
    'unknown type' => [['type' => 'article15'], 'type'],
]);

it('never takes a path from the uploaded file name', function () {
    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)(['document' => counselingPdf('../../../public/evil<script>.pdf')]))
        ->assertRedirect();

    $record = CounselingRecord::query()->sole();
    expect($record->document_path)->toMatch('/^[0-9a-f-]{36}\.pdf$/')
        ->and($record->document_name)->toBe('evilscript.pdf')
        ->and(Storage::disk('counseling')->allFiles())->toBe([$record->document_path]);
});

it('records the Admin behind a filing made while acting as a leader', function () {
    $admin = $this->tree['users']['admin'];
    $pl = $this->tree['users']['pl'];
    app(ActingFor::class)->set($admin->id, $pl->id);
    $pdf = counselingPdf();

    $record = app(FileCounselingRecordHandler::class)->handle($pl, new FileCounselingRecord(
        $this->rifleman->id, CounselingType::Lor, CounselingKind::Conduct, CarbonImmutable::now()->subDay(), 'Insubordination at practice.', $pdf->getRealPath(), 'lor.pdf',
    ));

    expect($record->issued_by)->toBe($pl->id)
        ->and($record->issued_by_admin)->toBe($admin->id);
    expect(AuditLogEntry::query()->where('action', 'counseling.filed')->sole())
        ->actor_id->toBe($admin->id)
        ->on_behalf_of_id->toBe($pl->id);
});

it('checks the document again in the handler, whatever reached it', function () {
    $dir = sys_get_temp_dir();
    $big = tempnam($dir, 'cr');
    file_put_contents($big, '%PDF-1.4'."\n".str_repeat('0', CounselingRules::DOCUMENT_MAX_BYTES));
    $empty = tempnam($dir, 'cr');

    try {
        expect(fn () => CounselingRules::checkDocument($big))->toThrow(InvalidCounselingRecordException::class, 'The document must be 10 MB or smaller.')
            ->and(fn () => CounselingRules::checkDocument($empty))->toThrow(InvalidCounselingRecordException::class)
            ->and(fn () => CounselingRules::checkDocument('/nonexistent/file.pdf'))->toThrow(InvalidCounselingRecordException::class);
        $pdf = counselingPdf();
        CounselingRules::checkDocument($pdf->getRealPath());
    } finally {
        array_map('unlink', [$big, $empty]);
    }

    $notPdf = UploadedFile::fake()->createWithContent('x.pdf', 'MZ not a pdf');
    expect(fn () => app(FileCounselingRecordHandler::class)->handle($this->tree['users']['sl'], new FileCounselingRecord(
        $this->rifleman->id, CounselingType::Dcs, CounselingKind::Conduct, CarbonImmutable::now(), 'Reason', $notPdf->getRealPath(), 'x.pdf',
    )))->toThrow(InvalidCounselingRecordException::class);

    nothingFiled();
});

it('keeps old paperwork: a date long past is accepted and still filed', function () {
    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)(['counseled_on' => '2025-01-15']))
        ->assertRedirect();

    expect(CounselingRecord::query()->sole()->counseled_on->toDateString())->toBe('2025-01-15');
});

it('keeps the filing when the Drive copy fails at once, as it does on a sync queue', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    config(['services.google_drive' => ['service_account_email' => 'sa@example.iam.gserviceaccount.com', 'private_key' => $private, 'folder_id' => 'folder-1']]);
    app()->forgetInstance(GoogleDriveApi::class);
    MockClient::global([RequestAccessTokenRequest::class => MockResponse::make(['error' => 'invalid_grant'], 400)]);

    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)())
        ->assertRedirect(route('troopers.show', $this->rifleman->id));

    $record = CounselingRecord::query()->sole();
    expect($record->drive_status)->toBe(DriveUploadStatus::Failed);
    Storage::disk('counseling')->assertExists($record->document_path);
});

it('refuses a double submit: the same record filed again within 10 minutes is already filed, and its copy is removed', function () {
    $this->travelTo(now()->setTime(12, 0));
    $sl = $this->tree['users']['sl'];
    $this->actingAs($sl)->post(route('counseling.store', $this->rifleman->id), ($this->file)())->assertRedirect();
    $stored = Storage::disk('counseling')->allFiles();

    $this->travel(9)->minutes();
    $this->actingAs($sl)->post(route('counseling.store', $this->rifleman->id), ($this->file)())
        ->assertSessionHasErrors(['document' => 'This record is already filed.']);

    expect(CounselingRecord::query()->count())->toBe(1)
        ->and(Storage::disk('counseling')->allFiles())->toBe($stored)
        ->and(AuditLogEntry::query()->where('action', 'counseling.filed')->count())->toBe(1);

    // Someone else in the chain submitting the very same paperwork is the same double filing.
    $this->actingAs($this->tree['users']['pl'])->post(route('counseling.store', $this->rifleman->id), ($this->file)())
        ->assertSessionHasErrors('document');
    expect(CounselingRecord::query()->count())->toBe(1);
});

it('files a record again once it differs in type, date, reason or document, or once 10 minutes have passed', function (Closure $again, bool $travel) {
    $this->travelTo(now()->setTime(12, 0));
    $pl = $this->tree['users']['pl'];
    $this->actingAs($pl)->post(route('counseling.store', $this->rifleman->id), ($this->file)())->assertRedirect();

    if ($travel) {
        $this->travel(11)->minutes();
    }

    $this->actingAs($pl)->post(route('counseling.store', $this->rifleman->id), ($this->file)($again()))->assertSessionHasNoErrors()->assertRedirect();
    expect(CounselingRecord::query()->count())->toBe(2)
        ->and(Storage::disk('counseling')->allFiles())->toHaveCount(2);
})->with([
    'another type' => [fn () => ['type' => 'lor'], false],
    'another date' => [fn () => ['counseled_on' => now()->subDays(4)->toDateString()], false],
    'another reason' => [fn () => ['reason' => 'Missed three section practices without notice.'], false],
    'another document' => [fn () => ['document' => counselingPdf(marker: 'a-second-signed-copy')], false],
    'the same, 11 minutes later' => [fn () => [], true],
]);

it('refuses the repeat in the handler too, with nothing left on the disk', function () {
    $handler = app(FileCounselingRecordHandler::class);
    $path = tempnam(sys_get_temp_dir(), 'dcs');
    file_put_contents($path, counselingPdfBytes());
    $command = new FileCounselingRecord(
        trooperId: $this->rifleman->id,
        type: CounselingType::Dcs,
        kind: CounselingKind::MissedPractice,
        counseledOn: CarbonImmutable::now('UTC')->subDays(2),
        reason: '  Missed practice.  ',
        documentPath: $path,
        documentName: 'dcs.pdf',
    );

    $handler->handle($this->tree['users']['sl'], $command);

    // The reason is compared as stored, trimmed.
    expect(fn () => $handler->handle($this->tree['users']['sl'], new FileCounselingRecord(
        $command->trooperId, $command->type, $command->kind, $command->counseledOn, 'Missed practice.', $path, 'other-name.pdf',
    )))->toThrow(InvalidCounselingRecordException::class, 'This record is already filed.');

    expect(Storage::disk('counseling')->allFiles())->toHaveCount(1);
    @unlink($path);
});

it('requires what the record is for: a missed section practice or rollcall, or other conduct', function (array $payload) {
    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)($payload))
        ->assertSessionHasErrors(['kind' => 'Choose what the record is for.']);

    nothingFiled();
})->with([
    'missing' => [['kind' => null]],
    'unknown' => [['kind' => 'tardiness']],
]);

it('stores other conduct as its own kind', function () {
    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)(['type' => 'lor', 'kind' => 'conduct']))
        ->assertRedirect();

    expect(CounselingRecord::query()->sole()->kind)->toBe(CounselingKind::Conduct);
});

it('refuses any filing on a trooper who has left the Cav, Admins included, and allows it again once they serve again', function () {
    $this->rifleman->forceFill(['status' => TrooperStatus::Inactive])->save();

    foreach (['admin', 'pl', 'sl'] as $who) {
        $this->actingAs($this->tree['users'][$who])
            ->post(route('counseling.store', $this->rifleman->id), ($this->file)())
            ->assertForbidden();
    }
    nothingFiled();

    $this->rifleman->forceFill(['status' => TrooperStatus::Reserve])->save();
    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)())
        ->assertRedirect(route('troopers.show', $this->rifleman->id));
    expect(CounselingRecord::query()->count())->toBe(1);
});

it('refuses filing on a departed trooper in the handler too, saying why, before anything is stored', function () {
    $this->rifleman->forceFill(['status' => TrooperStatus::Inactive])->save();
    $pdf = counselingPdf();

    expect(fn () => app(FileCounselingRecordHandler::class)->handle($this->tree['users']['admin'], new FileCounselingRecord(
        $this->rifleman->id, CounselingType::Lor, CounselingKind::Conduct, CarbonImmutable::now(), 'Reason', $pdf->getRealPath(), 'lor.pdf',
    )))->toThrow(CounselingForbiddenException::class, 'Brandt.L is no longer serving, so their counseling records are read-only.');

    nothingFiled();
});

it('still answers 404, not the departed refusal, for a departed trooper the viewer cannot see', function () {
    $this->rifleman->forceFill(['status' => TrooperStatus::Inactive])->save();

    $this->actingAs($this->tree['users']['otherSl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->file)())
        ->assertNotFound();
});
