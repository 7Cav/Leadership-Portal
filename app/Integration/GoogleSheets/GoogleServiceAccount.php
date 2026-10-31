<?php

namespace App\Integration\GoogleSheets;

use App\Integration\GoogleSheets\Requests\RequestAccessTokenRequest;
use Throwable;

/**
 * The Google service account the reports Sheet push signs in as (AD-3). It signs a one-hour JWT with the account's key,
 * trades it for an access token at the fixed Google token endpoint ({@see GoogleOAuthConnector}) and hands the token to
 * {@see GoogleSheetsApi}, the only caller. It asks for the Sheets scope alone: the portal no longer copies anything to
 * Google Drive (#565).
 *
 * AD-23: the email and private key come from env through config (`GOOGLE_SERVICE_ACCOUNT_EMAIL`,
 * `GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY`), injected by the composition root, never the DB. Every failure leaves as
 * {@see GoogleSheetsUnavailable}, whose messages never carry the key or the token.
 */
class GoogleServiceAccount
{
    public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    public const TOKEN_URL = GoogleOAuthConnector::BASE_URL.'/token';

    /**
     * `?array`: `config('services.google_service_account')` is null if the block is missing, and a TypeError at
     * container resolution would fire before any caller's guard exists.
     *
     * @param  array{service_account_email?: string|null, private_key?: string|null}|null  $config
     */
    public function __construct(#[\SensitiveParameter] private readonly ?array $config = []) {}

    /** Whether the account's email and key are both set. */
    public function isConfigured(): bool
    {
        return $this->filled('service_account_email') && $this->filled('private_key');
    }

    /**
     * A one-hour access token for the Sheets scope.
     *
     * @throws GoogleSheetsUnavailable
     */
    public function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw GoogleSheetsUnavailable::misconfigured('GOOGLE_SERVICE_ACCOUNT_EMAIL and GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY must both be set');
        }

        $assertion = $this->assertion();

        try {
            $response = (new GoogleOAuthConnector)->send(new RequestAccessTokenRequest($assertion));
        } catch (GoogleSheetsUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw GoogleSheetsUnavailable::unreachable($e);
        }

        if ($response->failed()) {
            // Google's own error code (`invalid_grant` for a disabled key or a skewed clock) names no secret.
            try {
                $error = $response->json('error');
            } catch (Throwable) {
                $error = null;
            }

            $step = is_string($error) && preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? "token request ({$error})" : 'token request';

            throw GoogleSheetsUnavailable::httpError($response->status(), $step);
        }

        try {
            $token = $response->json('access_token');
        } catch (Throwable $e) {
            throw GoogleSheetsUnavailable::malformed('the token answer was not JSON', $e);
        }

        if (! is_string($token) || $token === '') {
            throw GoogleSheetsUnavailable::malformed('the token answer carried no access token');
        }

        return $token;
    }

    /**
     * The service account's signed claim (RFC 7523): issued by its email, for the Sheets scope, to the fixed token URL,
     * valid for an hour.
     */
    private function assertion(): string
    {
        $key = openssl_pkey_get_private($this->privateKey());

        if ($key === false) {
            throw GoogleSheetsUnavailable::misconfigured('GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY is not a readable private key');
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
            throw GoogleSheetsUnavailable::misconfigured('GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY could not sign');
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
