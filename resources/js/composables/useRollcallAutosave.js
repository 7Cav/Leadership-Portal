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
// What to tell the leader about a failed request (`{ status, data }` as `postJson` resolves).
export function messageFor(result) {
    if (result.data?.message) return result.data.message;
    if (result.status === 0) return 'You were signed out. Sign in again, then retry.';
    if (result.status === 419) return 'Your session expired. Reload the page to keep going.';
    if (result.status === 403) return 'This rollcall is read-only for you right now.';
    if (result.status >= 500) return 'The portal hit an error. Your changes are kept; retry in a moment.';

    return 'The server did not accept the change.';
}

// A rejected promise from `post` is a network failure (or a bug): log it, never swallow it.
export function unreachable(error) {
    console.error('Rollcall request failed', error);
    const offline = typeof navigator !== 'undefined' && navigator.onLine === false;

    return {
        ok: false,
        status: -1,
        data: { message: offline ? 'You are offline. Your changes are kept; they save when you are back online.' : 'Could not reach the portal. Your changes are kept; retry in a moment.' },
    };
}

// `post` is injectable for tests; it resolves `{ ok, status, data }` like `postJson`. `nameOf`
// turns a trooper id into a name for messages. `onLeave` hears the troopers the server says are on
// leave this week (a leave synced after the page loaded), so the page can lock their rows. `onRejected`
// hears the troopers whose marks were refused for good, so the page can stop showing marks that never saved.
export function useRollcallAutosave({
    url,
    week,
    onSaved = () => {},
    onLeave = () => {},
    onRejected = () => {},
    nameOf = (id) => `#${id}`,
    post = postJson,
    now = () => Date.now(),
}) {
    // trooperId → { status?, rollcall?, notBefore }
    const pending = new Map();
    const state = ref('idle'); // idle | saving | saved | error
    const savedAt = ref(null);
    const error = ref(null);
    const pendingCount = ref(0);
    // Troopers whose marks the server refused for good (moved out of the section since the page
    // loaded): kept out of later batches, so they cannot block everyone else's saves.
    const rejected = ref([]);
    // Troopers the server says are on leave this week (3.4): their practice status comes from the
    // leave, so a practice mark for them is never sent again. Their signature still saves.
    const onLeaveIds = ref([]);
    let timer = null;
    let inFlight = null;

    const sync = () => (pendingCount.value = pending.size);

    function schedule(delay = DEBOUNCE_MS) {
        clearTimeout(timer);
        timer = setTimeout(() => flush(), delay);
    }

    function queue(trooperId, field, value, { holdMs = 0 } = {}) {
        if (rejected.value.includes(trooperId)) return;
        if (field === 'status' && onLeaveIds.value.includes(trooperId)) return;
        const entry = pending.get(trooperId) ?? { notBefore: 0 };
        entry[field] = value;
        // A fresh change by hand releases any hold: the leader has decided.
        entry.notBefore = holdMs ? Math.max(entry.notBefore, now() + holdMs) : 0;
        pending.set(trooperId, entry);
        sync();
        schedule();
    }

    // Take queued (not yet sent) changes back — Undo during a hold. Returns the ids actually taken
    // back: a change already sent (e.g. flushed early to mark the rollcall posted) cannot be.
    function cancel(trooperIds, field) {
        const cancelled = [];
        for (const id of trooperIds) {
            const entry = pending.get(id);
            if (!entry || entry[field] === undefined) continue;
            delete entry[field];
            cancelled.push(id);
            if (entry.status === undefined && entry.rollcall === undefined) pending.delete(id);
        }
        sync();

        return cancelled;
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
            .catch(unreachable)
            .then((result) => {
                const refused = result.status === 422 && Array.isArray(result.data?.rejected) ? new Set(result.data.rejected) : null;
                const leaveCovered = refused && result.data.reason === 'loa_covered';

                if (result.ok && result.data?.savedAt) {
                    state.value = 'saved';
                    savedAt.value = result.data.savedAt;
                    error.value = null;
                    try {
                        onSaved(result.data);
                    } catch (e) {
                        console.error('Rollcall onSaved handler failed', e);
                    }
                } else if (leaveCovered && refused.size) {
                    // On leave this week: drop just their practice marks, keep their signatures, and
                    // send the batch again at once. The Command refuses only the practice mark.
                    onLeaveIds.value = [...new Set([...onLeaveIds.value, ...refused])];
                    for (const [id, entry] of ready) {
                        const merged = { ...entry, notBefore: 0, ...(pending.get(id) ?? {}) };
                        if (refused.has(id)) delete merged.status;
                        if (merged.status === undefined && merged.rollcall === undefined) pending.delete(id);
                        else pending.set(id, merged);
                    }
                    sync();
                    state.value = 'idle';
                    try {
                        onLeave([...refused]);
                    } catch (e) {
                        console.error('Rollcall onLeave handler failed', e);
                    }
                } else if (refused?.size) {
                    // Drop the refused troopers for good and send everyone else again at once.
                    rejected.value = [...new Set([...rejected.value, ...refused])];
                    for (const [id, entry] of ready) {
                        if (refused.has(id)) continue;
                        const newer = pending.get(id) ?? {};
                        pending.set(id, { ...entry, notBefore: 0, ...newer });
                    }
                    for (const id of refused) pending.delete(id);
                    sync();
                    state.value = 'idle';
                    try {
                        onRejected([...refused]);
                    } catch (e) {
                        console.error('Rollcall onRejected handler failed', e);
                    }
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
        if (state.value === 'idle' && pending.size) return flush({ includeHeld });
        if (state.value !== 'error') reschedule();

        return state.value !== 'error';
    }

    function reschedule() {
        if (!pending.size) return;
        const next = Math.min(...[...pending.values()].map((entry) => entry.notBefore));
        schedule(Math.max(next - now(), DEBOUNCE_MS));
    }

    const retry = () => flush();
    const rejectedMessage = computed(() =>
        rejected.value.length
            ? `${rejected.value.map(nameOf).join(', ')} ${rejected.value.length === 1 ? 'is' : 'are'} no longer placed in this section, so ${rejected.value.length === 1 ? 'that mark was' : 'those marks were'} not saved. Reload to refresh the roster.`
            : null,
    );
    const onLeaveMessage = computed(() =>
        onLeaveIds.value.length
            ? `${onLeaveIds.value.map(nameOf).join(', ')} ${onLeaveIds.value.length === 1 ? 'is' : 'are'} on leave this week, so ${onLeaveIds.value.length === 1 ? 'their practice mark comes' : 'their practice marks come'} from the leave and the one entered here was not saved. Signatures still save.`
            : null,
    );
    const hasUnsaved = computed(() => pendingCount.value > 0 || state.value === 'saving');

    // Leaving with unsaved changes asks first, for a reload or tab close and for an in-app visit.
    const beforeUnload = (event) => {
        if (!hasUnsaved.value) return;
        event.preventDefault();
        event.returnValue = '';
    };
    // Any Inertia visit away — a link, another week, or a form such as Sign out — prompts too.
    // Saves themselves are fetches, and step changes client-side visits; neither reaches here.
    const visitGuard = () => {
        if (!hasUnsaved.value) return;

        return window.confirm('Some rollcall changes are not saved yet. Leave anyway?');
    };
    // Back online after a dropped connection: send what is waiting.
    const online = () => state.value === 'error' && retry();
    let removeVisitGuard = () => {};
    onMounted(() => {
        window.addEventListener('beforeunload', beforeUnload);
        window.addEventListener('online', online);
        removeVisitGuard = router.on?.('before', visitGuard) ?? (() => {});
    });
    onBeforeUnmount(() => {
        clearTimeout(timer);
        window.removeEventListener('beforeunload', beforeUnload);
        window.removeEventListener('online', online);
        removeVisitGuard();
    });

    return { queue, cancel, flush, retry, state, savedAt, error, hasUnsaved, pendingCount, rejected, rejectedMessage, onLeaveIds, onLeaveMessage };
}
