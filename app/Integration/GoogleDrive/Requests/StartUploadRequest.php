<?php

namespace App\Integration\GoogleDrive\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Opens a resumable upload of one file into the configured folder: `POST /upload/drive/v3/files?uploadType=resumable`
 * with the file's metadata. Drive answers with the session's URL in `Location`. Resumable rather than multipart because
 * Drive takes multipart uploads only up to 5 MB, and a document may be 10. `supportsAllDrives` because the folder must
 * sit in a shared drive: a service account has no storage of its own.
 */
class StartUploadRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $name,
        private readonly string $folderId,
        private readonly string $mimeType,
        private readonly int $size,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/upload/drive/v3/files';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['uploadType' => 'resumable', 'supportsAllDrives' => 'true', 'fields' => 'id'];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['X-Upload-Content-Type' => $this->mimeType, 'X-Upload-Content-Length' => (string) $this->size];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['name' => $this->name, 'parents' => [$this->folderId], 'mimeType' => $this->mimeType];
    }
}
