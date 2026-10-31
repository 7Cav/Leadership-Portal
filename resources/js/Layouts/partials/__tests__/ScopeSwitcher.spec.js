import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import AppLayout from '../../AppLayout.vue';

// The sidebar's unit switcher and the breadcrumb's first crumb (#426): both start at the top of the viewer's scope as
// the server shares it, and the menu lists the units it reads from /scope/units, each opening a page for that unit.
const mockPage = reactive({ url: '/home', props: {} });
const mockRouter = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => mockPage,
    router: mockRouter,
}));

const SECTION_LEADER_TOP = [{ id: 5, designation: '1/1/A/1-7', name: '1st Section' }];
const COMPANY_UNITS = [
    { id: 3, designation: 'A/1-7', name: 'Alpha Company', type: 'company', line: true, depth: 0 },
    { id: 4, designation: '1/A/1-7', name: '1st Platoon', type: 'platoon', line: true, depth: 1 },
    { id: 5, designation: '1/1/A/1-7', name: '1st Section', type: 'section', line: true, depth: 2 },
];

function mountLayout({ url = '/home', scopeTop = SECTION_LEADER_TOP, props = {} } = {}) {
    mockPage.url = url;
    mockPage.props = { auth: { user: { id: 1, username: 'Hale.S', is_admin: false }, profile: null }, viewAs: null, features: {}, scopeTop, ...props };

    return mount(AppLayout, { slots: { default: '<p>Page body</p>' }, attachTo: document.body });
}

function respondWith(body, { ok = true, status = 200 } = {}) {
    const fetchMock = vi.fn(() => Promise.resolve({ ok, status, json: () => Promise.resolve(body) }));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function openSwitcher(wrapper) {
    await wrapper.find('[data-testid="scope-switcher"]').trigger('keydown', { key: 'Enter' });
    await flushPromises();
}

const menu = () => document.body.querySelector('[data-testid="scope-switcher-menu"]');

describe('the unit switcher', () => {
    let wrapper;

    afterEach(() => {
        wrapper?.unmount();
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        mockRouter.visit.mockClear();
    });

    it('names the top of the viewer\'s scope, not the regiment, on the switcher and the breadcrumb', () => {
        wrapper = mountLayout();

        const switcher = wrapper.find('[data-testid="scope-switcher"]');
        expect(switcher.attributes('aria-label')).toBe('Scope: 1st Section');
        expect(switcher.text()).toContain('1/1/A/1-7');
        expect(switcher.text()).not.toContain('7th Cavalry Regiment');
        expect(wrapper.find('[data-testid="breadcrumb-scope"]').text()).toBe('1/1/A/1-7');
    });

    it('names the first of several tops with how many more', () => {
        wrapper = mountLayout({ scopeTop: [SECTION_LEADER_TOP[0], { id: 9, designation: 'E/2-7', name: 'Echo Company' }] });

        expect(wrapper.find('[data-testid="scope-switcher"]').attributes('aria-label')).toBe('Scope: 2 units');
        expect(wrapper.find('[data-testid="breadcrumb-scope"]').text()).toBe('1/1/A/1-7 +1');
    });

    it('names the regiment for an Admin, whose top it is', () => {
        wrapper = mountLayout({ scopeTop: [{ id: 1, designation: '7CAV', name: '7th Cavalry Regiment' }] });

        expect(wrapper.find('[data-testid="breadcrumb-scope"]').text()).toBe('7CAV');
        expect(wrapper.find('[data-testid="scope-switcher"]').attributes('aria-label')).toBe('Scope: 7th Cavalry Regiment');
    });

    it('lists the units in scope as a tree when opened, and no longer says switching is coming soon', async () => {
        const fetchMock = respondWith({ units: COMPANY_UNITS });
        wrapper = mountLayout({ scopeTop: [{ id: 3, designation: 'A/1-7', name: 'Alpha Company' }] });

        await openSwitcher(wrapper);

        expect(fetchMock).toHaveBeenCalledWith('/scope/units', expect.objectContaining({ credentials: 'same-origin' }));
        const items = [...menu().querySelectorAll('[data-unit]')];
        expect(items.map((item) => item.getAttribute('data-unit'))).toEqual(['3', '4', '5']);
        expect(items[2].style.paddingLeft).toBe('2rem');
        expect(menu().textContent).not.toMatch(/coming soon/i);
    });

    it('opens the same page for the unit chosen when the page is about a unit', async () => {
        respondWith({ units: COMPANY_UNITS });
        wrapper = mountLayout({ url: '/roster/3?view=matrix', props: { unit: { id: 3 } } });

        await openSwitcher(wrapper);
        menu().querySelector('[data-unit="5"]').dispatchEvent(new Event('click', { bubbles: true }));
        await flushPromises();

        expect(mockRouter.visit).toHaveBeenCalledWith('/roster/5?view=matrix');
    });

    it('marks the unit on screen', async () => {
        respondWith({ units: COMPANY_UNITS });
        wrapper = mountLayout({ url: '/roster/4', props: { unit: { id: 4 } } });

        await openSwitcher(wrapper);

        expect(menu().querySelector('[data-unit="4"] [aria-label="Unit on screen"]')).not.toBeNull();
        expect(menu().querySelector('[data-unit="3"] [aria-label="Unit on screen"]')).toBeNull();
    });

    it('says so when the scope holds no unit', async () => {
        respondWith({ units: [] });
        wrapper = mountLayout({ scopeTop: [] });

        await openSwitcher(wrapper);

        expect(menu().textContent).toContain('No units in your scope.');
        expect(wrapper.find('[data-testid="breadcrumb-scope"]').text()).toBe('7CAV');
    });

    it('says so when the list cannot be read', async () => {
        respondWith({}, { ok: false, status: 429 });
        wrapper = mountLayout();

        await openSwitcher(wrapper);

        expect(menu().querySelector('[role="alert"]').textContent).toContain('Your units could not be loaded.');
    });

    describe('guidons', () => {
        const GUIDON = { format: 'diagonal', primary: '#f47a20', secondary: '#ffffff', topLeft: 'ACD', bottomRight: 'B', topAlign: 'left', bottomAlign: 'left', large: '7' };

        it('shows the one top\'s guidon in the crest spot, named since the unit name is not its alt', () => {
            wrapper = mountLayout({ scopeTop: [{ ...SECTION_LEADER_TOP[0], guidon: GUIDON }] });

            const crest = wrapper.find('[data-testid="scope-switcher-guidon"] img');
            expect(crest.attributes('alt')).toBe('Guidon of 1/1/A/1-7');
            expect(crest.attributes('height')).toBe('23');
            expect(wrapper.find('img[src="/assets/cav-shield.png"]').exists()).toBe(false);
        });

        it('keeps the shield for a top with no guidon, or several tops', () => {
            wrapper = mountLayout({ scopeTop: [{ ...SECTION_LEADER_TOP[0], guidon: GUIDON }, { id: 9, designation: 'E/2-7', name: 'Echo Company', guidon: GUIDON }] });

            expect(wrapper.find('[data-testid="scope-switcher-guidon"]').exists()).toBe(false);
            expect(wrapper.find('img[src="/assets/cav-shield.png"]').exists()).toBe(true);
        });

        it('shows each listed unit\'s own guidon, decorative beside its name', async () => {
            respondWith({ units: [{ ...COMPANY_UNITS[0], guidon: GUIDON }, ...COMPANY_UNITS.slice(1)] });
            wrapper = mountLayout({ scopeTop: [{ id: 3, designation: 'A/1-7', name: 'Alpha Company' }] });

            await openSwitcher(wrapper);

            const flags = menu().querySelectorAll('[data-unit] [data-testid="guidon"]');
            expect(flags).toHaveLength(1);
            expect(flags[0].closest('[data-unit]').getAttribute('data-unit')).toBe('3');
            expect(flags[0].getAttribute('alt')).toBe('');
            expect(flags[0].getAttribute('height')).toBe('12');
            // Too small for text: the colors and split only.
            expect(decodeURIComponent(flags[0].getAttribute('src'))).not.toContain('data-text');
        });
    });
});
