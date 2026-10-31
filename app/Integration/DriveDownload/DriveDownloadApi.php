<?php

namespace App\Integration\DriveDownload;

use App\Integration\DriveDownload\Requests\DownloadFileRequest;
use GuzzleHttp\Exception\GuzzleException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Response;
use Throwable;

/**
 * The single door out to Google Drive for the counseling import (#565, AD-3): it reads the file id from a share link
 * and downloads that file the way anyone without an account would. Reached only from the import's CommandHandler.
 *
 * **Public files only, no credentials.** The portal holds no Google key or token (the Drive upload integration is
 * gone). A link is accepted only on `drive.google.com` or `docs.google.com`, and only for one file
 * (`/file/d/{id}`, `?id={id}`); a folder link is refused. The download is always the canonical
 * `https://drive.google.com/uc?export=download&id={id}`, never the URL the CSV gave, so nothing the CSV says chooses the
 * host or path the portal asks.
 *
 * **Redirects, followed by hand.** Drive answers that link with a redirect to its download host
 * (`drive.usercontent.google.com`, earlier `doc-…-docs.googleusercontent.com`), or, for a file that is not shared
 * publicly, to the sign-in page on `accounts.google.com`. The connector follows none; this class follows at most
 * {@see MAX_HOPS}, each only to an HTTPS URL on a host {@see isAllowedHost()} names. A redirect anywhere else (the
 * sign-in page included) means the file is not public.
 *
 * **A PDF or nothing.** The answer must be a 200 whose body starts with the PDF signature and is no larger than the
 * caller's cap. A web page (a sign-in form, Drive's "can't access" page, a virus-scan warning), a Google Doc or any
 * other file is "not publicly shared / not a PDF". The caller still runs its own full PDF checks on the bytes.
 *
 * Every failure leaves through {@see DriveDownloadFailed}.
 */
class DriveDownloadApi
{
    /** How many redirects one download may follow. Drive uses one. */
    public const MAX_HOPS = 4;

    /** The hosts a share link may name. */
    public const LINK_HOSTS = ['drive.google.com', 'docs.google.com'];

    /** Google's own download host, besides the link hosts, that a download may be redirected to. */
    private const DOWNLOAD_HOST = 'drive.usercontent.google.com';

    /** The older per-file download hosts (`doc-0s-bk-docs.googleusercontent.com`). */
    private const LEGACY_DOWNLOAD_HOST = '/^doc-[a-z0-9-]+\.googleusercontent\.com$/';

    /**
     * The file id a share link names: `https://drive.google.com/file/d/{id}/view`, `…/open?id={id}`, `…/uc?id={id}`, on
     * `drive.google.com` or `docs.google.com`.
     *
     * @throws DriveDownloadFailed a folder link, or not a link to one Drive file
     */
    public function fileIdFrom(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if (! in_array($parts['scheme'] ?? null, ['https', 'http'], true) || ! in_array($host, self::LINK_HOSTS, true) || isset($parts['user']) || isset($parts['port'])) {
            throw DriveDownloadFailed::notDriveLink();
        }

        if (preg_match('#/(folders|folderview|embeddedfolderview)(/|$)#', $path) === 1) {
            throw DriveDownloadFailed::folder();
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $id = preg_match('#^(?:/drive)?(?:/u/\d+)?/file/d/([^/]+)#', $path, $match) === 1 ? $match[1] : ($query['id'] ?? null);

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{10,200}$/', $id) !== 1) {
            throw DriveDownloadFailed::notDriveLink();
        }

        return $id;
    }

    /**
     * The bytes of the public file `$fileId`, a PDF of at most `$maxBytes`.
     *
     * @throws DriveDownloadFailed
     */
    public function download(string $fileId, int $maxBytes): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{10,200}$/', $fileId) !== 1) {
            throw DriveDownloadFailed::notDriveLink();
        }

        $connector = new DriveDownloadConnector($maxBytes);
        $url = 'https://drive.google.com/uc?export=download&id='.$fileId;

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $response = $this->send($connector, $url);

            if (! $response->redirect()) {
                return $this->pdf($response, $maxBytes);
            }

            $url = $this->nextHop($url, $response->header('Location'));
        }

        // Redirected round and round: not a plain public download.
        throw DriveDownloadFailed::notPublic();
    }

    /** Whether a download may go to `$host`: a link host, or Google's download host. */
    public static function isAllowedHost(string $host): bool
    {
        $host = strtolower($host);

        return in_array($host, self::LINK_HOSTS, true)
            || $host === self::DOWNLOAD_HOST
            || preg_match(self::LEGACY_DOWNLOAD_HOST, $host) === 1;
    }

    private function send(DriveDownloadConnector $connector, string $url): Response
    {
        try {
            return $connector->send(new DownloadFileRequest($url));
        } catch (DriveDownloadFailed $e) {
            throw $e;
        } catch (FatalRequestException|GuzzleException $e) {
            // A size cap tripped mid-transfer arrives wrapped by Guzzle and Saloon; anything else is a failed connection.
            throw $this->capCause($e) ?? DriveDownloadFailed::unreachable($e);
        }
    }

    /** The redirect target, only when it is HTTPS on an allowed host; anything else means the file is not public. */
    private function nextHop(string $from, mixed $location): string
    {
        if (! is_string($location) || $location === '') {
            throw DriveDownloadFailed::notPublic();
        }

        // A relative Location stays on the host it came from.
        if (str_starts_with($location, '/') && ! str_starts_with($location, '//')) {
            $origin = parse_url($from);
            $location = 'https://'.($origin['host'] ?? '').$location;
        }

        $parts = parse_url($location);

        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['port']) || ! self::isAllowedHost((string) ($parts['host'] ?? ''))) {
            throw DriveDownloadFailed::notPublic();
        }

        return $location;
    }

    private function pdf(Response $response, int $maxBytes): string
    {
        $status = $response->status();

        if ($status === 429 || $status >= 500) {
            throw DriveDownloadFailed::unreachable();
        }

        // Drive answers a file id it does not have (deleted, or a typo in the link) with a 404. A private file is
        // answered with a sign-in redirect or a refusal instead.
        if ($status === 404) {
            throw DriveDownloadFailed::notFound();
        }

        if ($status !== 200 || str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')) {
            throw DriveDownloadFailed::notPublic();
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw DriveDownloadFailed::tooLarge($maxBytes);
        }

        if (! str_starts_with($body, '%PDF-')) {
            throw DriveDownloadFailed::notPublic();
        }

        return $body;
    }

    private function capCause(Throwable $e): ?DriveDownloadFailed
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof DriveDownloadFailed) {
                return $cause;
            }
        }

        return null;
    }
}
