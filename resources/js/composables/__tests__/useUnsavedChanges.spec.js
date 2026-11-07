import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { mount } from '@vue/test-utils';

const handlers = [];
const stop = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (type, handler) => {
            handlers.push({ type, handler });

            return () => {
                stop();
                handlers.splice(handlers.findIndex((entry) => entry.handler === handler), 1);
            };
        },
    },
}));

const { LEAVE_MESSAGE, useUnsavedChanges } = await import('../useUnsavedChanges');

const visit = (method = 'get') => handlers.filter((entry) => entry.type === 'before').map((entry) => entry.handler({ detail: { visit: { method } } }));
const unload = () => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);

    return event.defaultPrevented;
};
const host = (dirty) => mount(defineComponent({ setup: () => (useUnsavedChanges(dirty), () => h('div')) }));

describe('useUnsavedChanges', () => {
    beforeEach(() => vi.spyOn(window, 'confirm').mockReturnValue(false));
    afterEach(() => {
        vi.restoreAllMocks();
        stop.mockClear();
        handlers.length = 0;
    });

    it('lets a clean page go without asking', () => {
        const wrapper = host(ref(false));
        expect(visit()).toEqual([undefined]);
        expect(unload()).toBe(false);
        expect(window.confirm).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('asks before a visit away and on reload while dirty', () => {
        const dirty = ref(true);
        const wrapper = host(dirty);
        expect(visit()).toEqual([false]);
        expect(window.confirm).toHaveBeenCalledWith(LEAVE_MESSAGE);
        expect(unload()).toBe(true);

        window.confirm.mockReturnValue(true);
        expect(visit()).toEqual([true]);
        wrapper.unmount();
    });

    it('never asks about the page\'s own submit', () => {
        const wrapper = host(() => true);
        expect(visit('post')).toEqual([undefined]);
        expect(visit('put')).toEqual([undefined]);
        expect(window.confirm).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('removes both guards on unmount', () => {
        const wrapper = host(ref(true));
        wrapper.unmount();
        expect(stop).toHaveBeenCalledTimes(1);
        expect(handlers).toHaveLength(0);
        expect(unload()).toBe(false);
    });
});
