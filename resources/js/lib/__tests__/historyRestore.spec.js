import { beforeEach, describe, expect, it, vi } from 'vitest';

// A fresh copy of the module each time: its answer is module state, set by events.
async function load() {
    vi.resetModules();

    return (await import('../historyRestore')).takeHistoryRestore;
}

describe('takeHistoryRestore', () => {
    beforeEach(() => vi.resetModules());

    it('says no for a page answered by the server', async () => {
        const take = await load();

        expect(take()).toBe(false);
    });

    it('says yes once after browser Back or Forward, then no', async () => {
        const take = await load();

        window.dispatchEvent(new PopStateEvent('popstate', { state: null }));

        expect(take()).toBe(true);
        expect(take()).toBe(false);
    });

    it('forgets the Back once a visit goes to the server', async () => {
        const take = await load();

        window.dispatchEvent(new PopStateEvent('popstate', { state: null }));
        document.dispatchEvent(new CustomEvent('inertia:start'));

        expect(take()).toBe(false);
    });
});
