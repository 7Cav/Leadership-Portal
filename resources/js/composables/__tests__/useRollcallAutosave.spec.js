import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';
import { DEBOUNCE_MS, useRollcallAutosave } from '../useRollcallAutosave';

vi.mock('@inertiajs/vue3', () => ({ router: { on: () => () => {} } }));

function setup(post) {
    let api;
    mount(defineComponent({ setup: () => ((api = useRollcallAutosave({ url: '/rollcall/12', week: '2026-10-03', post, onSaved })), () => h('div')) }));

    return api;
}

const onSaved = vi.fn();
const ok = (data = {}) => Promise.resolve({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [], ...data } });

describe('rollcall autosave', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        onSaved.mockClear();
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
        expect(save.error.value).toBe('The server did not accept the change.');
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

        save.cancel([1], 'status');
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
});
