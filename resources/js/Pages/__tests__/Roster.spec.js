import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import { TooltipProvider } from '@/components/ui/tooltip';
import Roster from '../Roster.vue';
import { CURRENT, roster, WEEKS } from '../../components/roster/__tests__/fixture';

const replace = vi.fn();
const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { success: (...a) => toastSuccess(...a), error: (...a) => toastError(...a) } }));
const post = vi.fn();
const mockPage = { url: '/roster/1', props: {} };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { props: ['title'], template: '<head :data-title="title"></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { replace: (...a) => replace(...a), post: (...a) => post(...a), on: () => () => {} },
    usePage: () => mockPage,
}));
vi.mock('@/components/roster/TrooperSheet.vue', () => ({
    default: { props: ['trooperId', 'loaSync'], template: '<div data-testid="sheet-stub" :data-loa-source="loaSync?.source">{{ trooperId }}</div>' },
}));

function props(over = {}) {
    const { units, troopers } = roster();

    return {
        unit: { id: 1, type: 'platoon', name: '1st Platoon', callsign: '1/A/1-7' },
        trail: [
            { id: 8, type: 'company', name: 'Alpha Company', callsign: 'A/1-7', inScope: false },
            { id: 1, type: 'platoon', name: '1st Platoon', callsign: '1/A/1-7', inScope: true },
        ],
        scopeOptions: [{ id: 1, type: 'platoon', name: '1st Platoon', callsign: '1/A/1-7', depth: 3 }],
        weeks: WEEKS,
        currentWeek: CURRENT,
        units,
        troopers,
        sync: { source: 'milpac', lastSuccessAt: '2026-10-08T09:14:00+00:00', stale: false, lastAttempt: null },
        loaSync: { source: 'Forum thread', lastSyncedAt: '2026-10-08T09:14:00+00:00', stale: false, neverSynced: false, latestStatus: 'success', latestFinishedAt: '2026-10-08T09:14:00+00:00' },
        can: { sync: false },
        links: { milpac: 'https://7cav.us/rosters/profile?uniqueid=', rollcall: '/rollcall/' },
        ...over,
    };
}

// Unmounted after each test, so the shared relative-time clock (useNow) starts fresh.
enableAutoUnmount(afterEach);

// The shell's SidebarProvider supplies the tooltip context on a real page.
const factory = (over) => mount({ render: () => h(TooltipProvider, () => h(Roster, props(over))) });

describe('Roster page', () => {
    beforeEach(() => {
        replace.mockReset();
        post.mockReset();
        mockPage.url = '/roster/1';
        mockPage.props = {};
    });

    it('shows the guests panel when the server sends one, and none for a battalion or the regiment', () => {
        const guests = { criteria: { minVisits: 3, minOwnSpPercent: 75, windowWeeks: 12 }, rows: [] };

        expect(factory({ guests }).find('[data-testid="practice-guests"]').text()).toContain("1/A/1-7's practices");
        expect(factory().find('[data-testid="practice-guests"]').exists()).toBe(false);
    });

    it('opens on the order of battle and keeps the chosen view in the URL', async () => {
        const wrapper = factory();
        expect(wrapper.find('[data-testid="roster-orbat"]').exists()).toBe(true);

        // Reka's tab trigger activates on mousedown.
        await wrapper.find('[data-testid="view-matrix"]').trigger('mousedown', { button: 0 });

        expect(wrapper.find('[data-testid="roster-matrix"]').exists()).toBe(true);
        expect(replace).toHaveBeenLastCalledWith(expect.objectContaining({ url: '/roster/1?view=matrix', preserveState: true }));
    });

    it('shows a saved guidon next to the unit and in the picker, and nothing for a unit with none (#446)', () => {
        const guidon = { primary: '#1f2a44', secondary: '#ffffff', topLeft: '2/7', bottomRight: 'A' };
        const withGuidon = factory({ unit: { id: 1, type: 'company', name: 'Alpha Company', callsign: 'A/2-7', guidon } });

        expect(withGuidon.find('#roster-title [data-testid="guidon"]').exists()).toBe(true);
        expect(factory().find('[data-testid="guidon"]').exists()).toBe(false);
    });

    it('offers Edit guidon only when the server says the viewer may save it', () => {
        expect(factory().find('[data-testid="roster-guidon"]').exists()).toBe(false);
        expect(factory({ can: { sync: false, editGuidon: true }, unit: { id: 1, type: 'company', name: 'Alpha Company', callsign: 'A/2-7', guidon: null } }).find('[data-testid="roster-guidon"]').text()).toBe('Edit guidon');
    });

    it('titles the tab with the unit and the portal name', () => {
        expect(factory().find('head').attributes('data-title')).toBe('Roster 1/A/1-7 · 7Cav Leadership Portal');
    });

    it('starts on the view the URL asks for', () => {
        mockPage.url = '/roster/1?view=matrix';

        expect(factory().find('[data-testid="roster-matrix"]').exists()).toBe(true);
    });

    it('summarises the unit with KPIs from the server', () => {
        expect(factory().find('[data-testid="scope-line"]').text()).toBe('1st Platoon. 4 troopers in 2 sections. RC 30d 83%, SP 30d 80%.');
    });

    it('shows the roster source and last successful sync, flagging a failed attempt (AD-20)', () => {
        expect(factory().find('[data-testid="roster-sync-stamp"]').text()).toContain('milpac, synced');

        const stale = factory({ sync: { source: 'milpac', lastSuccessAt: '2026-10-08T09:14:00+00:00', stale: true, lastAttempt: { status: 'failure', at: '2026-10-08T10:14:00+00:00' } } });
        expect(stale.find('[data-testid="roster-sync-stamp"]').text()).toContain('last attempt failed');

        const partial = factory({ sync: { source: 'milpac', lastSuccessAt: '2026-10-08T09:14:00+00:00', stale: true, lastAttempt: { status: 'partial', at: '2026-10-08T10:14:00+00:00' } } });
        expect(partial.find('[data-testid="roster-sync-stamp"]').text()).toContain('last attempt incomplete');

        // Stale only by age: the latest run succeeded, so there is no failed attempt to name.
        const overdue = factory({ sync: { source: 'milpac', lastSuccessAt: '2026-10-08T03:14:00+00:00', stale: true, lastAttempt: null } });
        expect(overdue.find('[data-testid="roster-sync-stamp"]').text()).toContain('overdue');
        expect(overdue.find('[data-testid="roster-sync-stamp"]').text()).not.toContain('failed');
    });

    it('names the leave source and last sync, and hands it to the trooper sheet (4.3, AD-20)', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="loa-sync"]').text()).toBe('Leave from Forum thread, synced 08Oct26 09:14Z');
        expect(wrapper.get('[data-testid="sheet-stub"]').attributes('data-loa-source')).toBe('Forum thread');

        const stale = factory({ loaSync: { ...props().loaSync, stale: true, latestStatus: 'failure' } });
        expect(stale.get('[data-testid="loa-sync"]').attributes('role')).toBe('status');
    });

    it('shows an Admin what the latest leave sync could not read, under the leave note, and nothing without it', () => {
        const run = { status: 'partial', finishedAt: '2026-10-08T12:15:00+00:00', unread: 1, needsReview: 0, failure: null, problems: [{ threadId: 812, text: 'Thread 812: The forum answered 503.' }] };

        expect(factory({ loaSyncProblems: run }).get('[data-testid="loa-sync-problems"]').text()).toContain('1 problem reading the forum');
        expect(factory().find('[data-testid="loa-sync-problems"]').exists()).toBe(false);
    });

    it('adds how long ago the last sync was, and keeps it current', async () => {
        vi.useFakeTimers();
        try {
            vi.setSystemTime(new Date('2026-10-08T11:20:00Z'));
            const wrapper = factory({ sync: { source: 'milpac', lastSuccessAt: '2026-10-08T09:14:00+00:00', stale: false, lastAttempt: null } });
            expect(wrapper.find('[data-testid="roster-sync-stamp"]').text()).toBe('milpac, synced 09:14Z (2 h ago)');

            await vi.advanceTimersByTimeAsync(60 * 60_000);
            expect(wrapper.find('[data-testid="roster-sync-stamp"]').text()).toBe('milpac, synced 09:14Z (3 h ago)');
        } finally {
            vi.useRealTimers();
        }
    });

    it('offers Sync now only to an Admin, posting to the roster sync route', async () => {
        expect(factory().find('[data-testid="roster-sync-now"]').exists()).toBe(false);

        const wrapper = factory({ can: { sync: true } });
        await wrapper.find('[data-testid="roster-sync-now"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/settings/roster-sync', {}, expect.any(Object));
    });

    it('offers a unit leader a sync of just the echelon on screen, and an Admin keeps the whole-roster one', async () => {
        const leader = factory({ can: { sync: false, syncUnit: true } });
        const button = leader.find('[data-testid="roster-sync-now"]');
        expect(button.text()).toContain('Sync this unit');
        await button.trigger('click');
        expect(post).toHaveBeenCalledWith('/units/1/roster-sync', {}, expect.any(Object));
    });

    it('shows a unit leader the result of their own unit sync next to the roster stamp', () => {
        const base = { source: 'milpac', lastSuccessAt: '2026-10-08T09:14:00+00:00', stale: false, lastAttempt: null };
        expect(factory().find('[data-testid="roster-unit-sync"]').exists()).toBe(false);
        expect(factory({ sync: { ...base, unit: null } }).find('[data-testid="roster-unit-sync"]').exists()).toBe(false);

        const ok = factory({ sync: { ...base, unit: { status: 'success', at: '2026-10-08T10:20:00+00:00' } } });
        expect(ok.find('[data-testid="roster-unit-sync"]').text()).toContain('This unit synced');
        expect(ok.find('[data-testid="roster-unit-sync"]').text()).toContain('10:20Z');

        const partial = factory({ sync: { ...base, unit: { status: 'partial', at: '2026-10-08T10:20:00+00:00' } } });
        expect(partial.find('[data-testid="roster-unit-sync"]').text()).toContain('some rows skipped');

        const failed = factory({ sync: { ...base, unit: { status: 'failure', at: '2026-10-08T10:20:00+00:00' } } });
        expect(failed.find('[data-testid="roster-unit-sync"]').text()).toContain("This unit's sync failed");
    });

    it('says why Sync now failed instead of showing a raw error page', async () => {
        const wrapper = factory({ can: { sync: true } });
        await wrapper.find('[data-testid="roster-sync-now"]').trigger('click');
        const options = post.mock.calls[0][2];

        expect(options.onHttpException({ status: 403 })).toBe(false);
        expect(toastError).toHaveBeenLastCalledWith('Could not start the roster sync', {
            description: 'Only an Admin or this unit\'s leader can start a roster sync.',
        });

        options.onNetworkError(new Error('offline'));
        expect(toastError.mock.calls.at(-1)[1].description).toContain('Could not reach the portal');

        options.onSuccess();
        expect(toastSuccess).toHaveBeenCalledWith('Roster sync started', expect.any(Object));
    });

    it('makes Sync now read-only while viewing as someone else', async () => {
        mockPage.props = { viewAs: { kind: 'unit', designation: '2/1/A/1-7', label: 'SGT Vasquez.R', admin: true } };
        const wrapper = factory({ can: { sync: true } });

        expect(wrapper.find('[data-testid="roster-sync-now"]').exists()).toBe(false);
        expect(wrapper.find('[aria-disabled="true"]').text()).toContain('Sync now');
    });

    it('links in-scope ancestors only', () => {
        const crumbs = factory().find('nav[aria-label="Unit"]');

        expect(crumbs.text()).toContain('A/1-7');
        expect(crumbs.find('a').exists()).toBe(false);
    });

    it('explains an empty scope', () => {
        const wrapper = factory({ unit: null, trail: [], scopeOptions: [], units: [], troopers: [] });

        expect(wrapper.text()).toContain('No roster in your scope yet');
    });

    it('explains a unit nobody is placed in yet', () => {
        const { units } = roster();
        const empty = units.map((u) => ({ ...u, kpis: { ...u.kpis, strength: 0 } }));

        expect(factory({ units: empty, troopers: [] }).text()).toContain('No troopers in 1/A/1-7 yet');
    });

    it('opens the trooper sheet for a clicked row and records it in the URL', async () => {
        const wrapper = factory();
        await wrapper.find('[data-testid="trooper-11"]').trigger('click');

        expect(wrapper.find('[data-testid="sheet-stub"]').text()).toBe('11');
        expect(replace).toHaveBeenLastCalledWith(expect.objectContaining({ url: '/roster/1?view=orbat&trooper=11' }));
    });
});
