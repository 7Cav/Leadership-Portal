<?php

namespace App\Integration\GoogleDrive\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasStringBody;

/**
 * Sends the whole file to the resumable session {@see StartUploadRequest} opened, in one request:
 * `PUT /upload/drive/v3/files?uploadType=resumable&upload_id=…`. Drive answers with the new file's id.
 */
class UploadContentRequest extends Request implements HasBody
{
    use HasStringBody;

    protected Method $method = Method::PUT;

    public function __construct(
        private readonly string $uploadId,
        private readonly string $contents,
        private readonly string $mimeType,
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
        return ['uploadType' => 'resumable', 'upload_id' => $this->uploadId, 'supportsAllDrives' => 'true', 'fields' => 'id'];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => $this->mimeType];
    }

    protected function defaultBody(): string
    {
        return $this->contents;
    }
}
