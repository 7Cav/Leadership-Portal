import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Rollcall from '../Rollcall.vue';

// Story 3.7: the rollcall draws what the server shaped; it decides nothing about who may look and marks nothing yet.

const routerGet = vi.fn();

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance; this unit test does not stand one up.
    Head: { template: '<head></head>' },
    router: { get: (...args) => routerGet(...args) },
}));

afterEach(() => routerGet.mockReset());

const SECTION = { id: 5, name: '1st Section', callsign: '1/1/A/1-7' };
const WEEK = { start: '2026-10-03', end: '2026-10-09', isCurrent: true, previous: '2026-09-26', next: null };
const NO_LOA = { state: 'none', type: null, typeLabel: null, startsAt: null, endsAt: null, expectedReturnAt: null, ongoing: false, overdue: false, endsInDays: null, sourceThread: null, reviewReason: null };
const COVERED = { ...NO_LOA, state: 'covered', type: 'loa', typeLabel: 'LOA', startsAt: '2026-10-01', endsAt: '2026-10-30', endsInDays: 22 };
const REVIEW = { ...NO_LOA, state: 'needs_review', type: 'loa', typeLabel: 'LOA', reviewReason: 'The end date could not be read.' };

const trooper = (id, displayName, rankShort, practice, overrides = {}) => ({
    id,
    displayName,
    rankShort,
    billet: 'Rifleman',
    status: 'combat',
    practice,
    rollcall: 'unsigned',
    loa: NO_LOA,
    ...overrides,
});

const UNMARKED = { value: null, origin: null, source: null };
const TROOPERS = [
    trooper(1, 'PFC Svensson.A', 'PFC', { value: 'present', origin: 'carried', source: null }),
    trooper(2, 'SGT Jay.JH', 'SGT', { value: 'present', origin: 'saved', source: 'manual' }, { rollcall: 'will_attend', billet: 'Section Leader' }),
    trooper(3, 'SPC Adams.P', 'SPC', { value: 'loa', origin: 'leave', source: null }, { loa: COVERED }),
    trooper(4, 'SPC Crooks.A', 'SPC', UNMARKED, { rollcall: 'cannot_attend' }),
];
const SYNC = {
    roster: { source: 'Cav roster', lastSyncedAt: '2026-10-08T09:30:00+00:00', stale: false },
    loa: { source: 'Forum thread', lastSyncedAt: '2026-10-08T10:00:00+00:00', stale: false, neverSynced: false, latestStatus: 'success', latestFinishedAt: '2026-10-08T10:00:00+00:00' },
};

const props = (overrides = {}) => ({
    section: SECTION,
    sections: [SECTION],
    week: WEEK,
    troopers: TROOPERS,
    banner: { eligible: 3, saved: 1, carried: 1, fromWeek: '2026-09-26' },
    sync: SYNC,
    ...overrides,
});

const page = (overrides = {}) => mount(Rollcall, { props: props(overrides) });
const rowOf = (wrapper, name) => wrapper.findAll('[data-testid="trooper-row"]').find((row) => row.get('[data-testid="trooper-name"]').text() === name);

describe('Rollcall', () => {
    describe('the header', () => {
        it('titles the page with the section, and the week in forum convention', () => {
            const wrapper = page();

            expect(wrapper.get('[data-testid="rollcall-title"]').text()).toBe('Rollcall · 1/1/A/1-7 1st Section');
            expect(wrapper.get('[data-testid="week-range"]').text()).toContain('03Oct26 – 09Oct26');
            expect(wrapper.find('[data-testid="current-week"]').exists()).toBe(true);
        });

        it('links to the previous week, and to this week', () => {
            const wrapper = page();

            expect(wrapper.get('[data-testid="previous-week"]').attributes('href')).toBe('/rollcall/5?week=2026-09-26');
            expect(wrapper.get('[data-testid="this-week"]').attributes('href')).toBe('/rollcall/5');
        });

        it('offers no later week from the current one, and links to the next from an earlier one', () => {
            const current = page();
            const earlier = page({ week: { ...WEEK, start: '2026-09-26', end: '2026-10-02', isCurrent: false, previous: '2026-09-19', next: '2026-10-03' } });

            expect(current.find('[data-testid="next-week"]').exists()).toBe(false);
            expect(current.get('[data-testid="next-week-disabled"]').attributes('disabled')).toBeDefined();
            expect(earlier.get('[data-testid="next-week"]').attributes('href')).toBe('/rollcall/5?week=2026-10-03');
            expect(earlier.find('[data-testid="current-week"]').exists()).toBe(false);
        });

        it('lists the sections to pick from only when there is more than one, and goes to the chosen one', async () => {
            const single = page();
            const many = page({ sections: [SECTION, { id: 6, name: '2nd Section', callsign: null }] });

            expect(single.find('[data-testid="section-select"]').exists()).toBe(false);
            expect(many.findAll('[data-testid="section-select"] option').map((o) => o.text())).toEqual(['1/1/A/1-7 1st Section', '2nd Section']);

            const select = many.get('[data-testid="section-select"]');
            select.element.value = '6';
            await select.trigger('change');

            expect(routerGet).toHaveBeenCalledWith('/rollcall/6');
        });
    });

    describe('the roster', () => {
        it('lists the troopers senior first, then by name, numbered', () => {
            const names = page().findAll('[data-testid="trooper-name"]').map((n) => n.text());

            expect(names).toEqual(['SGT Jay.JH', 'SPC Adams.P', 'SPC Crooks.A', 'PFC Svensson.A']);
            expect(page().findAll('[data-testid="trooper-row"]').map((row) => row.get('td').text())).toEqual(['1', '2', '3', '4']);
        });

        it('shows each trooper\'s billet, or says there is none', () => {
            const wrapper = page({ troopers: [trooper(1, 'PFC Svensson.A', 'PFC', UNMARKED, { billet: null })] });

            expect(wrapper.get('[data-testid="trooper-row"]').text()).toContain('No billet on record');
            expect(rowOf(page(), 'SGT Jay.JH').text()).toContain('Section Leader');
        });

        it('shows the rollcall signature, and says so when there is none', () => {
            const signed = rowOf(page(), 'SGT Jay.JH');
            const none = rowOf(page({ troopers: [trooper(1, 'PFC Svensson.A', 'PFC', UNMARKED)] }), 'PFC Svensson.A');

            expect(signed.get('[data-value="will_attend"]').attributes('data-selected')).toBe('true');
            expect(signed.find('[data-testid="unsigned"]').exists()).toBe(false);
            expect(none.findAll('[data-selected="true"]')).toHaveLength(0);
            expect(none.get('[data-testid="unsigned"]').text()).toBe('not signed');
            expect(rowOf(page(), 'SPC Crooks.A').get('[data-value="cannot_attend"]').attributes('data-selected')).toBe('true');
        });

        it('says an empty section has no troopers, and draws no table', () => {
            const wrapper = page({ troopers: [], banner: { eligible: 0, saved: 0, carried: 0, fromWeek: null } });

            expect(wrapper.get('[data-testid="roster-empty"]').text()).toContain('No troopers are placed in this section.');
            expect(wrapper.find('[data-testid="roster"]').exists()).toBe(false);
        });

        it('says there are no sections when the viewer holds none', () => {
            const wrapper = page({ section: null, sections: [], week: null, troopers: [], banner: null, sync: null });

            expect(wrapper.get('[data-testid="no-section"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="roster"]').exists()).toBe(false);
        });
    });

    describe('attendance', () => {
        it('shows a saved mark as selected, with no suggestion note', () => {
            const cell = rowOf(page(), 'SGT Jay.JH').get('[data-testid="sp-cell"]');

            expect(cell.attributes('data-origin')).toBe('saved');
            expect(cell.get('[data-value="present"]').attributes('data-selected')).toBe('true');
            expect(cell.find('[data-testid="carried"]').exists()).toBe(false);
        });

        it('shows a value carried from last week with a dashed edge and says where it came from', () => {
            const cell = rowOf(page(), 'PFC Svensson.A').get('[data-testid="sp-cell"]');

            expect(cell.attributes('data-origin')).toBe('carried');
            expect(cell.get('[data-value="present"]').classes().join(' ')).toContain('outline-dashed');
            expect(cell.get('[data-testid="carried"]').text()).toBe('from last week');
        });

        it('loads an unmarked trooper with nothing selected, and says so', () => {
            const cell = rowOf(page(), 'SPC Crooks.A').get('[data-testid="sp-cell"]');

            expect(cell.findAll('[data-selected="true"]')).toHaveLength(0);
            expect(cell.get('[data-testid="unmarked"]').text()).toBe('not marked');
        });

        it('replaces the toggle with the LOA badge for a trooper on leave', () => {
            const cell = rowOf(page(), 'SPC Adams.P').get('[data-testid="sp-cell"]');

            expect(cell.find('[data-testid="loa-badge"]').exists()).toBe(true);
            expect(cell.text()).toContain('LOA');
            expect(cell.find('[role="group"]').exists()).toBe(false);
        });

        it('keeps the toggle for a leave that needs review, and flags it beside the name', () => {
            const wrapper = page({ troopers: [trooper(1, 'PFC Svensson.A', 'PFC', UNMARKED, { loa: REVIEW })] });
            const row = wrapper.get('[data-testid="trooper-row"]');

            expect(row.get('[data-testid="sp-cell"]').find('[role="group"]').exists()).toBe(true);
            expect(row.get('th [data-testid="loa-badge"]').text()).toContain('needs review');
        });

        it('has no control anywhere: marking is the next story', () => {
            expect(page().findAll('button, input, select, textarea, form').filter((el) => !el.attributes('data-testid')?.startsWith('next-week-disabled'))).toHaveLength(0);
        });
    });

    describe('the pre-fill banner', () => {
        it('says what was carried over and from which week, and what is saved', () => {
            const text = page().get('[data-testid="prefill-banner"]').text();

            expect(text).toContain('Carried "present" over from 26Sep26 for 1 of 3 troopers.');
            expect(text).toContain('1 of 3 already saved for this week.');
            expect(text).toContain('Troopers on leave are shown by their LOA.');
        });

        it('says nothing was pre-filled when there is no earlier week', () => {
            const text = page({ banner: { eligible: 3, saved: 0, carried: 0, fromWeek: null } }).get('[data-testid="prefill-banner"]').text();

            expect(text).toContain('Nothing was pre-filled: this section has no earlier week to carry over.');
        });

        it('says nothing was pre-filled when nobody was present the week before', () => {
            const text = page({ banner: { eligible: 3, saved: 0, carried: 0, fromWeek: '2026-09-26' } }).get('[data-testid="prefill-banner"]').text();

            expect(text).toContain('Nothing was pre-filled: nobody was present on 26Sep26 to carry over.');
        });

        it('does not claim nothing was pre-filled when marks are already saved', () => {
            const text = page({ banner: { eligible: 3, saved: 2, carried: 0, fromWeek: '2026-09-26' } }).get('[data-testid="prefill-banner"]').text();

            expect(text).not.toContain('Nothing was pre-filled');
            expect(text).toContain('2 of 3 already saved');
        });

        it('shows the source legend: auto, manual and makeup', () => {
            const legend = page().get('[data-testid="source-legend"]').text();

            expect(legend).toContain('auto');
            expect(legend).toContain('manual');
            expect(legend).toContain('makeup');
        });
    });

    describe('source and last sync (AD-20)', () => {
        it('names the roster and leave sources with their last sync', () => {
            const lines = page().findAll('[data-testid="sync-line"]').map((l) => l.text());

            expect(lines[0]).toContain('Roster: Cav roster · last synced 08Oct26 09:30 UTC');
            expect(lines[1]).toContain('Leave: Forum thread · last synced 08Oct26 10:00 UTC');
        });

        it('says never synced rather than leave the time blank', () => {
            const wrapper = page({ sync: { ...SYNC, roster: { source: 'Cav roster', lastSyncedAt: null, stale: false } } });

            expect(wrapper.findAll('[data-testid="sync-line"]')[0].text()).toContain('never synced');
        });

        it('says a source is showing its last good copy when the latest sync failed', () => {
            const wrapper = page({ sync: { ...SYNC, loa: { ...SYNC.loa, stale: true } } });

            expect(wrapper.findAll('[data-testid="stale"]')).toHaveLength(1);
            expect(wrapper.get('[data-testid="stale"]').text()).toContain('last good copy');
        });
    });
});
