<?php

namespace App\Integration\DriveDownload\Requests;

use App\Integration\DriveDownload\DriveDownloadApi;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * One hop of a public Drive download (#565): a plain `GET` of `$url`, which is either the canonical
 * `https://drive.google.com/uc?export=download&id=…` or a redirect target {@see DriveDownloadApi}
 * has already checked. The connector checks it again before it is sent.
 */
class DownloadFileRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $url) {}

    public function resolveEndpoint(): string
    {
        return $this->url;
    }
}
