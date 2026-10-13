import { afterEach, describe, expect, it, vi } from 'vitest';
import { postJson } from '../postJson';

const respond = (status, body, type = 'application/json') =>
    vi.fn().mockResolvedValue(new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers: { 'content-type': type } }));

describe('postJson', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('sends JSON with the URL-decoded XSRF token and never follows a redirect', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D';
        const fetch = respond(200, { savedAt: 'x' });
        vi.stubGlobal('fetch', fetch);

        expect(await postJson('/rollcall/12', { week: '2026-10-03' })).toEqual({ ok: true, status: 200, data: { savedAt: 'x' } });
        const [, init] = fetch.mock.calls[0];
        expect(init.redirect).toBe('manual');
        expect(init.headers['X-XSRF-TOKEN']).toBe('abc==');
        expect(init.body).toBe('{"week":"2026-10-03"}');
    });

    it('reads a 200 that is not JSON (e.g. the login page) as a failure', async () => {
        vi.stubGlobal('fetch', respond(200, '<html>Sign in</html>', 'text/html'));

        expect((await postJson('/rollcall/12', {})).ok).toBe(false);
    });

    it('reads a redirect (an expired session) as a failure', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 0, headers: new Headers() }));

        expect(await postJson('/rollcall/12', {})).toEqual({ ok: false, status: 0, data: null });
    });

    it('keeps the JSON body of an error', async () => {
        vi.stubGlobal('fetch', respond(422, { message: 'Nope.' }));

        expect(await postJson('/rollcall/12', {})).toEqual({ ok: false, status: 422, data: { message: 'Nope.' } });
    });

    it.each([401, 419])('sends the user to sign in again after a %i, once the caller has the result', async (status) => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', respond(status, { message: 'Unauthenticated.' }));
        const onSignedOut = vi.fn();

        expect(await postJson('/rollcall/12', {}, { onSignedOut })).toEqual({ ok: false, status, data: { message: 'Unauthenticated.' } });
        expect(onSignedOut).not.toHaveBeenCalled();
        vi.runAllTimers();
        expect(onSignedOut).toHaveBeenCalledOnce();
        vi.useRealTimers();
    });

    it.each([403, 422, 500])('stays on the page after a %i', async (status) => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', respond(status, { message: 'Nope.' }));
        const onSignedOut = vi.fn();

        await postJson('/rollcall/12', {}, { onSignedOut });
        vi.runAllTimers();
        expect(onSignedOut).not.toHaveBeenCalled();
        vi.useRealTimers();
    });
});
