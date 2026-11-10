import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import NotificationBell from '../NotificationBell.vue';

const page = reactive({ url: '/home', props: { notificationsUnread: 0 } });
const visit = vi.fn();
const postJson = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => page,
    router: { visit: (...args) => visit(...args) },
}));

vi.mock('@/lib/postJson', () => ({ postJson: (...args) => postJson(...args) }));

const listed = [
    { id: 'n-1', message: "Hale.S changed 1/A/1-7's accountability options to: FTX.", url: '/roster/4?view=admin', read: false, createdAt: '2026-10-24T23:59:00Z' },
    { id: 'n-2', message: 'Older one.', url: '/roster/5?view=admin', read: true, createdAt: '2026-10-20T08:05:00Z' },
];

function body() {
    return document.body.querySelector('[data-testid="notification-list"]');
}

async function openBell(wrapper) {
    await wrapper.get('[data-testid="notification-bell"]').trigger('click');
    await flushPromises();
    await nextTick();
}

describe('notification bell', () => {
    let wrapper;

    beforeEach(() => {
        visit.mockReset();
        postJson.mockReset();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ notifications: listed.map((n) => ({ ...n })), unread: 1 }) }));
    });

    afterEach(() => wrapper?.unmount());

    it('is hidden while the unread count is not shared (view as, act as)', () => {
        page.props = { notificationsUnread: null };
        wrapper = mount(NotificationBell, { attachTo: document.body });

        expect(wrapper.find('[data-testid="notification-bell"]').exists()).toBe(false);
    });

    it('shows the unread count and lists the latest when opened, with the time in Zulu', async () => {
        page.props = { notificationsUnread: 3 };
        wrapper = mount(NotificationBell, { attachTo: document.body });

        expect(wrapper.get('[data-testid="notification-count"]').text()).toBe('3');
        expect(wrapper.get('[data-testid="notification-bell"]').attributes('aria-label')).toBe('Notifications, 3 unread');

        await openBell(wrapper);

        expect(global.fetch).toHaveBeenCalledWith('/notifications', expect.any(Object));
        expect(body().textContent).toContain("Hale.S changed 1/A/1-7's accountability options to: FTX.");
        expect(body().textContent).toContain('24OCT26 23 59Z');
        // The count follows what the server says when the list loads.
        expect(wrapper.get('[data-testid="notification-count"]').text()).toBe('1');
    });

    it('marks one read and opens its link when clicked', async () => {
        page.props = { notificationsUnread: 1 };
        postJson.mockResolvedValue({ ok: true, status: 200, data: { unread: 0 } });
        wrapper = mount(NotificationBell, { attachTo: document.body });
        await openBell(wrapper);

        document.body.querySelector('[data-testid="notification-n-1"]').click();
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/notifications/n-1/read', {});
        expect(visit).toHaveBeenCalledWith('/roster/4?view=admin');
        expect(wrapper.find('[data-testid="notification-count"]').exists()).toBe(false);
    });

    it('does not mark an already read one again', async () => {
        page.props = { notificationsUnread: 1 };
        wrapper = mount(NotificationBell, { attachTo: document.body });
        await openBell(wrapper);

        document.body.querySelector('[data-testid="notification-n-2"]').click();
        await flushPromises();

        expect(postJson).not.toHaveBeenCalled();
        expect(visit).toHaveBeenCalledWith('/roster/5?view=admin');
    });

    it('marks all read', async () => {
        page.props = { notificationsUnread: 1 };
        postJson.mockResolvedValue({ ok: true, status: 200, data: { unread: 0 } });
        wrapper = mount(NotificationBell, { attachTo: document.body });
        await openBell(wrapper);

        document.body.querySelector('[data-testid="notification-read-all"]').click();
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/notifications/read-all', {});
        expect(document.body.querySelector('[data-testid="notification-n-1"]').dataset.read).toBe('true');
        expect(wrapper.find('[data-testid="notification-count"]').exists()).toBe(false);
    });
});
