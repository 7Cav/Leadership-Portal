import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import ActAsPicker from '../ActAsPicker.vue';
import { useActAs } from '@/composables/useActAs';

const mockPage = reactive({ url: '/home', props: { auth: { user: { id: 1, username: 'Hale.S', is_admin: false } }, canActAs: true } });
const mockRouter = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => mockPage,
    router: mockRouter,
}));

const PEOPLE = {
    people: [
        { id: 7, label: 'SGT Mercer.J', designation: '2/1/A/1-7' },
        { id: 8, label: 'SGT Vasquez.R', designation: null },
    ],
};

const inBody = (selector) => document.body.querySelector(selector);
const allInBody = (selector) => [...document.body.querySelectorAll(selector)];

function respondWith(body, { ok = true, status = 200 } = {}) {
    const fetchMock = vi.fn(() => Promise.resolve({ ok, status, json: () => Promise.resolve(body) }));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function openPicker() {
    const wrapper = mount(ActAsPicker, { attachTo: document.body });
    useActAs().pickerOpen.value = true;
    await flushPromises();
    await flushPromises();

    return wrapper;
}

async function click(selector) {
    inBody(selector).dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();
}

describe('ActAsPicker (#432)', () => {
    let wrapper;

    beforeEach(() => {
        mockRouter.post.mockReset();
    });

    afterEach(() => {
        useActAs().pickerOpen.value = false;
        wrapper?.unmount();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('fetches the people fresh each time it opens, as JSON with the session cookie', async () => {
        const fetchMock = respondWith(PEOPLE);
        wrapper = await openPicker();

        expect(fetchMock).toHaveBeenCalledWith(
            '/act-as/options',
            expect.objectContaining({ credentials: 'same-origin', headers: expect.objectContaining({ Accept: 'application/json' }) }),
        );
    });

    it('lists exactly the people the server offered, with their unit', async () => {
        respondWith(PEOPLE);
        wrapper = await openPicker();

        expect(allInBody('[data-testid^="act-as-user-"]').map((el) => el.textContent.trim())).toEqual([
            expect.stringContaining('SGT Mercer.J'),
            expect.stringContaining('SGT Vasquez.R'),
        ]);
        expect(inBody('[data-testid="act-as-user-7"]').textContent).toContain('2/1/A/1-7');
    });

    it('asks to confirm before acting, and posts the person as a user on confirm', async () => {
        respondWith(PEOPLE);
        wrapper = await openPicker();

        await click('[data-testid="act-as-user-7"]');
        expect(mockRouter.post).not.toHaveBeenCalled();
        expect(inBody('[data-testid="act-as-picker-confirm"]').textContent).toContain('Act as SGT Mercer.J?');

        await click('[data-testid="act-as-picker-confirm-button"]');
        expect(mockRouter.post).toHaveBeenCalledWith('/act-as', { kind: 'user', id: 7 }, expect.any(Object));
    });

    it('goes back to the list on cancel without posting', async () => {
        respondWith(PEOPLE);
        wrapper = await openPicker();

        await click('[data-testid="act-as-user-7"]');
        await click('[data-testid="act-as-picker-cancel"]');

        expect(mockRouter.post).not.toHaveBeenCalled();
        expect(inBody('[data-testid="act-as-list"]')).not.toBeNull();
    });

    it('shows the server\'s refusal and stays open', async () => {
        respondWith(PEOPLE);
        mockRouter.post.mockImplementation((url, data, options) => options.onError({ act_as: 'You can only act as a serving billet holder below you.' }));
        wrapper = await openPicker();

        await click('[data-testid="act-as-user-7"]');
        await click('[data-testid="act-as-picker-confirm-button"]');

        expect(inBody('[data-testid="act-as-picker-error"]').textContent).toContain('serving billet holder below you');
        expect(useActAs().pickerOpen.value).toBe(true);
    });

    it("says a refusal or the write limit in plain words instead of Inertia's error dialog", async () => {
        respondWith(PEOPLE);
        let handled;
        mockRouter.post.mockImplementation((url, data, options) => (handled = options.onHttpException({ status: 429 })));
        wrapper = await openPicker();

        await click('[data-testid="act-as-user-7"]');
        await click('[data-testid="act-as-picker-confirm-button"]');

        expect(handled).toBe(false);
        expect(inBody('[data-testid="act-as-picker-error"]').textContent).toBe('Acting as them did not start. Too many requests; wait a minute and try again.');
    });

    it('says so when there is nobody to act as', async () => {
        respondWith({ people: [] });
        wrapper = await openPicker();

        expect(inBody('[data-testid="act-as-empty"]')).not.toBeNull();
        expect(inBody('[data-testid="act-as-list"]')).toBeNull();
    });

    it('offers a retry when the list does not load', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        respondWith({}, { ok: false, status: 403 });
        wrapper = await openPicker();

        expect(inBody('[data-testid="act-as-error"]')).not.toBeNull();

        const retry = respondWith(PEOPLE);
        await click('[data-testid="act-as-error"] button');
        expect(retry).toHaveBeenCalledTimes(1);
        expect(inBody('[data-testid="act-as-list"]')).not.toBeNull();
    });

    it('treats a response without a people list as a failure, not as nobody to act as', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        respondWith({ message: 'nope' });
        wrapper = await openPicker();

        expect(inBody('[data-testid="act-as-error"]')).not.toBeNull();
        expect(inBody('[data-testid="act-as-empty"]')).toBeNull();
    });
});
