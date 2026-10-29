<?php

namespace App\Integration\GoogleDrive;

use App\Integration\GoogleDrive\Requests\RequestAccessTokenRequest;
use App\Integration\GoogleDrive\Requests\StartUploadRequest;
use App\Integration\GoogleDrive\Requests\UploadContentRequest;
use Saloon\Http\Connector;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Throwable;

/**
 * The single door out to the 7Cav Google Drive (#381, AD-3): it copies a counseling document into the one folder the
 * env names, and does nothing else. A CommandHandler (the queued upload) calls it; it owns both connectors, so no HTTP
 * client to Google exists anywhere else.
 *
 * Authenticates as a **service account** (AD-23: its email, private key and the folder id come from env through config,
 * injected by the composition root, never the DB): it signs a one-hour JWT with the key, trades it for an access token
 * at the fixed Google token endpoint ({@see GoogleOAuthConnector}), then opens a resumable upload and sends the bytes
 * ({@see GoogleDriveConnector}, upload-only). The scope asked for is `drive`, which a service account only ever uses on
 * what was shared with it: share the counseling folder, in a shared drive, and nothing else.
 *
 * Every failure leaves as {@see GoogleDriveUnavailable}, whose messages never carry the key, the token or the file.
 */
class GoogleDriveApi
{
    /** Drive for the counseling copy, Sheets for the weekly staff report rows (the GoogleSheets seam). */
    public const SCOPE = 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets';

    public const TOKEN_URL = GoogleOAuthConnector::BASE_URL.'/token';

    /**
     * `?array`: `config('services.google_drive')` is null if the block is missing, and a TypeError at container
     * resolution would fire before any caller's guard exists.
     *
     * @param  array{service_account_email?: string|null, private_key?: string|null, folder_id?: string|null}|null  $config
     */
    public function __construct(#[\SensitiveParameter] private readonly ?array $config = []) {}

    /**
     * Whether all three env keys are set. When they are not, the portal keeps documents to itself and the upload is
     * skipped, never failed.
     */
    public function isConfigured(): bool
    {
        return $this->filled('service_account_email') && $this->filled('private_key') && $this->filled('folder_id');
    }

    /**
     * Upload `$contents` as `$name` into the configured folder and return the new Drive file's id.
     *
     * @throws GoogleDriveUnavailable when Drive is not configured or does not take the file
     */
    public function upload(string $name, #[\SensitiveParameter] string $contents, string $mimeType = 'application/pdf'): string
    {
        if (! $this->isConfigured()) {
            throw GoogleDriveUnavailable::misconfigured('GOOGLE_DRIVE_SERVICE_ACCOUNT_EMAIL, GOOGLE_DRIVE_PRIVATE_KEY and GOOGLE_DRIVE_FOLDER_ID must all be set');
        }

        $token = $this->accessToken();
        $drive = new GoogleDriveConnector($token);

        $started = $this->send($drive, new StartUploadRequest($name, trim((string) $this->config['folder_id']), $mimeType, strlen($contents)), 'upload start');
        $uploadId = $this->uploadIdFrom($started);

        $finished = $this->send($drive, new UploadContentRequest($uploadId, $contents, $mimeType), 'upload');
        $id = $this->json($finished)['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            throw GoogleDriveUnavailable::malformed('the upload answer carried no file id');
        }

        return $id;
    }

    /** Whether the service account's email and key are set: what the Sheets push needs, without the Drive folder. */
    public function hasServiceAccount(): bool
    {
        return $this->filled('service_account_email') && $this->filled('private_key');
    }

    /**
     * A one-hour access token for the service account, for the Drive and Sheets scopes. Public for the Sheets seam
     * beside this one, which shares the service account; nothing outside Integration ever sees a token.
     *
     * @throws GoogleDriveUnavailable
     */
    public function accessToken(): string
    {
        if (! $this->hasServiceAccount()) {
            throw GoogleDriveUnavailable::misconfigured('GOOGLE_DRIVE_SERVICE_ACCOUNT_EMAIL and GOOGLE_DRIVE_PRIVATE_KEY must both be set');
        }

        $response = $this->send(new GoogleOAuthConnector, new RequestAccessTokenRequest($this->assertion()), 'token request');
        $token = $this->json($response)['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw GoogleDriveUnavailable::malformed('the token answer carried no access token');
        }

        return $token;
    }

    /**
     * The service account's signed claim (RFC 7523): issued by its email, for the Drive scope, to the fixed token URL,
     * valid for an hour.
     */
    private function assertion(): string
    {
        $key = openssl_pkey_get_private($this->privateKey());

        if ($key === false) {
            throw GoogleDriveUnavailable::misconfigured('GOOGLE_DRIVE_PRIVATE_KEY is not a readable private key');
        }

        $now = time();
        $segments = [
            self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            self::base64Url(json_encode([
                'iss' => trim((string) $this->config['service_account_email']),
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw GoogleDriveUnavailable::misconfigured('GOOGLE_DRIVE_PRIVATE_KEY could not sign');
        }

        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    /**
     * The key as the env holds it. A PEM pasted into a one-line env value usually carries `\n` for its line breaks,
     * as the service account's JSON key file writes it.
     */
    private function privateKey(): string
    {
        return str_replace('\n', "\n", trim((string) $this->config['private_key']));
    }

    /**
     * The `upload_id` of the resumable session Drive opened. Only a session on the Drive upload URL is accepted; the
     * request that follows is built from the id alone, on the upload-only connector, never from the URL as given.
     */
    private function uploadIdFrom(Response $response): string
    {
        $location = $response->header('Location');
        $location = is_array($location) ? ($location[0] ?? null) : $location;
        $parts = is_string($location) ? parse_url($location) : false;

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'www.googleapis.com'
            || ($parts['path'] ?? null) !== GoogleDriveConnector::UPLOAD_PATH) {
            throw GoogleDriveUnavailable::malformed('the upload start answer carried no upload session');
        }

        parse_str($parts['query'] ?? '', $query);
        $uploadId = $query['upload_id'] ?? null;

        if (! is_string($uploadId) || ! preg_match('/^[A-Za-z0-9_\-]{1,512}$/', $uploadId)) {
            throw GoogleDriveUnavailable::malformed('the upload session carried no usable upload id');
        }

        return $uploadId;
    }

    private function send(Connector $connector, Request $request, string $step): Response
    {
        try {
            $response = $connector->send($request);
        } catch (GoogleDriveUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw GoogleDriveUnavailable::unreachable($e);
        }

        if ($response->failed()) {
            throw GoogleDriveUnavailable::httpError($step, $response->status());
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        try {
            $payload = $response->json();
        } catch (Throwable) {
            throw GoogleDriveUnavailable::malformed('the body was not JSON');
        }

        return is_array($payload) ? $payload : throw GoogleDriveUnavailable::malformed('the body was not a JSON object');
    }

    private function filled(string $key): bool
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
