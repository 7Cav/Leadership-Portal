// A request the server refused or could not finish (an HTTP exception: 403, 419, 429, 5xx), as against a
// validation error, which lands on the form's own fields. `failed` names what did not happen ("The template was
// not saved"); the sentence after it says what to do.
export function httpErrorMessage(response, failed) {
    const status = response?.status;

    if (status === 403) return `${failed}. You are not allowed to do that; reload the page, or exit view as first.`;
    if (status === 419) return `${failed}. Your session expired; reload the page and try again.`;
    if (status === 429) return `${failed}. Too many requests; wait a minute and try again.`;

    return `${failed} (error ${status ?? 'unknown'}). Try again.`;
}

// The `onHttpException` option for a form submit or visit: it hands the message to `show` and returns false, so
// Inertia does not put its raw error dialog over the page.
export function onHttpException(failed, show) {
    return (response) => {
        show(httpErrorMessage(response, failed));

        return false;
    };
}

// The `onHttpException` option for starting or stopping view as or act as. The switch itself succeeded when the page it
// lands on comes back as the blocked page (the previewed or acted-as identity may not open that page, so the server
// refuses its data and sends the shell only): that page is rendered and the visit counts as done. Any other refusal is
// a failure, as with `onHttpException`.
export function onPreviewHttpException(failed, show) {
    const fail = onHttpException(failed, show);

    return (response) => (response?.data?.component === 'Blocked' ? true : fail(response));
}
