import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import AppLayout from '../AppLayout.vue';
import { THEME_STORAGE_KEY, useTheme } from '@/composables/useTheme';
import { useTrooperSearch } from '@/composables/useTrooperSearch';

// The shell reads everything from the shared Inertia page (url + auth). A reactive stand-in lets
// a test move the URL the way an Inertia visit would.
const mockPage = reactive({ url: '/home', props: { auth: { user: null, profile: null }, viewAs: null } });
const mockRouter = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => mockPage,
    router: mockRouter,
}));

const ENRICHED = {
    rank_short: 'WO1',
    rank_full: 'Warrant Officer 1',
    billet: 'S1 Personnel NCO',
    mos: '42A',
    source: 'Cav roster (api.7cav.us)',
    fetched_at: '2026-08-03T09:14:00+00:00',
};

function mountLayout({ url = '/home', user = { id: 1, username: 'Hale.S', is_admin: false }, profile = null, viewAs = null, crumb = undefined, canActAs = false } = {}) {
    mockPage.url = url;
    mockPage.props = { auth: { user, profile }, viewAs, crumb, canActAs };

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
        it('shows Home, Roster, Rollcall, Reports and Game identities to everyone', () => {
            wrapper = mountLayout();

            for (const label of ['Home', 'Roster', 'Rollcall', 'Reports', 'Game identities']) {
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

        it('navigates with Inertia links, not full page loads', () => {
            wrapper = mountLayout({ user: { id: 1, username: 'Hale.S', is_admin: true } });

            // Inertia's <Link> renders an anchor and intercepts the click; a plain <a> would not
            // carry the component. Every nav anchor must be one.
            const linkHrefs = wrapper.findAllComponents({ name: 'Link' }).map((link) => link.props('href'));
            const navHrefs = wrapper.findAll('nav[aria-label="Primary"] a').map((a) => a.attributes('href'));

            expect(navHrefs).toEqual(['/home', '/roster', '/rollcall', '/reports', '/game-identities', '/settings/org-structure', '/settings/staff-grants', '/settings/templates', '/settings/games', '/settings/servers', '/settings/audit-log']);
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

        it('reads a trooper page as a child of Roster: Roster / name, with Roster active in the sidebar', () => {
            wrapper = mountLayout({ url: '/troopers/12', crumb: 'SPC Milkovic.D' });

            expect(wrapper.find('[data-testid="breadcrumb-current"]').text()).toBe('SPC Milkovic.D');
            expect(wrapper.find('nav[aria-label="breadcrumb"]').text()).toContain('Roster');
            expect(wrapper.find('[data-testid="nav-Roster"]').attributes('aria-current')).toBe('page');
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

    });

    // Story 1.13: the view-as banner, top-bar control and user-menu entry. The picker itself has
    // its own spec (partials/__tests__/ViewAsPicker.spec.js).
    describe('view as', () => {
        const AS_PERSON = { kind: 'user', id: 7, label: 'SGT Vasquez.R', designation: '2/1/A/1-7', unitName: '2nd Section', admin: false };
        const AS_UNIT = { kind: 'unit', id: 12, label: '1/A/1-7', designation: '1/A/1-7', unitName: '1st Platoon', admin: false };

        beforeEach(() => {
            mockRouter.post.mockClear();
            mockRouter.delete.mockClear();
        });

        it('offers the View as control in the top bar on every page (AC #1)', () => {
            for (const url of ['/home', '/settings/org-structure']) {
                wrapper = mountLayout({ url });

                const trigger = wrapper.find('header [data-testid="view-as-trigger"]');
                expect(trigger.exists()).toBe(true);
                expect(trigger.text()).toContain('View as');
                expect(trigger.attributes('aria-haspopup')).toBe('dialog');
                wrapper.unmount();
            }
        });

        it('shows no banner while view-as is off', () => {
            wrapper = mountLayout();

            expect(wrapper.find('[data-testid="view-as-banner"]').exists()).toBe(false);
        });

        it('shows a status banner naming the person and their unit while viewing as someone', () => {
            wrapper = mountLayout({ viewAs: AS_PERSON });

            const banner = wrapper.find('[data-testid="view-as-banner"]');
            expect(banner.attributes('role')).toBe('status');
            expect(banner.text()).toContain('Viewing as SGT Vasquez.R');
            expect(banner.text()).toContain('2/1/A/1-7');
            expect(banner.text()).toContain('Read-only preview');
        });

        it('names a unit preview as its leader\'s view', () => {
            wrapper = mountLayout({ viewAs: AS_UNIT });

            expect(wrapper.find('[data-testid="view-as-banner"]').text()).toContain('Viewing as the leader of 1/A/1-7 (1st Platoon)');
        });

        it('keeps the banner inside the sticky top bar, above the header', () => {
            wrapper = mountLayout({ viewAs: AS_PERSON });

            const bar = wrapper.find('[data-testid="view-as-banner"]').element.parentElement;
            expect(bar.className).toContain('sticky');
            expect(bar.querySelector('header')).not.toBeNull();
        });

        // Sticky page parts (the rollcall stepper, the report asides) offset from --shell-top, so
        // the bar's height has to grow with the banner or they slide under it.
        it('sets the sticky bar height for the page, taller while the banner shows', () => {
            const shellTop = () => wrapper.find('main#main').element.parentElement.style.getPropertyValue('--shell-top');

            wrapper = mountLayout();
            expect(shellTop()).toBe('3rem');
            wrapper.unmount();

            wrapper = mountLayout({ viewAs: AS_PERSON });
            expect(shellTop()).toBe('5.25rem');
        });

        it('exits view-as from the banner with a DELETE', async () => {
            wrapper = mountLayout({ viewAs: AS_PERSON });

            await wrapper.find('[data-testid="view-as-exit"]').trigger('click');

            expect(mockRouter.delete).toHaveBeenCalledWith('/view-as', expect.objectContaining({ preserveScroll: true }));
            expect(wrapper.find('[data-testid="view-as-exit"]').attributes('disabled')).toBeDefined();
        });

        it('enables View as in the user menu, and adds Exit while a preview is on', async () => {
            wrapper = mountLayout({ viewAs: AS_UNIT });
            await openMenu(wrapper, 'user-menu');

            expect(inBody('[data-testid="view-as"]').getAttribute('aria-disabled')).toBeNull();
            expect(inBody('[data-testid="view-as"]').textContent).not.toContain('Coming soon');

            inBody('[data-testid="view-as-menu-exit"]').dispatchEvent(new Event('click', { bubbles: true }));
            await flushPromises();

            expect(mockRouter.delete).toHaveBeenCalledWith('/view-as', expect.anything());
        });

        it('offers Act as in the user menu only to a leader with people below them (#432)', async () => {
            wrapper = mountLayout({ canActAs: true });
            await openMenu(wrapper, 'user-menu');

            expect(inBody('[data-testid="act-as"]').textContent).toContain('Act as');
        });

        it('hides Act as from everyone else', async () => {
            wrapper = mountLayout({ canActAs: false });
            await openMenu(wrapper, 'user-menu');

            expect(inBody('[data-testid="act-as"]')).toBeNull();
        });

        it('opens the picker from the top-bar control', async () => {
            vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
            try {
                wrapper = mountLayout();

                await wrapper.find('[data-testid="view-as-trigger"]').trigger('click');
                await flushPromises();

                expect(inBody('[data-testid="view-as-picker"]')).not.toBeNull();
            } finally {
                vi.unstubAllGlobals();
            }
        });

        it('hides Admin-only navigation while an Admin previews a leader', () => {
            wrapper = mountLayout({ user: { id: 1, username: 'Gryspeert.J', is_admin: true }, viewAs: AS_PERSON });

            expect(wrapper.find('[data-testid="nav-group-settings"]').exists()).toBe(false);
        });

        it('keeps Admin navigation when the previewed person is an Admin too', () => {
            wrapper = mountLayout({ user: { id: 1, username: 'Gryspeert.J', is_admin: true }, viewAs: { ...AS_PERSON, admin: true } });

            expect(wrapper.find('[data-testid="nav-group-settings"]').exists()).toBe(true);
        });
    });

    describe('trooper search', () => {
        afterEach(() => {
            useTrooperSearch().searchOpen.value = false;
        });

        const pressSlash = (target = document.body) =>
            target.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true }));

        it('opens from the top-bar control, which advertises the / key', async () => {
            wrapper = mountLayout();
            const trigger = wrapper.find('[data-testid="trooper-search-trigger"]');

            expect(trigger.attributes('aria-keyshortcuts')).toBe('/');
            expect(trigger.find('kbd').text()).toBe('/');

            await trigger.trigger('click');
            await flushPromises();

            expect(inBody('[data-testid="trooper-search"]')).not.toBeNull();
        });

        it('opens on / from anywhere on the page', async () => {
            wrapper = mountLayout();

            pressSlash();
            await flushPromises();

            expect(useTrooperSearch().searchOpen.value).toBe(true);
            expect(inBody('[data-testid="trooper-search"]')).not.toBeNull();
        });

        it('leaves / alone while typing in a field', async () => {
            wrapper = mountLayout({ url: '/home' });
            const input = document.createElement('input');
            document.body.append(input);

            pressSlash(input);
            await flushPromises();

            expect(useTrooperSearch().searchOpen.value).toBe(false);
        });

        it('stops listening once the shell is gone', async () => {
            wrapper = mountLayout();
            wrapper.unmount();
            wrapper = null;

            pressSlash();
            await flushPromises();

            expect(useTrooperSearch().searchOpen.value).toBe(false);
        });
    });
});
