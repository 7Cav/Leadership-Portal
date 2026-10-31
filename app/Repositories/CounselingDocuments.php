<?php

namespace App\Repositories;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sole access to the counseling source documents on disk (#381, #565): the PDFs uploaded before records were written in
 * the portal, and the ones the counseling import brings in. Kept permanently, never purged; the portal keeps no file it
 * generated. The file half of `counseling_records` as {@see CounselingRecordRepository} is the row half. The `counseling`
 * disk is private (`storage/app/counseling`, outside
 * both `public/` and the `local` disk the framework can serve), so a document leaves only through the authorized
 * download route, which re-checks who may see the record before asking for {@see download()}.
 *
 * Every stored file gets a name the portal makes up (a UUID with `.pdf`); the uploader's file name is never part of a
 * path, so it cannot point anywhere else on the disk. What callers pass back in as `$path` is only ever a
 * `document_path` this class wrote, and every path is re-checked against that shape all the same.
 */
class CounselingDocuments
{
    public const DISK = 'counseling';

    /** The path prefix of a seeded demo document ({@see isDemo()}). Nothing the portal stores starts with it. */
    public const DEMO_PREFIX = 'demo/';

    /**
     * Copy the file at `$sourcePath` (the upload's temporary file) onto the private disk.
     */
    public function store(string $sourcePath, string $name): StoredDocument
    {
        $path = Str::uuid()->toString().'.pdf';
        $size = filesize($sourcePath);
        $sha256 = hash_file('sha256', $sourcePath);

        if ($size === false || $sha256 === false || $this->disk()->putFileAs('', new File($sourcePath), $path) === false) {
            throw new RuntimeException('The counseling document could not be stored.');
        }

        return new StoredDocument($path, $name, $size, $sha256);
    }

    /** The SHA-256 of the document as it is on disk now, for the download's audit entry; null when it is missing. */
    public function sha256(string $path): ?string
    {
        $full = $this->disk()->path($this->checked($path));

        return is_file($full) ? (hash_file('sha256', $full) ?: null) : null;
    }

    /** Whether the document is there. A path this class could not have made never is. */
    public function exists(string $path): bool
    {
        return self::isOurs($path) && $this->disk()->exists($path);
    }

    /**
     * The document as a download named `$downloadName`: always an attachment, always `application/pdf`, so a browser
     * neither renders it in the portal's origin nor guesses another type. The sandbox policy covers a browser that
     * shows it anyway: no script, no requests, no access to the portal's origin. A name outside plain ASCII also gets
     * an ASCII fallback beside the RFC 5987 one.
     */
    public function download(string $path, string $downloadName): StreamedResponse
    {
        return $this->disk()->download($this->checked($path), $downloadName, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
        ]);
    }

    public function delete(string $path): void
    {
        if (self::isDemo($path)) {
            return;
        }

        $this->disk()->delete($this->checked($path));
    }

    /**
     * Whether `$path` names a demo document: a seeded record's (`CounselingSeeder`), under {@see DEMO_PREFIX}, with no
     * file behind it. The download refuses it and the page says so instead of downloading.
     */
    public static function isDemo(string $path): bool
    {
        return str_starts_with($path, self::DEMO_PREFIX) && self::isOurs(substr($path, strlen(self::DEMO_PREFIX)));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** Only a name this class could have made: a UUID and `.pdf`, nothing that walks a directory. */
    private function checked(string $path): string
    {
        return self::isOurs($path) ? $path : throw new RuntimeException('Not a counseling document path.');
    }

    private static function isOurs(string $path): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/', $path) === 1;
    }
}
