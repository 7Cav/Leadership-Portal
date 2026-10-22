import { toast } from 'vue-sonner';

const BLOCKED = 'The browser blocked clipboard access. Allow it for this site and try again.';
const UNAVAILABLE = 'This browser cannot copy from this page.';

// Puts text on the clipboard and confirms it the same way on every copy button: a success toast
// titled `label`, or an error toast saying why it failed. `description` goes under the success
// toast; `failed` titles the error toast and `fallback` tells the user what to do instead when the
// browser has no clipboard here at all. Resolves true when the text was copied.
export async function copyText(text, label = 'Copied', { description, failed = 'Could not copy', fallback } = {}) {
    try {
        // Absent outside a secure context (plain HTTP): that throws into the same error path.
        await navigator.clipboard.writeText(text);
    } catch (error) {
        console.error('Copy to clipboard failed', error);
        toast.error(failed, {
            description: error?.name === 'NotAllowedError' ? BLOCKED : fallback ? `${UNAVAILABLE} ${fallback}` : UNAVAILABLE,
        });

        return false;
    }

    toast.success(label, { description });

    return true;
}
