<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trust the load balancer or reverse proxy listed in `portal.trusted_proxies` (TRUSTED_PROXIES).
 *
 * Behind a proxy, `$request->ip()` is the proxy's address, which puts every user in one sign-in
 * throttle bucket, and `secure()` is false behind TLS termination, which keeps HSTS off.
 *
 * Read per request rather than in bootstrap/app.php: the `withMiddleware` callback runs when
 * the HTTP kernel is resolved, before the bootstrappers load `.env` and the config. There,
 * `env()` sees only a real process variable (never a value set in `.env`) and `config()` is not
 * bound yet. By the time this middleware runs both are loaded, cached config or not.
 *
 * Empty means no proxy: `proxies()` returns null and nothing is trusted, whatever the Host (see
 * `setTrustedProxyIpAddresses()`). Never `*` on an internet-facing host, since any client could
 * then forge its own address.
 */
class TrustProxies extends Middleware
{
    /**
     * The X-Forwarded-* headers trusted from a listed proxy. Narrower than the framework
     * default: X-Forwarded-Prefix and the AWS ELB variant stay untrusted.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * With no proxy configured the framework still trusts every address when the Host ends in
     * `.on-forge.com` or `.on-vapor.com` (or on Laravel Cloud). The client picks the Host, so that
     * would let it forge its own address; an empty setting has to mean no proxy at all.
     *
     * @return void
     */
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        if ($this->proxies() === null) {
            return;
        }

        parent::setTrustedProxyIpAddresses($request);
    }

    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('portal.trusted_proxies', '')),
        )));

        return parent::proxies() ?: ($configured === [] ? null : $configured);
    }
}
