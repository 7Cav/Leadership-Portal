import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import ViewAsPicker from '../ViewAsPicker.vue';
import { useViewAs } from '@/composables/useViewAs';

const mockPage = reactive({ url: '/home', props: { auth: { user: { id: 1, username: 'Hale.S', is_admin: false } }, viewAs: null } });
const mockRouter = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => mockPage,
    router: mockRouter,
}));

const LEADER_OPTIONS = {
    units: [
        { id: 4, designation: '1/A/1-7', name: '1st Platoon', type: 'platoon' },
        { id: 5, designation: '1/1/A/1-7', name: '1st Section', type: 'section' },
        { id: 6, designation: '2/1/A/1-7', name: '2nd Section', type: 'section' },
    ],
    people: null,
    current: null,
};

const ADMIN_OPTIONS = {
    units: LEADER_OPTIONS.units,
    people: [
        { id: 7, label: 'SGT Vasquez.R', designation: '2/1/A/1-7', admin: false },
        { id: 8, label: 'Okafor.T', designation: null, admin: true },
    ],
    current: null,
};

const inBody = (selector) => document.body.querySelector(selector);
const allInBody = (selector) => [...document.body.querySelectorAll(selector)];

function respondWith(body, { ok = true, status = 200 } = {}) {
    const fetchMock = vi.fn(() => Promise.resolve({ ok, status, json: () => Promise.resolve(body) }));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function openPicker() {
    const wrapper = mount(ViewAsPicker, { attachTo: document.body });
    useViewAs().pickerOpen.value = true;
    await flushPromises();
    await flushPromises();

    return wrapper;
}

async function pick(selector) {
    inBody(selector).dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();
}

describe('ViewAsPicker', () => {
    let wrapper;

    beforeEach(() => {
        mockPage.props.viewAs = null;
        mockRouter.post.mockReset();
        mockRouter.delete.mockReset();
    });

    afterEach(() => {
        useViewAs().pickerOpen.value = false;
        wrapper?.unmount();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
    });

    it('fetches the options fresh each time it opens, as JSON with the session cookie', async () => {
        const fetchMock = respondWith(LEADER_OPTIONS);
        wrapper = await openPicker();

        expect(fetchMock).toHaveBeenCalledWith(
            '/view-as/options',
            expect.objectContaining({ credentials: 'same-origin', headers: expect.objectContaining({ Accept: 'application/json' }) }),
        );

        useViewAs().pickerOpen.value = false;
        await flushPromises();
        useViewAs().pickerOpen.value = true;
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('shows a skeleton while the options load', async () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        wrapper = await openPicker();

        expect(inBody('[data-testid="view-as-loading"]').getAttribute('aria-busy')).toBe('true');
        expect(inBody('[data-testid="view-as-list"]')).toBeNull();
    });

    it('lists a leader\'s units by designation and name, with no People group', async () => {
        respondWith(LEADER_OPTIONS);
        wrapper = await openPicker();

        const unit = inBody('[data-testid="view-as-unit-6"]');
        expect(unit.textContent).toContain('2/1/A/1-7');
        expect(unit.textContent).toContain('2nd Section');
        expect(inBody('[data-testid^="view-as-user-"]')).toBeNull();
        expect(inBody('input').getAttribute('placeholder')).toBe('Find a unit');
    });

    it('lists people too for an Admin, with their rank, unit and Admin badge', async () => {
        respondWith(ADMIN_OPTIONS);
        wrapper = await openPicker();

        expect(inBody('[data-testid="view-as-user-7"]').textContent).toContain('SGT Vasquez.R');
        expect(inBody('[data-testid="view-as-user-7"]').textContent).toContain('2/1/A/1-7');
        expect(inBody('[data-testid="view-as-user-8"]').textContent).toContain('Admin');
        expect(inBody('input').getAttribute('placeholder')).toBe('Find a unit or person');
    });

    it('filters units and people by what is typed', async () => {
        respondWith(ADMIN_OPTIONS);
        wrapper = await openPicker();

        const input = inBody('input');
        input.value = 'vasq';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        const visible = allInBody('[data-slot="command-item"]').map((item) => item.dataset.testid);
        expect(visible).toEqual(['view-as-user-7']);
    });

    it('enters view-as for a picked unit and closes on success', async () => {
        respondWith(LEADER_OPTIONS);
        mockRouter.post.mockImplementation((url, data, options) => {
            options.onSuccess?.();
            options.onFinish?.();
        });
        wrapper = await openPicker();

        await pick('[data-testid="view-as-unit-5"]');

        expect(mockRouter.post).toHaveBeenCalledWith('/view-as', { kind: 'unit', id: 5 }, expect.anything());
        expect(useViewAs().pickerOpen.value).toBe(false);
    });

    it('enters view-as for a picked person', async () => {
        respondWith(ADMIN_OPTIONS);
        wrapper = await openPicker();

        await pick('[data-testid="view-as-user-7"]');

        expect(mockRouter.post).toHaveBeenCalledWith('/view-as', { kind: 'user', id: 7 }, expect.anything());
    });

    it('keeps the picker open and says why when the server refuses the target', async () => {
        respondWith(LEADER_OPTIONS);
        mockRouter.post.mockImplementation((url, data, options) => {
            options.onError?.({ view_as: 'You can only view as a unit inside your own scope.' });
            options.onFinish?.();
        });
        wrapper = await openPicker();

        await pick('[data-testid="view-as-unit-4"]');

        expect(useViewAs().pickerOpen.value).toBe(true);
        expect(inBody('[data-testid="view-as-submit-error"]').textContent).toContain('You can only view as a unit inside your own scope.');
        expect(inBody('[data-testid="view-as-submit-error"]').getAttribute('role')).toBe('alert');
    });

    it("says the write limit in plain words instead of Inertia's error dialog, and stays open", async () => {
        respondWith(LEADER_OPTIONS);
        let handled;
        mockRouter.post.mockImplementation((url, data, options) => {
            handled = options.onHttpException?.({ status: 429 });
            options.onFinish?.();
        });
        wrapper = await openPicker();

        await pick('[data-testid="view-as-unit-4"]');

        expect(handled).toBe(false);
        expect(useViewAs().pickerOpen.value).toBe(true);
        expect(inBody('[data-testid="view-as-submit-error"]').textContent.trim()).toBe(
            'The preview did not start. Too many requests; wait a minute and try again.',
        );
    });

    it('shows no error when the preview starts on a page the target cannot open, and lets Inertia render the blocked page', async () => {
        respondWith(LEADER_OPTIONS);
        let handled;
        mockRouter.post.mockImplementation((url, data, options) => {
            handled = options.onHttpException?.({ status: 403, data: { component: 'Blocked', props: { mode: 'viewAs', kind: 'unit' } } });
            options.onSuccess?.();
            options.onFinish?.();
        });
        wrapper = await openPicker();

        await pick('[data-testid="view-as-unit-4"]');

        expect(handled).toBe(true);
        expect(useViewAs().pickerOpen.value).toBe(false);
        expect(inBody('[data-testid="view-as-submit-error"]')).toBeNull();
    });

    it('still says a plain 403 that is not the blocked page failed', async () => {
        respondWith(LEADER_OPTIONS);
        let handled;
        mockRouter.post.mockImplementation((url, data, options) => {
            handled = options.onHttpException?.({ status: 403, data: '<html>Forbidden</html>' });
            options.onFinish?.();
        });
        wrapper = await openPicker();

        await pick('[data-testid="view-as-unit-4"]');

        expect(handled).toBe(false);
        expect(inBody('[data-testid="view-as-submit-error"]').textContent).toContain('The preview did not start.');
    });

    it('marks the current target, closes without a request when it is picked again, and offers the way back', async () => {
        mockPage.props.viewAs = { kind: 'unit', id: 5, label: '1/1/A/1-7', designation: '1/1/A/1-7', unitName: '1st Section', admin: false };
        respondWith({ ...LEADER_OPTIONS, current: { kind: 'unit', id: 5 } });
        wrapper = await openPicker();

        expect(inBody('[data-testid="view-as-unit-5"] [aria-label="Current view"]')).not.toBeNull();
        expect(inBody('[data-testid="view-as-unit-4"] [aria-label="Current view"]')).toBeNull();

        await pick('[data-testid="view-as-unit-5"]');
        expect(mockRouter.post).not.toHaveBeenCalled();
        expect(useViewAs().pickerOpen.value).toBe(false);

        useViewAs().pickerOpen.value = true;
        await flushPromises();
        await flushPromises();
        await pick('[data-testid="view-as-back"]');
        expect(mockRouter.delete).toHaveBeenCalledWith('/view-as', expect.anything());
    });

    it('explains an empty scope instead of showing an empty list', async () => {
        respondWith({ units: [], people: null, current: null });
        wrapper = await openPicker();

        expect(inBody('[data-testid="view-as-empty"]').textContent).toContain('Nothing to view as');
        expect(inBody('[data-testid="view-as-list"]')).toBeNull();
    });

    it('shows a retryable error when the options fail to load', async () => {
        const fetchMock = respondWith({}, { ok: false, status: 500 });
        wrapper = await openPicker();

        expect(inBody('[data-testid="view-as-error"]').getAttribute('role')).toBe('alert');

        fetchMock.mockImplementation(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(LEADER_OPTIONS) }));
        [...inBody('[data-testid="view-as-error"]').querySelectorAll('button')].at(-1).click();
        await flushPromises();
        await flushPromises();

        expect(inBody('[data-testid="view-as-unit-4"]')).not.toBeNull();
    });

    it('treats a network failure like any other load error', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))));
        wrapper = await openPicker();

        expect(inBody('[data-testid="view-as-error"]')).not.toBeNull();
    });

    it('gives the dialog an accessible name and description', async () => {
        respondWith(LEADER_OPTIONS);
        wrapper = await openPicker();

        const dialog = inBody('[role="dialog"]');
        const labelledBy = document.getElementById(dialog.getAttribute('aria-labelledby'));
        expect(labelledBy.textContent).toBe('View as');
        expect(document.getElementById(dialog.getAttribute('aria-describedby')).textContent).toContain('read-only');
    });
});
