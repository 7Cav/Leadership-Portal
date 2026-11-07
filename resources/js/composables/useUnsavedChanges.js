import { onBeforeUnmount, onMounted, unref } from 'vue';
import { router } from '@inertiajs/vue3';

export const LEAVE_MESSAGE = 'Leave this page? Your changes are not saved.';

// Asks before leaving a page while `dirty` (a ref, computed or getter) is true: a reload or tab close
// (`beforeunload`, where the browser shows its own wording) and any Inertia GET visit away. The page's
// own writes (its submit or autosave, non-GET visits) are never asked about.
export function useUnsavedChanges(dirty, { message = LEAVE_MESSAGE } = {}) {
    const isDirty = () => !!(typeof dirty === 'function' ? dirty() : unref(dirty));

    function onBeforeUnload(event) {
        if (!isDirty()) return;
        event.preventDefault();
        event.returnValue = '';
    }

    function onBefore(event) {
        const method = event?.detail?.visit?.method ?? 'get';
        if (method.toLowerCase() !== 'get' || !isDirty()) return;

        return window.confirm(message);
    }

    let stop = () => {};
    onMounted(() => {
        window.addEventListener('beforeunload', onBeforeUnload);
        stop = router.on?.('before', onBefore) ?? (() => {});
    });
    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        stop();
    });
}
