import { onBeforeUnmount, onMounted, unref } from 'vue';
import { router } from '@inertiajs/vue3';

export const LEAVE_MESSAGE = 'Leave this page? Your changes are not saved.';

// Asks before leaving a page while `dirty` (a ref, computed or getter) is true: a reload or tab close
// (`beforeunload`, where the browser shows its own wording) and any Inertia GET visit away. The page's
// own writes (its submit or autosave, non-GET visits) and partial reloads, which stay on the page (the
// tutorial's checklist reading its prop again), are never asked about.
export function useUnsavedChanges(dirty, { message = LEAVE_MESSAGE } = {}) {
    const isDirty = () => !!(typeof dirty === 'function' ? dirty() : unref(dirty));

    function onBeforeUnload(event) {
        if (!isDirty()) return;
        event.preventDefault();
        event.returnValue = '';
    }

    function onBefore(event) {
        const visit = event?.detail?.visit ?? {};
        const method = visit.method ?? 'get';
        const partial = (visit.only?.length ?? 0) > 0 || (visit.except?.length ?? 0) > 0;
        if (method.toLowerCase() !== 'get' || partial || !isDirty()) return;

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
