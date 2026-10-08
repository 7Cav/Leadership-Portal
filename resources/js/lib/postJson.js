// A same-origin JSON POST for background saves that must not navigate (the rollcall autosave,
// 3.8). Laravel's CSRF check reads the `XSRF-TOKEN` cookie back from the `X-XSRF-TOKEN` header.
// Redirects are not followed: this app answers JSON errors only on api/*, so an expired session
// comes back as a redirect to the login page, which must read as a failure, not a 200.
function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        redirect: 'manual',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });

    let data = null;
    if ((response.headers.get('content-type') ?? '').includes('application/json')) {
        data = await response.json().catch(() => null);
    }

    return { ok: response.ok && data !== null, status: response.status, data };
}
