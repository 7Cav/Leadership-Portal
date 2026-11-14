import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import AppLayout from '../../AppLayout.vue';

// The sidebar's Bookmarks section and the top bar's bookmark button, both read from the shared `bookmarks` prop
// (null during view as, act as and the tutorial, which hides both).
const mockPage = reactive({ url: '/home', props: {} });
const mockRouter = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn(), patch: vi.fn(), visit: vi.fn(), reload: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => mockPage,
    router: mockRouter,
}));

const ROSTER = { id: 11, label: 'Roster 2/B/2-7', href: '/roster/41' };
const REPORT = { id: 12, label: 'Report 1/1/C/ACD', href: '/reports/52' };

function mountLayout({ url = '/home', bookmarks = null } = {}) {
    mockPage.url = url;
    mockPage.props = {
        auth: { user: { id: 1, username: 'Hale.S', is_admin: false }, profile: null },
        viewAs: null,
        features: {},
        scopeTop: [],
        bookmarks,
    };

    return mount(AppLayout, { slots: { default: '<p>Page body</p>' }, attachTo: document.body });
}

function shared({ items = [], current = null, limit = 10 } = {}) {
    return { limit, items, current };
}

const rosterPage = (bookmarkId = null) => ({ route: 'roster.index', params: { unit: 41 }, label: 'Roster 2/B/2-7', bookmarkId });

async function openMenu(wrapper, index = 0) {
    await wrapper.findAll('[data-testid="bookmark-menu"]')[index].trigger('keydown', { key: 'Enter' });
    await flushPromises();
}

async function choose(testid) {
    document.body.querySelector(`[data-testid="${testid}"]`).click();
    await flushPromises();
}

describe('bookmarks', () => {
    let wrapper;

    afterEach(() => {
        wrapper?.unmount();
        document.body.innerHTML = '';
        Object.values(mockRouter).forEach((fn) => fn.mockClear());
    });

    describe('the sidebar section', () => {
        it('lists the bookmarks in their order, after Leadership and before Help', () => {
            wrapper = mountLayout({ bookmarks: shared({ items: [ROSTER, REPORT] }) });

            const group = wrapper.get('[data-testid="nav-group-bookmarks"]');
            expect(group.text()).toContain('Bookmarks');
            const links = group.findAll('[data-testid="bookmark-link"]');
            expect(links.map((link) => link.text())).toEqual(['Roster 2/B/2-7', 'Report 1/1/C/ACD']);
            expect(links[0].attributes('href')).toBe('/roster/41');

            const order = [...document.querySelectorAll('[data-testid^="nav-group-"]')].map((node) => node.dataset.testid);
            expect(order.indexOf('nav-group-bookmarks')).toBe(order.indexOf('nav-group-leadership') + 1);
        });

        it('marks the bookmark of the page on screen', () => {
            wrapper = mountLayout({ url: '/roster/41?view=matrix', bookmarks: shared({ items: [ROSTER, REPORT] }) });

            const links = wrapper.findAll('[data-testid="bookmark-link"]');
            expect(links[0].attributes('aria-current')).toBe('page');
            expect(links[1].attributes('aria-current')).toBeUndefined();
        });

        it('is not shown with no bookmarks, nor while they are hidden', () => {
            wrapper = mountLayout({ bookmarks: shared() });
            expect(wrapper.find('[data-testid="nav-group-bookmarks"]').exists()).toBe(false);
            wrapper.unmount();

            wrapper = mountLayout({ bookmarks: null });
            expect(wrapper.find('[data-testid="nav-group-bookmarks"]').exists()).toBe(false);
        });

        it('moves and removes a bookmark from its menu', async () => {
            wrapper = mountLayout({ bookmarks: shared({ items: [ROSTER, REPORT] }) });

            await openMenu(wrapper, 0);
            // The first has nowhere to go up.
            expect(document.body.querySelector('[data-testid="bookmark-up"]')).toBeNull();
            await choose('bookmark-down');
            expect(mockRouter.patch).toHaveBeenCalledWith('/bookmarks/11', { direction: 'down' }, expect.any(Object));

            await openMenu(wrapper, 1);
            expect(document.body.querySelector('[data-testid="bookmark-down"]')).toBeNull();
            await choose('bookmark-remove');
            expect(mockRouter.delete).toHaveBeenCalledWith('/bookmarks/12', expect.any(Object));
        });
    });

    describe('the top bar button', () => {
        it('bookmarks the page on screen', async () => {
            wrapper = mountLayout({ url: '/roster/41', bookmarks: shared({ current: rosterPage() }) });

            const button = wrapper.get('[data-testid="bookmark-toggle"]');
            expect(button.attributes('aria-label')).toBe('Bookmark this page');
            expect(button.attributes('aria-pressed')).toBe('false');

            await button.trigger('click');
            expect(mockRouter.post).toHaveBeenCalledWith('/bookmarks', { route: 'roster.index', params: { unit: 41 } }, expect.any(Object));
        });

        it('is filled on a page already bookmarked, and removes it', async () => {
            wrapper = mountLayout({ url: '/roster/41', bookmarks: shared({ items: [ROSTER], current: rosterPage(11) }) });

            const button = wrapper.get('[data-testid="bookmark-toggle"]');
            expect(button.attributes('aria-label')).toBe('Remove bookmark');
            expect(button.attributes('aria-pressed')).toBe('true');
            expect(button.attributes('data-bookmarked')).toBe('true');

            await button.trigger('click');
            expect(mockRouter.delete).toHaveBeenCalledWith('/bookmarks/11', expect.any(Object));
            expect(mockRouter.post).not.toHaveBeenCalled();
        });

        it('explains the limit and adds nothing when every slot is in use', async () => {
            const items = Array.from({ length: 10 }, (_, i) => ({ id: 100 + i, label: `Roster ${i}`, href: `/roster/${200 + i}` }));
            wrapper = mountLayout({ url: '/roster/41', bookmarks: shared({ items, current: rosterPage() }) });

            const button = wrapper.get('[data-testid="bookmark-toggle"]');
            expect(button.attributes('aria-label')).toBe('All 10 bookmark slots are in use. Remove one to add this page.');
            expect(button.attributes('aria-disabled')).toBe('true');

            await button.trigger('click');
            expect(mockRouter.post).not.toHaveBeenCalled();
        });

        it('is not shown on a page that cannot be bookmarked, nor while bookmarks are hidden', () => {
            wrapper = mountLayout({ bookmarks: shared({ items: [ROSTER] }) });
            expect(wrapper.find('[data-testid="bookmark-toggle"]').exists()).toBe(false);
            wrapper.unmount();

            wrapper = mountLayout({ url: '/roster/41', bookmarks: null });
            expect(wrapper.find('[data-testid="bookmark-toggle"]').exists()).toBe(false);
        });
    });
});
