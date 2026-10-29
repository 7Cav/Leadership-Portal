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
// A hold only lasts while the leader stays on the step where they made it. Leaving the screen in any
// way sends every change at once, held ones included, instead of letting the hold's timer die with
// it: an Inertia visit away (after the leader agreed to leave), the screen unmounting (browser Back
// or Forward between steps rebuilds it from history), and the tab closing or reloading (`pagehide`,
// sent with `keepalive` so the browser finishes the request after the page is gone). The page
// commits held changes itself when the leader moves to another step.
//
// A week whose section report has been sent is locked (AD-12): the server answers any save for it
// with a 409 and `locked`. That refusal is final, so the autosave stops instead of retrying: it drops
// what is queued (it can never be saved), ignores later changes, and reports `locked` with the
// server's message, so the page can turn read-only and reload the frozen marks.
//
// What to tell the leader about a failed request (`{ status, data }` as `postJson` resolves).
export function messageFor(result) {
    // Session ends first: the framework's own wording for these ("Unauthenticated.", "CSRF token
    // mismatch.") means nothing to a leader, and `postJson` is already reloading the page.
    if (result.status === 0 || result.status === 401) return 'You were signed out. Sign in again, then retry.';
    if (result.status === 419) return 'Your session expired; reload the page and try again.';
    if (result.data?.message) return result.data.message;
    if (result.status === 403) return 'This roll call is read-only for you right now.';
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

// The save the last rollcall screen sent as it went away (`leave` below), so the screen that replaces it can wait for
// it before reading the marks back from the server: browser Back between steps unmounts one screen and mounts the
// next at once, and a read sent first could miss the marks still on their way.
let leaving = Promise.resolve(true);
export const whenLeft = () => leaving;

// `post` is injectable for tests; it resolves `{ ok, status, data }` like `postJson`. `nameOf`
// turns a trooper id into a name for messages. `onLeave` hears the troopers the server says are on
// leave this week (a leave synced after the page loaded), so the page can lock their rows. `onRejected`
// hears the troopers whose marks were refused for good, so the page can stop showing marks that never saved.
// `onLocked` runs once, when a save finds the week locked.
export function useRollcallAutosave({
    url,
    week,
    onSaved = () => {},
    onLeave = () => {},
    onRejected = () => {},
    onLocked = () => {},
    nameOf = (id) => `#${id}`,
    post = postJson,
    now = () => Date.now(),
}) {
    // trooperId → { status?, rollcall?, notBefore }
    const pending = new Map();
    const state = ref('idle'); // idle | saving | saved | error | locked
    const savedAt = ref(null);
    const error = ref(null);
    const pendingCount = ref(0);
    // Troopers whose marks the server refused for good (moved out of the section since the page
    // loaded): kept out of later batches, so they cannot block everyone else's saves.
    const rejected = ref([]);
    // Troopers the server says are on leave this week (3.4): their practice status comes from the
    // leave, so a practice mark for them is never sent again. Their signature still saves.
    const onLeaveIds = ref([]);
    // The server's reason once a save found the week locked (its report was sent); null until then.
    const lockedMessage = ref(null);
    const locked = computed(() => state.value === 'locked');
    let timer = null;
    let inFlight = null;
    // The batch on its way, until its answer is in.
    let sending = new Map();
    // The screen has gone (unmounted): a save it sent on the way out still finishes, but its answer
    // no longer reaches the page, which may already show another week or another page entirely.
    let gone = false;

    const sync = () => (pendingCount.value = pending.size);

    // Tell the page about an answer, unless the page has gone: `onLocked` reloads, which would
    // reload whatever page the leader moved on to.
    function notify(handler, value, name) {
        if (gone) return;
        try {
            handler(value);
        } catch (e) {
            console.error(`Rollcall ${name} handler failed`, e);
        }
    }

    const marksOf = (entries) =>
        entries.map(([trooperId, entry]) => ({
            trooper_id: trooperId,
            ...(entry.status !== undefined && { status: entry.status }),
            ...(entry.rollcall !== undefined && { rollcall: entry.rollcall }),
        }));

    // `keepalive` only when asked, so a plain save calls `post` exactly as before.
    const send = (body, keepalive) => (keepalive ? post(url, body, { keepalive: true }) : post(url, body));

    function schedule(delay = DEBOUNCE_MS) {
        if (gone) return;
        clearTimeout(timer);
        timer = setTimeout(() => flush(), delay);
    }

    function queue(trooperId, field, value, { holdMs = 0 } = {}) {
        if (locked.value || rejected.value.includes(trooperId)) return;
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

    // Whether a change to this field is not saved yet (queued, held, or on its way), so the screen
    // knows the leader's value is newer than anything the server answers in the meantime.
    const isPending = (trooperId, field) => pending.get(trooperId)?.[field] !== undefined || sending.get(trooperId)?.[field] !== undefined;

    async function flush({ includeHeld = false, keepalive = false } = {}) {
        clearTimeout(timer);
        if (inFlight) {
            await inFlight;

            return flush({ includeHeld, keepalive });
        }

        const at = now();
        const ready = [...pending].filter(([, entry]) => includeHeld || entry.notBefore <= at);
        if (!ready.length) {
            reschedule();

            return state.value !== 'error';
        }

        for (const [id] of ready) pending.delete(id);
        sync();

        const marks = marksOf(ready);

        state.value = 'saving';
        sending = new Map(ready);
        inFlight = send({ week, marks }, keepalive)
            .catch(unreachable)
            .then((result) => {
                const refused = result.status === 422 && Array.isArray(result.data?.rejected) ? new Set(result.data.rejected) : null;
                const leaveCovered = refused && result.data.reason === 'loa_covered';

                if (result.ok && result.data?.savedAt) {
                    state.value = 'saved';
                    savedAt.value = result.data.savedAt;
                    error.value = null;
                    notify(onSaved, result.data, 'onSaved');
                } else if (result.status === 409 && result.data?.locked) {
                    // Final: nothing queued for this week can ever save. Drop it all and stop.
                    pending.clear();
                    sync();
                    state.value = 'locked';
                    lockedMessage.value = messageFor(result);
                    error.value = null;
                    notify(onLocked, lockedMessage.value, 'onLocked');
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
                    notify(onLeave, [...refused], 'onLeave');
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
                    notify(onRejected, [...refused], 'onRejected');
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
            .finally(() => {
                inFlight = null;
                sending = new Map();
            });

        await inFlight;
        if (state.value === 'idle' && pending.size) return flush({ includeHeld, keepalive });
        // An explicit save (marking posted, confirming, showing a capture) also sends what changed
        // while its batch was in flight, so what follows it sees every mark on screen.
        if (includeHeld && state.value === 'saved' && pending.size) return flush({ includeHeld, keepalive });
        if (state.value !== 'error') reschedule();

        // A batch refused as locked was not saved either: a caller waiting on it (marking the
        // rollcall posted) must not go on as if it had been.
        return state.value !== 'error' && state.value !== 'locked';
    }

    function reschedule() {
        if (!pending.size) return;
        const next = Math.min(...[...pending.values()].map((entry) => entry.notBefore));
        schedule(Math.max(next - now(), DEBOUNCE_MS));
    }

    const retry = () => flush();
    const rejectedMessage = computed(() =>
        rejected.value.length
            ? `${rejected.value.map(nameOf).join(', ')} ${rejected.value.length === 1 ? 'is' : 'are'} no longer placed in this section, so ${rejected.value.length === 1 ? 'that mark was' : 'those marks were'} not saved. Their new section marks them now; if they still belong here, ask an Admin to check their placement.`
            : null,
    );
    const onLeaveMessage = computed(() =>
        onLeaveIds.value.length
            ? `${onLeaveIds.value.map(nameOf).join(', ')} ${onLeaveIds.value.length === 1 ? 'is' : 'are'} on LOA this week, so ${onLeaveIds.value.length === 1 ? 'their practice mark comes' : 'their practice marks come'} from the LOA and the one entered here was not saved. Signatures still save.`
            : null,
    );
    const hasUnsaved = computed(() => pendingCount.value > 0 || state.value === 'saving');

    // The screen is going away: send every change now, held ones included, so a hold never outlives
    // the screen that would have sent it. The request is `keepalive`, since the page itself may be
    // about to go too. Resolves as `flush` does. With nothing waiting there is nothing to send, but a
    // batch still on its way (a step change just before browser Back sent it) is waited for, so what
    // reads the marks next sees it.
    function leave() {
        if (locked.value) return Promise.resolve(false);
        if (!pending.size) return inFlight ? inFlight.then(() => state.value !== 'error' && state.value !== 'locked') : Promise.resolve(true);

        return flush({ includeHeld: true, keepalive: true });
    }

    // The tab is closing or reloading (`pagehide`; the leader already got past `beforeUnload`'s
    // question). Nothing after this event can wait for an answer, so whatever is still queued or
    // held goes in one `keepalive` request at once, even while an earlier batch is on its way:
    // waiting for that batch first would mean never sending this one.
    function sendNow() {
        if (locked.value || !pending.size) return;
        clearTimeout(timer);
        const marks = marksOf([...pending]);
        pending.clear();
        sync();
        send({ week, marks }, true).catch(unreachable);
    }

    // Leaving with unsaved changes asks first, for a reload or tab close and for an in-app visit.
    const beforeUnload = (event) => {
        if (!hasUnsaved.value) return;
        event.preventDefault();
        event.returnValue = '';
    };
    // Any Inertia visit away — a link, another week, or a form such as Sign out — prompts too, and
    // a leader who leaves anyway has their changes sent before the next page is asked for, so that
    // page reads them. Saves themselves are fetches, and step changes client-side visits; neither
    // reaches here. A partial reload (`only`/`except`, such as the capture retry's poll) refreshes
    // props on this page and keeps the queue, so it is not leaving: asking there would stall the poll.
    const visitGuard = (event) => {
        if (!hasUnsaved.value) return;
        const visit = event?.detail?.visit;
        if (visit?.only?.length || visit?.except?.length) return;

        const goAnyway = window.confirm('Some roll call changes are not saved yet. Leave anyway?');
        if (goAnyway) leave();

        return goAnyway;
    };
    // Back online after a dropped connection: send what is waiting.
    const online = () => state.value === 'error' && retry();
    let removeVisitGuard = () => {};
    onMounted(() => {
        window.addEventListener('beforeunload', beforeUnload);
        window.addEventListener('pagehide', sendNow);
        window.addEventListener('online', online);
        removeVisitGuard = router.on?.('before', visitGuard) ?? (() => {});
    });
    // Unmounting without a visit guard asking first: browser Back or Forward between the steps
    // (Inertia rebuilds the page from history) or to another page. The changes go now, not when
    // their timers would have fired, since those timers die with the screen.
    onBeforeUnmount(() => {
        leaving = leave();
        gone = true;
        clearTimeout(timer);
        window.removeEventListener('beforeunload', beforeUnload);
        window.removeEventListener('pagehide', sendNow);
        window.removeEventListener('online', online);
        removeVisitGuard();
    });

    return { queue, cancel, isPending, flush, leave, retry, state, savedAt, error, hasUnsaved, pendingCount, rejected, rejectedMessage, onLeaveIds, onLeaveMessage, locked, lockedMessage };
}
