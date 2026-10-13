<?php

namespace App\Integration\Nightwatch;

use Saloon\Enums\Method;
use Saloon\Http\Auth\HeaderAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Saloon connector for Nightwatch's public stats API. The concrete outbound edge (AD-3) —
 * reached only from {@see NightwatchApi}.
 *
 * **GET-only, stats-only.** A boot hook refuses, before anything is sent, any method
 * other than GET and any path that is not strictly under the configured base (which ends in
 * `/api/public/stats`). The key is a read-only workspace key too, so this is the second lock.
 * The API key is the default `X-Api-Key` header so no request goes out unauthenticated by omission.
 */
class NightwatchConnector extends Connector
{
    use AcceptsJson, HasTimeout;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 15;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function defaultAuth(): HeaderAuthenticator
    {
        return new HeaderAuthenticator($this->apiKey, 'X-Api-Key');
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $this->guard($pendingRequest);
    }

    private function guard(PendingRequest $pending): void
    {
        if ($pending->getMethod() !== Method::GET) {
            throw new NightwatchRequestRefused('Nightwatch is read-only: only GET requests are allowed.');
        }

        $base = parse_url(rtrim($this->baseUrl, '/'));
        $target = parse_url($pending->getUri()->__toString());
        $basePath = $base['path'] ?? '';
        $targetPath = rawurldecode($target['path'] ?? '');

        $inside = ($target['scheme'] ?? null) === ($base['scheme'] ?? null)
            && ($target['host'] ?? null) === ($base['host'] ?? null)
            && ($target['port'] ?? null) === ($base['port'] ?? null)
            && str_starts_with($targetPath, $basePath.'/')
            && ! in_array('..', explode('/', $targetPath), true);

        if (! $inside || ! str_ends_with(rtrim($basePath, '/'), '/api/public/stats')) {
            throw new NightwatchRequestRefused('Nightwatch requests must target a path under /api/public/stats.');
        }
    }
}
