<?php

use App\Integration\DriveDownload\DriveDownloadApi;
use App\Integration\DriveDownload\DriveDownloadConnector;
use App\Integration\DriveDownload\DriveDownloadFailed;
use App\Integration\DriveDownload\DriveDownloadFailure;
use App\Integration\DriveDownload\Requests\DownloadFileRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * #565: the public Drive download behind the counseling import. Every call is faked with Saloon's MockClient; nothing
 * here touches Google.
 */
const DRIVE_ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz012345';
const DRIVE_MAX = 10 * 1024 * 1024;

function drivePdf(): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

function driveFailure(Closure $call): DriveDownloadFailure
{
    try {
        $call();
    } catch (DriveDownloadFailed $e) {
        return $e->reason;
    }

    throw new RuntimeException('Expected a DriveDownloadFailed.');
}

it('reads the file id from the share links Drive hands out', function (string $url) {
    expect((new DriveDownloadApi)->fileIdFrom($url))->toBe(DRIVE_ID);
})->with([
    'https://drive.google.com/file/d/'.DRIVE_ID.'/view?usp=sharing',
    'https://drive.google.com/file/d/'.DRIVE_ID,
    'https://drive.google.com/u/0/file/d/'.DRIVE_ID.'/view',
    'https://drive.google.com/open?id='.DRIVE_ID,
    'https://drive.google.com/uc?export=download&id='.DRIVE_ID,
    'https://docs.google.com/file/d/'.DRIVE_ID.'/edit',
    '  https://DRIVE.google.com/file/d/'.DRIVE_ID.'/view  ',
]);

it('refuses folder links and anything that is not one Drive file', function (string $url, DriveDownloadFailure $reason) {
    expect(driveFailure(fn () => (new DriveDownloadApi)->fileIdFrom($url)))->toBe($reason);
})->with([
    ['https://drive.google.com/drive/folders/'.DRIVE_ID, DriveDownloadFailure::Folder],
    ['https://drive.google.com/drive/u/1/folders/'.DRIVE_ID.'?usp=sharing', DriveDownloadFailure::Folder],
    ['https://drive.google.com/embeddedfolderview?id='.DRIVE_ID, DriveDownloadFailure::Folder],
    ['https://evil.test/file/d/'.DRIVE_ID, DriveDownloadFailure::NotDriveLink],
    ['https://drive.google.com.evil.test/file/d/'.DRIVE_ID, DriveDownloadFailure::NotDriveLink],
    ['https://user@drive.google.com/file/d/'.DRIVE_ID, DriveDownloadFailure::NotDriveLink],
    ['ftp://drive.google.com/file/d/'.DRIVE_ID, DriveDownloadFailure::NotDriveLink],
    ['https://drive.google.com/file/d/short', DriveDownloadFailure::NotDriveLink],
    ['https://drive.google.com/open?id=../../etc', DriveDownloadFailure::NotDriveLink],
    ['not a url', DriveDownloadFailure::NotDriveLink],
]);

it('downloads the canonical link with no credentials and follows Drive\'s redirect to its download host', function () {
    $mock = MockClient::global([
        MockResponse::make('', 303, ['Location' => 'https://drive.usercontent.google.com/download?id='.DRIVE_ID.'&export=download']),
        MockResponse::make(drivePdf(), 200, ['Content-Type' => 'application/pdf']),
    ]);

    expect((new DriveDownloadApi)->download(DRIVE_ID, DRIVE_MAX))->toBe(drivePdf());

    $sent = $mock->getRecordedResponses();
    expect($sent)->toHaveCount(2)
        ->and((string) $sent[0]->getPendingRequest()->getUri())->toBe('https://drive.google.com/uc?export=download&id='.DRIVE_ID)
        ->and((string) $sent[1]->getPendingRequest()->getUri())->toBe('https://drive.usercontent.google.com/download?id='.DRIVE_ID.'&export=download');

    foreach ($sent as $response) {
        $headers = array_change_key_case($response->getPendingRequest()->headers()->all());
        expect($headers)->not->toHaveKey('authorization')->not->toHaveKey('cookie');
    }
});

it('follows the older per-file download host too', function () {
    MockClient::global([
        MockResponse::make('', 302, ['Location' => 'https://doc-0s-bk-docs.googleusercontent.com/docs/securesc/x/'.DRIVE_ID]),
        MockResponse::make(drivePdf(), 200, ['Content-Type' => 'application/octet-stream']),
    ]);

    expect((new DriveDownloadApi)->download(DRIVE_ID, DRIVE_MAX))->toBe(drivePdf());
});

it('treats a sign-in redirect, a web page, a refusal or another file as not public', function (array $responses) {
    MockClient::global($responses);

    expect(driveFailure(fn () => (new DriveDownloadApi)->download(DRIVE_ID, DRIVE_MAX)))->toBe(DriveDownloadFailure::NotPublic);
})->with([
    'sign-in redirect' => [[MockResponse::make('', 302, ['Location' => 'https://accounts.google.com/ServiceLogin?continue=x'])]],
    'redirect off Google' => [[MockResponse::make('', 302, ['Location' => 'https://evil.test/file.pdf'])]],
    'plain http redirect' => [[MockResponse::make('', 302, ['Location' => 'http://drive.usercontent.google.com/download'])]],
    'redirect without a target' => [[MockResponse::make('', 302)]],
    'html page' => [[MockResponse::make('<html>Sign in</html>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]],
    'html sent as octet-stream' => [[MockResponse::make('<!doctype html><html></html>', 200, ['Content-Type' => 'application/octet-stream'])]],
    'forbidden' => [[MockResponse::make('', 403)]],
    'not found' => [[MockResponse::make('', 404)]],
]);

it('stops after a few redirects', function () {
    MockClient::global(array_fill(0, 10, MockResponse::make('', 302, ['Location' => 'https://drive.google.com/uc?id='.DRIVE_ID])));

    expect(driveFailure(fn () => (new DriveDownloadApi)->download(DRIVE_ID, DRIVE_MAX)))->toBe(DriveDownloadFailure::NotPublic);
});

it('refuses a file over the cap', function () {
    MockClient::global([MockResponse::make(drivePdf().str_repeat('x', 100), 200, ['Content-Type' => 'application/pdf'])]);

    expect(driveFailure(fn () => (new DriveDownloadApi)->download(DRIVE_ID, 50)))->toBe(DriveDownloadFailure::TooLarge);
});

it('says Drive is unreachable on a server error, a rate limit or a failed connection', function (?int $status) {
    MockClient::global([$status === null ? fn () => throw new RuntimeException('connection refused') : MockResponse::make('', $status)]);

    expect(driveFailure(fn () => (new DriveDownloadApi)->download(DRIVE_ID, DRIVE_MAX)))->toBe(DriveDownloadFailure::Unreachable);
})->with([
    'server error' => [503],
    'rate limit' => [429],
    'connection' => [null],
]);

it('never sends a request to a host the seam does not allow, whatever reaches the connector', function () {
    $mock = new MockClient([MockResponse::make(drivePdf())]);
    $connector = new DriveDownloadConnector(DRIVE_MAX);
    $connector->withMockClient($mock);

    expect(fn () => $connector->send(new DownloadFileRequest('https://evil.test/x.pdf')))->toThrow(DriveDownloadFailed::class)
        ->and(fn () => $connector->send(new DownloadFileRequest('http://drive.google.com/uc?id='.DRIVE_ID)))->toThrow(DriveDownloadFailed::class);
    $mock->assertNothingSent();
});

it('allows only Google\'s own download hosts', function () {
    expect(DriveDownloadApi::isAllowedHost('drive.google.com'))->toBeTrue()
        ->and(DriveDownloadApi::isAllowedHost('drive.usercontent.google.com'))->toBeTrue()
        ->and(DriveDownloadApi::isAllowedHost('doc-0s-bk-docs.googleusercontent.com'))->toBeTrue()
        ->and(DriveDownloadApi::isAllowedHost('lh3.googleusercontent.com'))->toBeFalse()
        ->and(DriveDownloadApi::isAllowedHost('accounts.google.com'))->toBeFalse()
        ->and(DriveDownloadApi::isAllowedHost('drive.google.com.evil.test'))->toBeFalse();
});
