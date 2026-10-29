import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import { DEBOUNCE_MS, messageFor, useRollcallAutosave, whenLeft } from '../useRollcallAutosave';

let visitGuard = null;
vi.mock('@inertiajs/vue3', () => ({ router: { on: (event, callback) => ((visitGuard = callback), () => (visitGuard = null)) } }));

// The autosave, plus `unmount` for the screen going away.
function setup(post) {
    let api;
    const wrapper = mount(defineComponent({ setup: () => ((api = useRollcallAutosave({ url: '/rollcall/12', week: '2026-10-03', post, onSaved, onLeave, onLocked, nameOf: (id) => `Trooper${id}.A` })), () => h('div')) }));

    return Object.assign(api, { unmount: () => wrapper.unmount() });
}

enableAutoUnmount(afterEach);

const onSaved = vi.fn();
const onLeave = vi.fn();
const onLocked = vi.fn();
const ok = (data = {}) => Promise.resolve({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [], ...data } });

describe('rollcall autosave', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        onSaved.mockClear();
        onLeave.mockClear();
        onLocked.mockClear();
    });
    afterEach(() => vi.useRealTimers());

    it('debounces changes into one batch per save, both facts per trooper', async () => {
        const post = vi.fn(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        save.queue(1, 'rollcall', 'will_attend');
        save.queue(2, 'status', 'missed');
        expect(post).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/rollcall/12', {
            week: '2026-10-03',
            marks: [
                { trooper_id: 1, status: 'present', rollcall: 'will_attend' },
                { trooper_id: 2, status: 'missed' },
            ],
        });
        expect(save.state.value).toBe('saved');
        expect(save.hasUnsaved.value).toBe(false);
        expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ savedAt: '2026-10-08T16:20:00+00:00' }));
    });

    it('keeps every change on a failed save and sends it again on retry', async () => {
        const post = vi.fn().mockResolvedValueOnce({ ok: false, status: 500, data: null }).mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);

        expect(save.state.value).toBe('error');
        expect(save.error.value).toBe('The portal hit an error. Your changes are kept; retry in a moment.');
        expect(save.hasUnsaved.value).toBe(true);

        await save.retry();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 1, status: 'present' }] });
        expect(save.state.value).toBe('saved');
    });

    it('lets a newer change win over a failed batch it is merged back under', async () => {
        let fail;
        const post = vi.fn().mockReturnValueOnce(new Promise((resolve) => (fail = resolve))).mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        save.queue(1, 'status', 'missed');
        fail({ ok: false, status: 0, data: null });
        await vi.advanceTimersByTimeAsync(0);

        expect(save.error.value).toBe('You were signed out. Sign in again, then retry.');
        await save.retry();
        expect(post).toHaveBeenLastCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 1, status: 'missed' }] });
    });

    it('treats a network failure as a failed save, not a lost one', async () => {
        const save = setup(vi.fn(() => Promise.reject(new Error('offline'))));

        save.queue(1, 'rollcall', 'cannot_attend');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);

        expect(save.state.value).toBe('error');
        expect(save.hasUnsaved.value).toBe(true);
    });

    it('holds a change for its undo window, and cancel takes it back unsent', async () => {
        const post = vi.fn(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        save.queue(2, 'status', 'present', { holdMs: 6000 });
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(post).not.toHaveBeenCalled();

        expect(save.cancel([1, 9], 'status')).toEqual([1]);
        await vi.advanceTimersByTimeAsync(6000);

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'present' }] });
    });

    it('releases a hold when the same mark is changed by hand', async () => {
        const post = vi.fn(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        save.queue(1, 'status', 'missed');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);

        expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 1, status: 'missed' }] });
    });

    it('flushes held changes on demand', async () => {
        const post = vi.fn(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        expect(await save.flush({ includeHeld: true })).toBe(true);
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('drops troopers the server refused for good, saves everyone else, and names them', async () => {
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'not placed', rejected: [2] } })
            .mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        save.queue(2, 'status', 'present');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);

        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenLastCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 1, status: 'present' }] });
        expect(save.state.value).toBe('saved');
        expect(save.rejectedMessage.value).toBe('Trooper2.A is no longer placed in this section, so that mark was not saved. Their new section marks them now; if they still belong here, ask an Admin to check their placement.');

        save.queue(2, 'status', 'missed');
        expect(save.pendingCount.value).toBe(0);
    });

    it('keeps the signature of a trooper the server says is on leave, drops only their practice mark, and says why', async () => {
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'on leave', rejected: [2], reason: 'loa_covered' } })
            .mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        save.queue(2, 'status', 'present');
        save.queue(2, 'rollcall', 'cannot_attend');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);

        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenLastCalledWith('/rollcall/12', {
            week: '2026-10-03',
            marks: [{ trooper_id: 1, status: 'present' }, { trooper_id: 2, rollcall: 'cannot_attend' }],
        });
        expect(save.state.value).toBe('saved');
        expect(onLeave).toHaveBeenCalledWith([2]);
        expect(save.rejectedMessage.value).toBeNull();
        expect(save.onLeaveMessage.value).toBe(
            'Trooper2.A is on LOA this week, so their practice mark comes from the LOA and the one entered here was not saved. Signatures still save.',
        );

        // Later: a practice mark for them is never queued again; a signature still is.
        save.queue(2, 'status', 'missed');
        expect(save.pendingCount.value).toBe(0);
        save.queue(2, 'rollcall', 'will_attend');
        expect(save.pendingCount.value).toBe(1);
    });

    it('drops a trooper on leave whose batch entry was only a practice mark', async () => {
        const post = vi.fn()
            .mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'on leave', rejected: [2], reason: 'loa_covered' } })
            .mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'rollcall', 'will_attend');
        save.queue(2, 'status', 'present');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);

        expect(post).toHaveBeenLastCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 1, rollcall: 'will_attend' }] });
        expect(save.pendingCount.value).toBe(0);
    });

    it('stops for good when the week is locked: drops the queue, ignores later changes, never retries (AD-12)', async () => {
        const message = 'The report for this week has been sent, so its rollcall is locked.';
        const post = vi.fn().mockResolvedValue({ ok: false, status: 409, data: { message, locked: true } });
        const save = setup(post);

        save.queue(1, 'status', 'present');
        save.queue(2, 'rollcall', 'will_attend', { holdMs: 6000 });
        expect(await save.flush()).toBe(false);

        expect(save.state.value).toBe('locked');
        expect(save.locked.value).toBe(true);
        expect(save.lockedMessage.value).toBe(message);
        expect(save.error.value).toBeNull();
        expect(save.hasUnsaved.value).toBe(false);
        expect(onLocked).toHaveBeenCalledTimes(1);

        save.queue(1, 'status', 'missed');
        expect(save.pendingCount.value).toBe(0);
        await vi.advanceTimersByTimeAsync(10000);
        expect(post).toHaveBeenCalledTimes(1);
        expect(await save.flush({ includeHeld: true })).toBe(true);
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('cannot take back a held change once it was sent early', async () => {
        const save = setup(vi.fn(() => ok()));

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        await save.flush({ includeHeld: true });

        expect(save.cancel([1], 'status')).toEqual([]);
    });

    it('sends a change made while a save is in flight in the next batch', async () => {
        let finish;
        const post = vi.fn().mockReturnValueOnce(new Promise((resolve) => (finish = resolve))).mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        save.queue(2, 'rollcall', 'will_attend');
        finish({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00' } });
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);

        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenLastCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, rollcall: 'will_attend' }] });
    });

    it('guards leaving the page while changes are unsaved, and only then', async () => {
        const save = setup(vi.fn(() => ok()));
        const confirm = vi.fn().mockReturnValue(false);
        vi.stubGlobal('confirm', confirm);

        const quiet = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(quiet);
        expect(quiet.defaultPrevented).toBe(false);
        expect(visitGuard({ detail: { visit: { method: 'get' } } })).toBeUndefined();

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        const leaving = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(leaving);
        expect(leaving.defaultPrevented).toBe(true);
        expect(visitGuard({ detail: { visit: { method: 'post' } } })).toBe(false);
        expect(confirm).toHaveBeenCalledWith('Some rollcall changes are not saved yet. Leave anyway?');
        vi.unstubAllGlobals();
    });

    it('sends queued and held changes at once, with keepalive, when the leader leaves anyway', async () => {
        const post = vi.fn(() => ok());
        const save = setup(post);
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        save.queue(2, 'rollcall', 'will_attend');
        expect(visitGuard({ detail: { visit: { method: 'get', only: [], except: [] } } })).toBe(true);

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith(
            '/rollcall/12',
            { week: '2026-10-03', marks: [{ trooper_id: 1, status: 'present' }, { trooper_id: 2, rollcall: 'will_attend' }] },
            { keepalive: true },
        );
        vi.unstubAllGlobals();
    });

    it('sends held changes when the screen unmounts instead of dropping them with their timer', async () => {
        const post = vi.fn(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        save.unmount();

        expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 1, status: 'present' }] }, { keepalive: true });
        await vi.advanceTimersByTimeAsync(7000);
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('sends what is left after the batch on its way when the screen unmounts mid-save', async () => {
        let answer;
        const post = vi.fn().mockReturnValueOnce(new Promise((resolve) => (answer = resolve))).mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        save.queue(2, 'status', 'missed', { holdMs: 6000 });
        save.unmount();
        expect(post).toHaveBeenCalledTimes(1);

        answer({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00' } });
        await vi.advanceTimersByTimeAsync(0);
        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenLastCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'missed' }] }, { keepalive: true });
    });

    it('lets the screen that replaces it wait for the save it sent on the way out', async () => {
        let answer;
        const post = vi.fn().mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
        const save = setup(post);
        let left = false;

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        save.unmount();
        whenLeft().then(() => (left = true));
        await vi.advanceTimersByTimeAsync(0);
        expect(left).toBe(false);

        answer({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00' } });
        await vi.advanceTimersByTimeAsync(0);
        expect(left).toBe(true);
    });

    it('also has the next screen wait for a batch already on its way when the screen went', async () => {
        let answer;
        const post = vi.fn().mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
        const save = setup(post);
        let left = false;

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        // A step change sends the held mark, and browser Back unmounts the screen before the answer.
        save.flush({ includeHeld: true });
        save.unmount();
        whenLeft().then(() => (left = true));
        await vi.advanceTimersByTimeAsync(0);
        expect(post).toHaveBeenCalledTimes(1);
        expect(left).toBe(false);

        answer({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00' } });
        await vi.advanceTimersByTimeAsync(0);
        expect(left).toBe(true);
    });

    it('does not hand an answer that arrives after the screen went to the page it left', async () => {
        const post = vi.fn(() => Promise.resolve({ ok: false, status: 409, data: { locked: true, message: 'Locked.' } }));
        const save = setup(post);

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        save.unmount();
        await vi.advanceTimersByTimeAsync(0);

        expect(post).toHaveBeenCalledTimes(1);
        expect(onLocked).not.toHaveBeenCalled();
    });

    it('sends everything still queued or held in one keepalive request when the tab closes, even mid-save', async () => {
        const post = vi.fn().mockReturnValueOnce(new Promise(() => {})).mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        save.queue(2, 'status', 'missed', { holdMs: 6000 });
        save.queue(3, 'rollcall', 'cannot_attend');
        window.dispatchEvent(new Event('pagehide'));

        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenLastCalledWith(
            '/rollcall/12',
            { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'missed' }, { trooper_id: 3, rollcall: 'cannot_attend' }] },
            { keepalive: true },
        );
        expect(save.pendingCount.value).toBe(0);
    });

    it('sends nothing on the way out when nothing is waiting', () => {
        const post = vi.fn(() => ok());
        const save = setup(post);

        window.dispatchEvent(new Event('pagehide'));
        save.unmount();

        expect(post).not.toHaveBeenCalled();
    });

    it('lets a partial reload of the page through without asking, even with unsaved changes', () => {
        const save = setup(vi.fn(() => ok()));
        const confirm = vi.fn().mockReturnValue(false);
        vi.stubGlobal('confirm', confirm);

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        expect(visitGuard({ detail: { visit: { method: 'get', only: ['telemetry', 'capture'], except: [] } } })).toBeUndefined();
        expect(visitGuard({ detail: { visit: { method: 'get', only: [], except: [] } } })).toBe(false);
        expect(confirm).toHaveBeenCalledTimes(1);
        vi.unstubAllGlobals();
    });

    it('says whether a field still has an unsent change, held or queued', async () => {
        const save = setup(vi.fn(() => ok()));

        save.queue(1, 'status', 'present', { holdMs: 6000 });
        save.queue(2, 'rollcall', 'will_attend');
        expect(save.isPending(1, 'status')).toBe(true);
        expect(save.isPending(1, 'rollcall')).toBe(false);
        expect(save.isPending(2, 'rollcall')).toBe(true);

        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(save.isPending(2, 'rollcall')).toBe(false);
        expect(save.isPending(1, 'status')).toBe(true);
    });

    it('counts a change as pending while its batch is on the way', async () => {
        let finish;
        const save = setup(vi.fn().mockReturnValueOnce(new Promise((resolve) => (finish = resolve))));

        save.queue(1, 'status', 'present');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
        expect(save.isPending(1, 'status')).toBe(true);

        finish({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00' } });
        await vi.advanceTimersByTimeAsync(0);
        expect(save.isPending(1, 'status')).toBe(false);
    });

    it('an explicit save also sends what changed while its batch was in flight', async () => {
        let finish;
        const post = vi.fn().mockReturnValueOnce(new Promise((resolve) => (finish = resolve))).mockImplementation(() => ok());
        const save = setup(post);

        save.queue(1, 'status', 'present');
        const flushed = save.flush({ includeHeld: true });
        save.queue(2, 'rollcall', 'will_attend');
        finish({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00' } });

        expect(await flushed).toBe(true);
        expect(post).toHaveBeenCalledTimes(2);
        expect(post).toHaveBeenLastCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, rollcall: 'will_attend' }] });
        expect(save.hasUnsaved.value).toBe(false);
    });

    it('explains each kind of failure in words the leader can act on', () => {
        expect(messageFor({ status: 403, data: null })).toBe('This rollcall is read-only for you right now.');
        expect(messageFor({ status: 419, data: null })).toBe('Your session expired; reload the page and try again.');
        expect(messageFor({ status: 0, data: null })).toBe('You were signed out. Sign in again, then retry.');
        expect(messageFor({ status: 401, data: { message: 'Unauthenticated.' } })).toBe('You were signed out. Sign in again, then retry.');
        expect(messageFor({ status: 419, data: { message: 'CSRF token mismatch.' } })).toBe('Your session expired; reload the page and try again.');
        expect(messageFor({ status: 422, data: { message: 'Nope.' } })).toBe('Nope.');
    });
});
