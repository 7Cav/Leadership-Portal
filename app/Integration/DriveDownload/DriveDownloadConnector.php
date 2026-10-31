<?php

namespace App\Integration\DriveDownload;

use Psr\Http\Message\ResponseInterface;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Saloon connector for a public Google Drive download (#565). The concrete outbound edge (AD-3), reached only from
 * {@see DriveDownloadApi}.
 *
 * **No credentials, GET only, Google's hosts only.** It sends no key, no cookie and no token: a file that needs any of
 * them is not public, and the import says so. A boot hook refuses, before anything is sent, any method but GET, any
 * scheme but HTTPS and any host {@see DriveDownloadApi::isAllowedHost()} does not name, so a URL that slipped past the
 * seam's own check still never leaves. Redirects are never followed by Guzzle: Drive answers its canonical download
 * link with a redirect to its download host, so the seam follows each hop itself, checking the next host first.
 *
 * **Size-capped while it downloads.** A `Content-Length` over `$maxBytes`, or more bytes than that arriving, aborts the
 * transfer, so a large file is never held in memory whole. The seam checks the body's size again.
 */
class DriveDownloadConnector extends Connector
{
    use HasTimeout;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 20;

    public function __construct(private readonly int $maxBytes) {}

    /** Empty: every request names its full URL, which the boot hook checks. */
    public function resolveBaseUrl(): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        $max = $this->maxBytes;

        return [
            'allow_redirects' => false,
            'on_headers' => function (ResponseInterface $response) use ($max): void {
                $length = $response->getHeaderLine('Content-Length');

                if ($length !== '' && ctype_digit($length) && (int) $length > $max) {
                    throw DriveDownloadFailed::tooLarge($max);
                }
            },
            'progress' => function (int|float $total, int|float $downloaded) use ($max): void {
                if ($downloaded > $max) {
                    throw DriveDownloadFailed::tooLarge($max);
                }
            },
        ];
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $uri = $pendingRequest->getUri();

        if ($pendingRequest->getMethod() !== Method::GET
            || $uri->getScheme() !== 'https'
            || $uri->getUserInfo() !== ''
            || $uri->getPort() !== null
            || ! DriveDownloadApi::isAllowedHost($uri->getHost())) {
            throw DriveDownloadFailed::notPublic();
        }
    }
}
