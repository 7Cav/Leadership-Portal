import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Rollcall from '../Rollcall.vue';

// Stories 3.7 and 3.8: the rollcall draws what the server shaped, decides nothing about who may look, and sends only what a
// leader changed or accepted.

const routerGet = vi.fn();
const routerPost = vi.fn();
const toastSuccess = vi.fn();
let previewing = false;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    // `<Head>` needs a real Inertia app instance; this unit test does not stand one up.
    Head: { template: '<head></head>' },
    router: { get: (...args) => routerGet(...args), post: (...args) => routerPost(...args) },
    usePage: () => ({ props: { viewAs: previewing ? { kind: 'unit', id: 9 } : null } }),
}));

vi.mock('vue-sonner', () => ({ toast: { success: (...args) => toastSuccess(...args) } }));

afterEach(() => {
    routerGet.mockReset();
    routerPost.mockReset();
    toastSuccess.mockReset();
    previewing = false;
});

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

        it('shows where a stored mark came from with a dot: manual, auto, or makeup by its status', () => {
            const wrapper = page({
                troopers: [
                    trooper(1, 'SGT Jay.JH', 'SGT', { value: 'present', origin: 'saved', source: 'manual' }),
                    trooper(2, 'SPC Adams.P', 'SPC', { value: 'missed', origin: 'saved', source: 'auto' }),
                    trooper(3, 'SPC Crooks.A', 'SPC', { value: 'makeup', origin: 'saved', source: 'manual' }),
                ],
            });
            const kind = (name) => rowOf(wrapper, name).get('[data-testid="source-dot"]').attributes('data-source-kind');

            expect([kind('SGT Jay.JH'), kind('SPC Adams.P'), kind('SPC Crooks.A')]).toEqual(['manual', 'auto', 'makeup']);
        });

        it('shows no dot for a value that is not stored: carried, unmarked or on leave', () => {
            const wrapper = page();

            for (const name of ['PFC Svensson.A', 'SPC Crooks.A', 'SPC Adams.P']) {
                expect(rowOf(wrapper, name).find('[data-testid="source-dot"]').exists()).toBe(false);
            }
            expect(rowOf(wrapper, 'SGT Jay.JH').find('[data-testid="source-dot"]').exists()).toBe(true);
        });

        it('draws the shared legend in the banner', () => {
            expect(page().get('[data-testid="prefill-banner"] [data-testid="source-legend"]').text()).toBe('automanualmakeup');
        });

        it('gives a trooper on leave no practice toggle, but still their signature', () => {
            const row = rowOf(page(), 'SPC Adams.P');

            expect(row.get('[data-testid="sp-cell"]').findAll('button')).toHaveLength(0);
            expect(row.get('[data-testid="rc-cell"]').findAll('button')).toHaveLength(2);
        });
    });

    describe('marking (3.8)', () => {
        const click = (row, cell, value) => row.get(`[data-testid="${cell}"] [data-value="${value}"]`).trigger('click');
        const spOf = (wrapper, name) => rowOf(wrapper, name).get('[data-testid="sp-cell"]');
        const lastPost = () => routerPost.mock.calls.at(-1);

        it('takes a saved mark\'s dot away once the leader edits it, since the value is no longer the stored one', async () => {
            const wrapper = page();

            expect(spOf(wrapper, 'SGT Jay.JH').find('[data-testid="source-dot"]').exists()).toBe(true);
            await click(rowOf(wrapper, 'SGT Jay.JH'), 'sp-cell', 'missed');

            expect(spOf(wrapper, 'SGT Jay.JH').find('[data-testid="source-dot"]').exists()).toBe(false);
        });

        it('turns the toggles into buttons', () => {
            const cell = spOf(page(), 'SPC Crooks.A');

            expect(cell.findAll('button')).toHaveLength(4);
            expect(cell.get('[data-value="present"]').attributes('aria-pressed')).toBe('false');
        });

        it('selects what is pressed, says it is unsaved, and counts it', async () => {
            const wrapper = page();

            await click(rowOf(wrapper, 'SPC Crooks.A'), 'sp-cell', 'missed');

            expect(spOf(wrapper, 'SPC Crooks.A').get('[data-value="missed"]').attributes('aria-pressed')).toBe('true');
            expect(spOf(wrapper, 'SPC Crooks.A').get('[data-testid="edited"]').text()).toBe('unsaved');
            expect(wrapper.get('[data-testid="save-summary"]').text()).toContain('2 to save');
        });

        it('sends only what changed or was accepted: a carried present and an edit, never an untouched saved mark', async () => {
            const wrapper = page();

            await click(rowOf(wrapper, 'SPC Crooks.A'), 'sp-cell', 'makeup');
            await wrapper.get('[data-testid="save"]').trigger('click');

            expect(routerPost).toHaveBeenCalledOnce();
            expect(lastPost()[0]).toBe('/rollcall/5');
            expect(lastPost()[1]).toEqual({
                week: '2026-10-03',
                marks: [{ trooper_id: 1, status: 'present' }, { trooper_id: 4, status: 'makeup', rollcall: undefined }].map((m) => JSON.parse(JSON.stringify(m))),
            });
        });

        it('says what saving does to a carried value', () => {
            expect(page().get('[data-testid="save-summary"]').text()).toContain('Saving accepts what was carried over.');
        });

        it('lets a leader take back a carried suggestion, and then sends nothing for it', async () => {
            const wrapper = page();

            await click(rowOf(wrapper, 'PFC Svensson.A'), 'sp-cell', 'present');

            expect(spOf(wrapper, 'PFC Svensson.A').findAll('[data-selected="true"]')).toHaveLength(0);
            expect(wrapper.get('[data-testid="save-summary"]').text()).toContain('Nothing to save.');
        });

        it('does not let a saved mark be cleared by pressing it again', async () => {
            const wrapper = page();

            await click(rowOf(wrapper, 'SGT Jay.JH'), 'sp-cell', 'present');

            expect(spOf(wrapper, 'SGT Jay.JH').get('[data-value="present"]').attributes('aria-pressed')).toBe('true');
        });

        it('sets every trooper not on leave to present with "Mark all present", and leaves the leave row alone', async () => {
            const wrapper = page();

            await wrapper.get('[data-testid="mark-all-present"]').trigger('click');

            for (const name of ['SGT Jay.JH', 'SPC Crooks.A', 'PFC Svensson.A']) {
                expect(spOf(wrapper, name).get('[data-value="present"]').attributes('aria-pressed')).toBe('true');
            }
            expect(spOf(wrapper, 'SPC Adams.P').findAll('button')).toHaveLength(0);
            expect(spOf(wrapper, 'SPC Adams.P').find('[data-testid="loa-badge"]').exists()).toBe(true);
        });

        it('sends "Mark all present" as the same marks a leader would have set row by row, leave rows excluded', async () => {
            const wrapper = page();

            await wrapper.get('[data-testid="mark-all-present"]').trigger('click');
            await wrapper.get('[data-testid="save"]').trigger('click');

            const sent = lastPost()[1].marks.map((m) => m.trooper_id).sort();

            // Jay's present is already saved and unchanged, Adams is on leave: only Svensson (accepted) and Crooks (new).
            expect(sent).toEqual([1, 4]);
            expect(lastPost()[1].marks.every((m) => m.status === 'present')).toBe(true);
        });

        it('sets a signature, and takes it back on a second press', async () => {
            const wrapper = page({ troopers: [trooper(7, 'SPC Hollis.D', 'SPC', UNMARKED)] });
            const pressed = () => rowOf(wrapper, 'SPC Hollis.D').get('[data-testid="rc-cell"] [data-value="will_attend"]').attributes('aria-pressed');

            await click(rowOf(wrapper, 'SPC Hollis.D'), 'rc-cell', 'will_attend');
            expect(pressed()).toBe('true');
            expect(wrapper.get('[data-testid="save-summary"]').text()).toContain('1 to save');

            await click(rowOf(wrapper, 'SPC Hollis.D'), 'rc-cell', 'will_attend');
            expect(pressed()).toBe('false');
            expect(wrapper.get('[data-testid="save-summary"]').text()).toContain('Nothing to save.');
        });

        it('sends a signature set back to unsigned', async () => {
            const wrapper = page();

            await click(rowOf(wrapper, 'SPC Crooks.A'), 'rc-cell', 'cannot_attend');
            await wrapper.get('[data-testid="save"]').trigger('click');

            expect(lastPost()[1].marks).toContainEqual({ trooper_id: 4, rollcall: 'unsigned' });
        });

        it('sends a new signature for a trooper who had none', async () => {
            const wrapper = page({ troopers: [trooper(7, 'SPC Hollis.D', 'SPC', UNMARKED)] });

            await click(rowOf(wrapper, 'SPC Hollis.D'), 'rc-cell', 'will_attend');
            await wrapper.get('[data-testid="save"]').trigger('click');

            expect(lastPost()[1].marks).toEqual([{ trooper_id: 7, rollcall: 'will_attend' }]);
        });

        it('puts everything back with Reset', async () => {
            const wrapper = page();

            await click(rowOf(wrapper, 'SPC Crooks.A'), 'sp-cell', 'missed');
            await wrapper.get('[data-testid="reset"]').trigger('click');

            expect(spOf(wrapper, 'SPC Crooks.A').findAll('[data-selected="true"]')).toHaveLength(0);
            expect(wrapper.get('[data-testid="save-summary"]').text()).toContain('1 to save');
        });

        it('saves an empty roster as an empty list of marks, which only records the practice', async () => {
            const wrapper = page({ troopers: [], banner: { eligible: 0, saved: 0, carried: 0, fromWeek: null } });

            await wrapper.get('[data-testid="save"]').trigger('click');

            expect(lastPost()[1]).toEqual({ week: '2026-10-03', marks: [] });
            expect(wrapper.get('[data-testid="mark-all-present"]').attributes('disabled')).toBeDefined();
        });

        it('says it saved, and shows the server\'s refusal beside the buttons', async () => {
            const wrapper = page();
            await wrapper.get('[data-testid="save"]').trigger('click');

            const options = lastPost()[2];
            options.onSuccess();
            options.onFinish();
            expect(toastSuccess).toHaveBeenCalledWith('Rollcall saved');

            await wrapper.get('[data-testid="save"]').trigger('click');
            lastPost()[2].onError({ marks: 'Troopers 4 are on leave for this week.' });
            await wrapper.vm.$nextTick();

            expect(wrapper.get('[data-testid="save-error"]').text()).toBe('Troopers 4 are on leave for this week.');
        });

        it('turns every control off while viewing as someone else, and says why', () => {
            previewing = true;
            const wrapper = page();

            expect(wrapper.get('[data-testid="preview-notice"]').text()).toContain('read-only');
            expect(spOf(wrapper, 'SPC Crooks.A').findAll('button')).toHaveLength(0);
            expect(wrapper.get('[data-testid="save"]').attributes('disabled')).toBeDefined();
            expect(wrapper.get('[data-testid="mark-all-present"]').attributes('disabled')).toBeDefined();
        });

        it('shows no preview notice, and live controls, otherwise', () => {
            const wrapper = page();

            expect(wrapper.find('[data-testid="preview-notice"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="locked-notice"]').exists()).toBe(false);
            expect(wrapper.get('[data-testid="save"]').attributes('disabled')).toBeUndefined();
            expect(wrapper.get('[data-testid="reset"]').attributes('disabled')).toBeUndefined();
            expect(wrapper.get('[data-testid="mark-all-present"]').attributes('disabled')).toBeUndefined();
        });

        it('turns every control off once the week\'s report has been sent, and says the rollcall is locked', () => {
            const wrapper = page({ locked: true });

            expect(wrapper.get('[data-testid="locked-notice"]').text()).toContain('The report for this week has been sent, so its rollcall is locked.');
            expect(wrapper.get('[data-testid="save"]').attributes('disabled')).toBeDefined();
            expect(wrapper.get('[data-testid="reset"]').attributes('disabled')).toBeDefined();
            expect(wrapper.get('[data-testid="mark-all-present"]').attributes('disabled')).toBeDefined();
            expect(wrapper.get('[data-testid="save-summary"]').text()).toContain('Locked');
            expect(wrapper.findAll('[data-testid="sp-cell"] button')).toHaveLength(0);
            expect(wrapper.findAll('[data-testid="rc-cell"] button')).toHaveLength(0);
        });

        it('still shows what a locked week holds, read-only', () => {
            const cell = spOf(page({ locked: true }), 'SGT Jay.JH');

            expect(cell.get('[data-value="present"]').attributes('data-selected')).toBe('true');
            expect(cell.find('button').exists()).toBe(false);
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
