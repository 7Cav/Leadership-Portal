import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TrooperSearch from '../TrooperSearch.vue';
import { DEBOUNCE_MS, useTrooperSearch } from '@/composables/useTrooperSearch';

const mockRouter = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    router: mockRouter,
}));

const mockReload = vi.hoisted(() => vi.fn());

vi.mock('@/lib/postJson', async (importOriginal) => ({
    ...(await importOriginal()),
    reloadPage: mockReload,
}));

const RESULTS = {
    troopers: [
        { id: 7, rank: 'SGT', rankFull: 'Sergeant', name: 'Vasquez.R', displayName: 'SGT Vasquez.R', section: { id: 5, designation: '1/1/A/1-7', name: '1st Section' }, departed: false },
        { id: 9, rank: 'PFC', rankFull: 'Private First Class', name: 'Vasko.T', displayName: 'PFC Vasko.T', section: null, departed: true },
    ],
    more: false,
};

const inBody = (selector) => document.body.querySelector(selector);
const allInBody = (selector) => [...document.body.querySelectorAll(selector)];

function respond(handler) {
    const fetchMock = vi.fn(handler);
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}
const json = (body, status = 200) => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) });

async function open() {
    const wrapper = mount(TrooperSearch, { attachTo: document.body });
    useTrooperSearch().searchOpen.value = true;
    await flushPromises();

    return wrapper;
}

async function type(text) {
    const input = inBody('[data-testid="trooper-search-input"]');
    input.value = text;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function settle() {
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MS);
    await flushPromises();
}

describe('TrooperSearch', () => {
    let wrapper;

    beforeEach(() => {
        vi.useFakeTimers();
        mockRouter.visit.mockReset();
        mockReload.mockReset();
    });

    afterEach(() => {
        useTrooperSearch().searchOpen.value = false;
        wrapper?.unmount();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('opens on a hint, and sends nothing for a single letter', async () => {
        const fetchMock = respond(() => json(RESULTS));
        wrapper = await open();

        expect(inBody('[data-testid="trooper-search-hint"]').textContent).toContain('at least 2');

        await type(' v ');
        await settle();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(inBody('[data-testid="trooper-search-hint"]')).not.toBeNull();
    });

    it('waits for a pause in typing, then sends one search for the trimmed text, as JSON with the session cookie', async () => {
        const fetchMock = respond(() => json(RESULTS));
        wrapper = await open();

        await type('va');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS - 50);
        await type('vas');
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MS - 50);
        await type(' vasq ');
        expect(fetchMock).not.toHaveBeenCalled();
        expect(inBody('[data-testid="trooper-search-loading"]')).not.toBeNull();

        await settle();

        expect(fetchMock).toHaveBeenCalledOnce();
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/troopers/search?q=vasq');
        expect(init).toMatchObject({ credentials: 'same-origin', headers: { Accept: 'application/json' } });
    });

    it('encodes the text it sends', async () => {
        const fetchMock = respond(() => json(RESULTS));
        wrapper = await open();

        await type('a&b=c');
        await settle();

        expect(fetchMock.mock.calls[0][0]).toBe('/troopers/search?q=a%26b%3Dc');
    });

    it('lists each trooper with rank, name and section, and marks the departed', async () => {
        respond(() => json(RESULTS));
        wrapper = await open();

        await type('vas');
        await settle();

        const first = inBody('[data-testid="trooper-search-result-7"]');
        expect(first.textContent).toContain('SGT');
        expect(first.textContent).toContain('Vasquez.R');
        expect(first.textContent).toContain('1/1/A/1-7');

        const second = inBody('[data-testid="trooper-search-result-9"]');
        expect(second.textContent).toContain('Departed');
        expect(second.textContent).toContain('Not placed');
        expect(inBody('[data-testid="trooper-search-more"]')).toBeNull();
    });

    it('says when there are more troopers than shown', async () => {
        respond(() => json({ ...RESULTS, more: true }));
        wrapper = await open();

        await type('vas');
        await settle();

        expect(inBody('[data-testid="trooper-search-more"]').textContent).toContain('first 2');
    });

    it('opens the picked trooper\'s page and closes', async () => {
        respond(() => json(RESULTS));
        wrapper = await open();

        await type('vas');
        await settle();
        inBody('[data-testid="trooper-search-result-7"]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(mockRouter.visit).toHaveBeenCalledWith('/troopers/7');
        expect(useTrooperSearch().searchOpen.value).toBe(false);
    });

    it('says plainly when nobody in scope matches', async () => {
        respond(() => json({ troopers: [], more: false }));
        wrapper = await open();

        await type('zz');
        await settle();

        expect(inBody('[data-testid="trooper-search-empty"]').textContent).toContain('No trooper in your scope matches “zz”');
    });

    it('keeps only the newest answer when an older one arrives late', async () => {
        const pending = [];
        respond(() => new Promise((resolve) => pending.push(resolve)));
        wrapper = await open();

        await type('va');
        await settle();
        await type('vasq');
        await settle();

        // The newer answer lands first, then the stale one.
        pending[1]({ ok: true, status: 200, json: () => Promise.resolve({ troopers: [RESULTS.troopers[0]], more: false }) });
        await flushPromises();
        pending[0]({ ok: true, status: 200, json: () => Promise.resolve(RESULTS) });
        await flushPromises();

        expect(allInBody('[data-testid^="trooper-search-result-"]').map((el) => el.dataset.testid)).toEqual(['trooper-search-result-7']);
    });

    it('asks the user to slow down when the server throttles', async () => {
        respond(() => json({}, 429));
        wrapper = await open();

        await type('vas');
        await settle();

        expect(inBody('[data-testid="trooper-search-throttled"]')).not.toBeNull();
    });

    it.each([401, 419])('reloads into sign-in after a %i instead of showing an error', async (status) => {
        respond(() => json({ message: 'Unauthenticated.' }, status));
        wrapper = await open();

        await type('vas');
        await settle();

        expect(mockReload).toHaveBeenCalledOnce();
        expect(inBody('[data-testid="trooper-search-error"]')).toBeNull();
    });

    it('stays on the page and offers a retry after a server error', async () => {
        respond(() => json({ message: 'Server Error' }, 500));
        wrapper = await open();

        await type('vas');
        await settle();

        expect(mockReload).not.toHaveBeenCalled();
        expect(inBody('[data-testid="trooper-search-error"]')).not.toBeNull();
    });

    it('offers a retry when the search fails', async () => {
        const fetchMock = respond(() => Promise.reject(new TypeError('network')));
        wrapper = await open();

        await type('vas');
        await settle();
        expect(inBody('[data-testid="trooper-search-error"]')).not.toBeNull();

        fetchMock.mockImplementation(() => json(RESULTS));
        [...inBody('[data-testid="trooper-search-error"]').querySelectorAll('button')].at(-1).click();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).toBe('/troopers/search?q=vas');
        expect(inBody('[data-testid="trooper-search-result-7"]')).not.toBeNull();
    });

    it('starts blank each time it opens', async () => {
        respond(() => json(RESULTS));
        wrapper = await open();
        await type('vas');
        await settle();

        useTrooperSearch().searchOpen.value = false;
        await flushPromises();
        await vi.runAllTimersAsync();
        useTrooperSearch().searchOpen.value = true;
        await flushPromises();

        expect(inBody('[data-testid="trooper-search-input"]').value).toBe('');
        expect(inBody('[data-testid="trooper-search-hint"]')).not.toBeNull();
    });
});
