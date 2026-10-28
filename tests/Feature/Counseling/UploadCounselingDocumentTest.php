<?php

use App\Commands\Counseling\UploadCounselingDocument;
use App\Commands\Counseling\UploadCounselingDocumentHandler;
use App\Integration\GoogleDrive\GoogleDriveApi;
use App\Integration\GoogleDrive\GoogleDriveUnavailable;
use App\Integration\GoogleDrive\Requests\RequestAccessTokenRequest;
use App\Integration\GoogleDrive\Requests\StartUploadRequest;
use App\Integration\GoogleDrive\Requests\UploadContentRequest;
use App\Jobs\UploadCounselingDocumentJob;
use App\Models\AuditLogEntry;
use App\Models\CounselingRecord;
use App\Models\DriveUploadStatus;
use App\Models\Trooper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

// #381: the queued copy of a counseling document to Google Drive. Google is always faked here.

beforeEach(function () {
    Storage::fake('counseling');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $this->configureDrive = function () use ($private) {
        config(['services.google_drive' => ['service_account_email' => 'portal@cav-test.iam.gserviceaccount.com', 'private_key' => $private, 'folder_id' => 'folder-123']]);
        app()->forgetInstance(GoogleDriveApi::class);
    };
    $this->record = CounselingRecord::factory()->create([
        'trooper_id' => Trooper::factory()->create(['username' => 'Brandt.L'])->id,
        'counseled_on' => '2026-10-20',
        'drive_status' => DriveUploadStatus::Pending,
    ]);
    Storage::disk('counseling')->put($this->record->document_path, '%PDF-1.4 the signed statement');
    $this->google = function (?MockResponse $upload = null): MockClient {
        // A fresh client each time, so a test can swap a failing Google for a working one.
        MockClient::destroyGlobal();

        return MockClient::global([
            RequestAccessTokenRequest::class => MockResponse::make(['access_token' => 'ya29.test-token']),
            StartUploadRequest::class => MockResponse::make('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=S1']),
            UploadContentRequest::class => $upload ?? MockResponse::make(['id' => 'drive-file-9']),
        ]);
    };
});

it('uploads the document, named after the record, and marks the record uploaded with the Drive file id', function () {
    ($this->configureDrive)();
    $mock = ($this->google)();

    UploadCounselingDocumentJob::dispatch(new UploadCounselingDocument($this->record->id));

    $record = $this->record->fresh();
    expect($record->drive_status)->toBe(DriveUploadStatus::Uploaded)
        ->and($record->drive_file_id)->toBe('drive-file-9')
        ->and($record->drive_attempted_at)->not->toBeNull();
    $mock->assertSent(fn (Request $request, $response) => $request instanceof StartUploadRequest
        && $response->getPendingRequest()->body()->get('name') === 'DCS 2026-10-20 Brandt.L.pdf');
    $mock->assertSent(fn (Request $request, $response) => $request instanceof UploadContentRequest
        && (string) $response->getPendingRequest()->body() === '%PDF-1.4 the signed statement');
    // The upload's bookkeeping is not a personnel action: it writes no audit entry.
    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('marks the record failed and throws, so the queue retries it', function () {
    ($this->configureDrive)();
    ($this->google)(MockResponse::make('', 503));

    expect(fn () => app(UploadCounselingDocumentHandler::class)->handle(new UploadCounselingDocument($this->record->id)))
        ->toThrow(GoogleDriveUnavailable::class);
    expect($this->record->fresh()->drive_status)->toBe(DriveUploadStatus::Failed);

    // A later try that succeeds clears it.
    ($this->google)();
    app(UploadCounselingDocumentHandler::class)->handle(new UploadCounselingDocument($this->record->id));
    expect($this->record->fresh()->drive_status)->toBe(DriveUploadStatus::Uploaded);
});

it('retries five times with growing pauses, and logs the record but no credential when it gives up', function () {
    $job = new UploadCounselingDocumentJob(new UploadCounselingDocument($this->record->id));
    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([60, 300, 900, 3600]);

    Log::spy();
    $job->failed(GoogleDriveUnavailable::httpError('upload', 503));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['recordId'] === $this->record->id
        && str_contains($context['exception'], 'HTTP 503')
        && ! str_contains(json_encode($context), 'PRIVATE KEY'));
});

it('skips the upload, and keeps the record, when Google Drive is not configured', function () {
    $mock = ($this->google)();

    app(UploadCounselingDocumentHandler::class)->handle(new UploadCounselingDocument($this->record->id));

    expect($this->record->fresh()->drive_status)->toBe(DriveUploadStatus::Skipped);
    $mock->assertNothingSent();
});

it('does nothing for a record deleted before its upload ran, or one already uploaded', function () {
    ($this->configureDrive)();
    $mock = ($this->google)();
    $uploaded = CounselingRecord::factory()->create(['drive_status' => DriveUploadStatus::Uploaded, 'drive_file_id' => 'kept']);

    expect(app(UploadCounselingDocumentHandler::class)->handle(new UploadCounselingDocument(999999)))->toBeNull()
        ->and(app(UploadCounselingDocumentHandler::class)->handle(new UploadCounselingDocument($uploaded->id)))->toBe(DriveUploadStatus::Uploaded)
        ->and($uploaded->fresh()->drive_file_id)->toBe('kept');
    $mock->assertNothingSent();
});

it('marks a record whose document went missing as failed, so it is retried and seen', function () {
    ($this->configureDrive)();
    $mock = ($this->google)();
    Storage::disk('counseling')->delete($this->record->document_path);

    expect(fn () => app(UploadCounselingDocumentHandler::class)->handle(new UploadCounselingDocument($this->record->id)))
        ->toThrow(RuntimeException::class);
    expect($this->record->fresh()->drive_status)->toBe(DriveUploadStatus::Failed);
    $mock->assertNothingSent();
});
