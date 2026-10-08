import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { postJson } from '@/lib/postJson';

export const DEBOUNCE_MS = 700;

// Autosave for the rollcall screen (3.8): every change is queued per trooper and sent in one
// debounced batch to `rollcall.store`, which writes it through the audited MarkAttendance Command.
// A failed save keeps every queued change — nothing the leader entered is dropped — and the next
// change, or Retry, sends it again. A change can be *held* for a while (the undo window of "Mark
// all present"): it is not sent until the hold expires, so Undo can still take it back, because a
// saved practice mark cannot be cleared again (3.3).
//
// `post` is injectable for tests; it resolves `{ ok, status, data }` like `postJson`.
export function useRollcallAutosave({ url, week, onSaved = () => {}, post = postJson, now = () => Date.now() }) {
    // trooperId → { status?, rollcall?, notBefore }
    const pending = new Map();
    const state = ref('idle'); // idle | saving | saved | error
    const savedAt = ref(null);
    const error = ref(null);
    const pendingCount = ref(0);
    let timer = null;
    let inFlight = null;

    const sync = () => (pendingCount.value = pending.size);

    function schedule(delay = DEBOUNCE_MS) {
        clearTimeout(timer);
        timer = setTimeout(() => flush(), delay);
    }

    function queue(trooperId, field, value, { holdMs = 0 } = {}) {
        const entry = pending.get(trooperId) ?? { notBefore: 0 };
        entry[field] = value;
        // A fresh change by hand releases any hold: the leader has decided.
        entry.notBefore = holdMs ? Math.max(entry.notBefore, now() + holdMs) : 0;
        pending.set(trooperId, entry);
        sync();
        schedule();
    }

    // Take queued (not yet sent) changes back — Undo during a hold.
    function cancel(trooperIds, field) {
        for (const id of trooperIds) {
            const entry = pending.get(id);
            if (!entry) continue;
            delete entry[field];
            if (entry.status === undefined && entry.rollcall === undefined) pending.delete(id);
        }
        sync();
    }

    function messageFor(result) {
        if (result.data?.message) return result.data.message;
        if (result.status === 0) return 'You were signed out. Sign in again, then retry.';
        if (result.status === 419) return 'Your session expired. Reload the page to keep going.';
        if (result.status === 403) return 'This rollcall is read-only for you right now.';

        return 'The server did not accept the change.';
    }

    async function flush({ includeHeld = false } = {}) {
        clearTimeout(timer);
        if (inFlight) {
            await inFlight;

            return flush({ includeHeld });
        }

        const at = now();
        const ready = [...pending].filter(([, entry]) => includeHeld || entry.notBefore <= at);
        if (!ready.length) {
            reschedule();

            return state.value !== 'error';
        }

        for (const [id] of ready) pending.delete(id);
        sync();

        const marks = ready.map(([trooperId, entry]) => ({
            trooper_id: trooperId,
            ...(entry.status !== undefined && { status: entry.status }),
            ...(entry.rollcall !== undefined && { rollcall: entry.rollcall }),
        }));

        state.value = 'saving';
        inFlight = post(url, { week, marks })
            .catch(() => ({ ok: false, status: -1, data: { message: 'No connection to the portal. Your changes are kept; retry when you are back online.' } }))
            .then((result) => {
                if (result.ok && result.data?.savedAt) {
                    state.value = 'saved';
                    savedAt.value = result.data.savedAt;
                    error.value = null;
                    onSaved(result.data);
                } else {
                    // Put the batch back, under anything changed since it was sent.
                    for (const [id, entry] of ready) {
                        const newer = pending.get(id) ?? {};
                        pending.set(id, { ...entry, notBefore: 0, ...newer });
                    }
                    sync();
                    state.value = 'error';
                    error.value = messageFor(result);
                }
            })
            .finally(() => (inFlight = null));

        await inFlight;
        if (state.value !== 'error') reschedule();

        return state.value !== 'error';
    }

    function reschedule() {
        if (!pending.size) return;
        const next = Math.min(...[...pending.values()].map((entry) => entry.notBefore));
        schedule(Math.max(next - now(), DEBOUNCE_MS));
    }

    const retry = () => flush();
    const hasUnsaved = computed(() => pendingCount.value > 0 || state.value === 'saving');

    // Leaving with unsaved changes asks first, for a reload or tab close and for an in-app visit.
    const beforeUnload = (event) => {
        if (!hasUnsaved.value) return;
        event.preventDefault();
        event.returnValue = '';
    };
    let removeVisitGuard = () => {};
    onMounted(() => {
        window.addEventListener('beforeunload', beforeUnload);
        removeVisitGuard = router.on?.('before', (event) => {
            if (event.detail?.visit?.method !== 'get' || !hasUnsaved.value) return;

            return window.confirm('Some rollcall changes are not saved yet. Leave anyway?');
        }) ?? (() => {});
    });
    onBeforeUnmount(() => {
        clearTimeout(timer);
        window.removeEventListener('beforeunload', beforeUnload);
        removeVisitGuard();
    });

    return { queue, cancel, flush, retry, state, savedAt, error, hasUnsaved, pendingCount };
}
