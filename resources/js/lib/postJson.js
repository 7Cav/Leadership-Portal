// A same-origin JSON POST for background saves that must not navigate (the rollcall autosave,
// 3.8). Laravel's CSRF check reads the `XSRF-TOKEN` cookie back from the `X-XSRF-TOKEN` header.
// The `Accept` header makes the server answer errors as JSON, an ended session included (401, or
// 419 for an expired CSRF token). Redirects are still not followed, so any other redirect reads
// as a failure rather than a 200 from the page it points at.
function xsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

// A 401 or 419 means the session behind this page is gone or its token is stale. Reloading lets
// the server decide: a signed-out user lands on sign-in with this page as the place to return to,
// and a still-live session just picks up a fresh token. A screen holding unsaved changes asks
// before the page goes (`beforeunload`), so nothing is dropped without the user's say. Other JSON
// fetches (the trooper search) use these two as well, so every one of them ends a session the same way.
export function sessionEnded(status) {
    return status === 401 || status === 419;
}

export function reloadPage() {
    window.location.reload();
}

export async function postJson(url, body, { onSignedOut = reloadPage } = {}) {
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
        data = await response.json().catch((error) => {
            console.warn(`Unreadable JSON from ${url} (HTTP ${response.status})`, error);

            return null;
        });
    }

    if (sessionEnded(response.status)) {
        // After the caller has had the result, so it can keep what it was sending first.
        setTimeout(onSignedOut, 0);
    }

    return { ok: response.ok && data !== null, status: response.status, data };
}
