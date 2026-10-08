import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import AppLayout from '../AppLayout.vue';
import { THEME_STORAGE_KEY, useTheme } from '@/composables/useTheme';

// The shell reads everything from the shared Inertia page (url + auth). A reactive stand-in lets
// a test move the URL the way an Inertia visit would.
const mockPage = reactive({ url: '/home', props: { auth: { user: null, profile: null } } });

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => mockPage,
}));

const ENRICHED = {
    rank_short: 'WO1',
    rank_full: 'Warrant Officer 1',
    billet: 'S1 Personnel NCO',
    mos: '42A',
    source: 'Cav roster (api.7cav.us)',
    fetched_at: '2026-08-03T09:14:00+00:00',
};

function mountLayout({ url = '/home', user = { id: 1, username: 'Hale.S', is_admin: false }, profile = null } = {}) {
    mockPage.url = url;
    mockPage.props = { auth: { user, profile } };

    return mount(AppLayout, {
        slots: { default: '<p data-testid="page-body">Page body</p>' },
        attachTo: document.body,
    });
}

// Reka renders menu content in a portal on <body>, only while open.
async function openMenu(wrapper, testid) {
    await wrapper.find(`[data-testid="${testid}"]`).trigger('keydown', { key: 'Enter' });
    await flushPromises();
}

const inBody = (selector) => document.body.querySelector(selector);

describe('AppLayout', () => {
    let wrapper;

    beforeEach(() => {
        localStorage.clear();
        document.documentElement.classList.remove('dark');
        useTheme().syncFromDocument();
    });

    afterEach(() => {
        wrapper?.unmount();
        document.body.innerHTML = '';
    });

    it('renders the page inside the shell, in the main landmark the skip link targets', () => {
        wrapper = mountLayout();

        expect(wrapper.find('main#main [data-testid="page-body"]').exists()).toBe(true);
        expect(wrapper.find('a[href="#main"]').text()).toBe('Skip to content');
    });

    describe('navigation', () => {
        it('shows Home, Roster, Rollcall and Reports to everyone', () => {
            wrapper = mountLayout();

            for (const label of ['Home', 'Roster', 'Rollcall', 'Reports']) {
                expect(wrapper.find(`[data-testid="nav-${label}"]`).exists()).toBe(true);
            }
        });

        it('hides the Settings group from a non-Admin', () => {
            wrapper = mountLayout({ user: { id: 1, username: 'Hale.S', is_admin: false } });

            expect(wrapper.find('[data-testid="nav-group-settings"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="nav-Org structure"]').exists()).toBe(false);
        });

        it('shows the Settings group, with its three screens, to an Admin', () => {
            wrapper = mountLayout({ user: { id: 1, username: 'Hale.S', is_admin: true } });

            const settings = wrapper.find('[data-testid="nav-group-settings"]');
            expect(settings.exists()).toBe(true);
            expect(settings.find('a[href="/settings/org-structure"]').exists()).toBe(true);
            expect(settings.find('a[href="/settings/staff-grants"]').exists()).toBe(true);
            expect(settings.find('a[href="/settings/audit-log"]').exists()).toBe(true);
        });

        // The full table of is_admin values lives in composables/__tests__/useIdentity.spec.js.
        it('treats a truthy but non-boolean is_admin as not an Admin', () => {
            wrapper = mountLayout({ user: { id: 1, username: 'Hale.S', is_admin: 1 } });

            expect(wrapper.find('[data-testid="nav-group-settings"]').exists()).toBe(false);
        });

        it('marks only the current section as active', () => {
            wrapper = mountLayout({ url: '/home' });

            const home = wrapper.find('[data-testid="nav-Home"]');
            expect(home.attributes('aria-current')).toBe('page');
            expect(home.attributes('data-active')).toBe('true');
            // The breadcrumb's current crumb carries aria-current too; count the sidebar only.
            expect(wrapper.findAll('[data-sidebar="sidebar"] [aria-current="page"]')).toHaveLength(1);
        });

        it('keeps a section active on its sub-pages and query strings', () => {
            wrapper = mountLayout({ url: '/settings/audit-log?page=2', user: { id: 1, username: 'Hale.S', is_admin: true } });

            expect(wrapper.find('[data-testid="nav-Audit log"]').attributes('aria-current')).toBe('page');
            expect(wrapper.find('[data-testid="nav-Home"]').attributes('aria-current')).toBeUndefined();
        });

        it('moves the active marker when an Inertia visit changes the URL', async () => {
            wrapper = mountLayout({ url: '/home', user: { id: 1, username: 'Hale.S', is_admin: true } });

            mockPage.url = '/settings/org-structure';
            await flushPromises();

            expect(wrapper.find('[data-testid="nav-Org structure"]').attributes('aria-current')).toBe('page');
            expect(wrapper.find('[data-testid="nav-Home"]').attributes('aria-current')).toBeUndefined();
        });

        it('renders screens that do not exist yet as disabled controls, not links', () => {
            wrapper = mountLayout();

            for (const label of ['Roster', 'Rollcall', 'Reports']) {
                const item = wrapper.find(`[data-testid="nav-${label}"]`);
                expect(item.element.tagName).toBe('BUTTON');
                expect(item.attributes('aria-disabled')).toBe('true');
                expect(item.attributes('href')).toBeUndefined();
                expect(item.text()).toContain('Soon');
            }
        });

        it('navigates with Inertia links, not full page loads', () => {
            wrapper = mountLayout({ user: { id: 1, username: 'Hale.S', is_admin: true } });

            // Inertia's <Link> renders an anchor and intercepts the click; a plain <a> would not
            // carry the component. Every nav anchor must be one.
            const linkHrefs = wrapper.findAllComponents({ name: 'Link' }).map((link) => link.props('href'));
            const navHrefs = wrapper.findAll('nav[aria-label="Primary"] a').map((a) => a.attributes('href'));

            expect(navHrefs).toEqual(['/home', '/settings/org-structure', '/settings/staff-grants', '/settings/audit-log']);
            for (const href of navHrefs) expect(linkHrefs).toContain(href);
        });

        it('exposes the menu as a labelled navigation landmark', () => {
            wrapper = mountLayout();

            expect(wrapper.find('nav[aria-label="Primary"] a[href="/home"]').exists()).toBe(true);
        });

        it('shows the current page in the breadcrumb', () => {
            wrapper = mountLayout({ url: '/settings/staff-grants', user: { id: 1, username: 'Hale.S', is_admin: true } });

            expect(wrapper.find('[data-testid="breadcrumb-current"]').text()).toBe('Staff grants');
            expect(wrapper.find('nav[aria-label="breadcrumb"]').text()).toContain('Settings');
        });
    });

    describe('theme', () => {
        it('toggles to dark, marks <html>, and remembers the choice', async () => {
            wrapper = mountLayout();

            await wrapper.find('[data-testid="theme-toggle"]').trigger('click');

            expect(document.documentElement.classList.contains('dark')).toBe(true);
            expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
            expect(wrapper.find('[data-testid="theme-toggle"]').attributes('aria-label')).toBe('Switch to light theme');
        });

        it('toggles back to light and stores that too', async () => {
            document.documentElement.classList.add('dark');
            wrapper = mountLayout();
            await flushPromises();

            expect(wrapper.find('[data-testid="theme-toggle"]').attributes('aria-label')).toBe('Switch to light theme');

            await wrapper.find('[data-testid="theme-toggle"]').trigger('click');

            expect(document.documentElement.classList.contains('dark')).toBe(false);
            expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('light');
        });

        it('starts from the theme the page was pre-painted with', async () => {
            document.documentElement.classList.add('dark');
            wrapper = mountLayout();
            await flushPromises();

            expect(useTheme().theme.value).toBe('dark');
        });

        it('still switches theme when storage is unavailable', async () => {
            const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
                throw new Error('blocked');
            });
            try {
                wrapper = mountLayout();

                await wrapper.find('[data-testid="theme-toggle"]').trigger('click');

                expect(document.documentElement.classList.contains('dark')).toBe(true);
            } finally {
                setItem.mockRestore();
            }
        });

        it('switches theme from the user menu as well', async () => {
            wrapper = mountLayout();
            await openMenu(wrapper, 'user-menu');

            inBody('[data-testid="theme-dark"]').dispatchEvent(new Event('click', { bubbles: true }));
            await flushPromises();

            expect(document.documentElement.classList.contains('dark')).toBe(true);
            expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
        });
    });

    // Below lg (1024px, covering the AC's <= 880px) the sidebar becomes an off-canvas sheet.
    describe('mobile menu', () => {
        let originalMatchMedia;

        beforeEach(() => {
            originalMatchMedia = window.matchMedia;
            window.matchMedia = (query) => ({
                matches: query === '(max-width: 1023px)',
                media: query,
                addEventListener() {},
                removeEventListener() {},
                addListener() {},
                removeListener() {},
            });
        });

        afterEach(() => {
            window.matchMedia = originalMatchMedia;
        });

        const sheet = () => inBody('[data-mobile="true"]');

        it('keeps the menu closed until the trigger opens it as a sheet', async () => {
            wrapper = mountLayout();
            await flushPromises();
            expect(sheet()).toBeNull();

            await wrapper.find('[data-sidebar="trigger"]').trigger('click');
            await flushPromises();

            expect(sheet()).not.toBeNull();
            expect(sheet().querySelector('nav[aria-label="Primary"] a[href="/home"]')).not.toBeNull();
        });

        it('closes the sheet once a nav link is followed, even to the page already open', async () => {
            wrapper = mountLayout();
            await wrapper.find('[data-sidebar="trigger"]').trigger('click');
            await flushPromises();

            // A modified click: Inertia's Link leaves it alone (no visit in a unit test), while the
            // shell's own click handler still runs.
            sheet().querySelector('a[href="/home"]').dispatchEvent(new MouseEvent('click', { bubbles: true, ctrlKey: true }));
            await flushPromises();

            expect(sheet()).toBeNull();
        });
    });

    describe('identity cluster', () => {
        it('shows rank, username and billet with their source and age (AD-20)', async () => {
            wrapper = mountLayout({ profile: ENRICHED });

            const trigger = wrapper.find('[data-testid="user-menu"]');
            expect(trigger.text()).toContain('WO1 Hale.S');
            expect(trigger.text()).toContain('S1 Personnel NCO');
            expect(trigger.text()).toContain('Cav roster (api.7cav.us)');

            await openMenu(wrapper, 'user-menu');

            expect(inBody('.identity').textContent).toBe('WO1 Hale.S · S1 Personnel NCO');
            const provenance = inBody('.provenance');
            expect(provenance.textContent).toContain('MOS 42A');
            expect(provenance.textContent).toContain('Cav roster (api.7cav.us)');
            expect(provenance.getAttribute('title')).toContain('Warrant Officer 1');
        });

        it('degrades to the bare username, with no provenance, when unenriched', async () => {
            wrapper = mountLayout({ profile: null });
            await openMenu(wrapper, 'user-menu');

            expect(inBody('.identity').textContent).toBe('Hale.S');
            expect(inBody('.provenance')).toBeNull();
        });

        it.each([
            ['Hale.S', 'HS'],
            ['Gryspeert.J', 'GJ'],
            ['some_long_user_name', 'SL'],
        ])('builds the avatar monogram from %s as %s', (username, expected) => {
            wrapper = mountLayout({ user: { id: 1, username, is_admin: false } });

            expect(wrapper.find('[data-testid="user-menu"] [data-slot="avatar-fallback"]').text()).toBe(expected);
        });

        it('falls back to a placeholder identity without a username', () => {
            wrapper = mountLayout({ user: { id: 1, username: '', is_admin: false } });

            expect(wrapper.find('[data-testid="user-menu"]').text()).toContain('Trooper');
        });

        it('offers exactly one Sign out, posting to the logout route as a button (Story 1.5)', async () => {
            wrapper = mountLayout();
            await openMenu(wrapper, 'user-menu');

            const signOuts = wrapper.findAllComponents({ name: 'Link' }).filter((link) => link.props('href') === '/logout');
            expect(signOuts).toHaveLength(1);
            expect(signOuts[0].props('method')).toBe('post');
            expect(signOuts[0].props('as')).toBe('button');
            expect(inBody('[data-testid="sign-out"]').tagName).toBe('BUTTON');
            expect(inBody('[data-testid="sign-out"]').textContent).toContain('Sign out');
        });

        it('shows View as, disabled and marked coming soon, until Story 1.13', async () => {
            wrapper = mountLayout();
            await openMenu(wrapper, 'user-menu');

            const viewAs = inBody('[data-testid="view-as"]');
            expect(viewAs.getAttribute('aria-disabled')).toBe('true');
            expect(viewAs.textContent).toContain('Coming soon');
        });
    });
});
