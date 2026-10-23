import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import Rollcall from '../Rollcall.vue';

const page = reactive({ url: '/rollcall/12', props: { auth: { user: { username: 'Vasquez.R', is_admin: false } }, viewAs: null } });
const push = vi.fn((visit) => (page.url = visit.url));
const visit = vi.fn();
const reload = vi.fn();
// Global router listeners (the autosave's leave guard registers `before`).
const listeners = {};
// Runs a visit the way Inertia does: the `before` listeners can refuse it, and then nothing follows.
const visitThroughGuard = (options, run) => (listeners.before?.({ detail: { visit: { method: 'get', only: [], except: [], ...options } } }) === false ? undefined : run());

enableAutoUnmount(afterEach);
const post = vi.fn();
const toastSuccess = vi.fn();

vi.mock('@/lib/postJson', () => ({ postJson: (...args) => post(...args) }));
const toastError = vi.fn();
const toastWarning = vi.fn();
const toastDismiss = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args) => toastSuccess(...args),
        error: (...args) => toastError(...args),
        warning: (...args) => toastWarning(...args),
        dismiss: (...args) => toastDismiss(...args),
    },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { push: (...args) => push(...args), visit: (...args) => visit(...args), reload: (...args) => reload(...args), on: (event, callback) => ((listeners[event] = callback), () => listeners[event] === callback && delete listeners[event]) },
    usePage: () => page,
}));

const WEEK = {
    start: '2026-10-03T00:00:00+00:00',
    date: '2026-10-03',
    closes: '2026-10-09T23:59:00+00:00',
    isCurrent: true,
    previous: '2026-09-26',
    next: null,
    current: '2026-10-03',
};
const SECTION = { id: 12, designation: '2/1/A/1-7', name: '2nd Section', platoon: '1/A/1-7' };
const SECTIONS = [SECTION, { id: 13, designation: '3/1/A/1-7', name: '3rd Section', platoon: '1/A/1-7' }];

const trooper = (id, rollcall, status, extra = {}) => ({
    id, cavId: 1000 + id, rank: 'PFC', name: `Trooper${id}.A`, rollcall, status, source: status ? 'manual' : null, priorStatus: null, ...extra,
});

// One trooper's `LoaCover::toArray()` and the `LoaSyncState::toArray()` the page receives (3.4, 4.3).
const COVER = {
    state: 'covered', type: 'loa', typeLabel: 'LOA', startsAt: '2026-10-01', endsAt: '2026-10-20', expectedReturnAt: null,
    ongoing: false, overdue: false, endsInDays: 12, ended: false, sourceThread: 'https://7cav.us/threads/loa-request.1/', reviewReason: null,
};
const LOA_SYNC = { source: 'Forum thread', lastSyncedAt: '2026-10-08T15:00:00+00:00', stale: false, neverSynced: false, latestStatus: 'success', latestFinishedAt: '2026-10-08T15:00:00+00:00' };

const TEMPLATE = {
    state: 'resolved',
    companyId: 1,
    versionId: 31,
    version: 2,
    body: '[b]{{unit}} Rollcall, week of {{week_start}}[/b]\n{{#troopers}}[tr][td]{{rank}}[/td][td]{{name}}[/td][td]{{#rc_yes}}Will attend{{/rc_yes}}{{#rc_no}}Cannot attend{{/rc_no}}{{#rc_unsigned}}Unsigned{{/rc_unsigned}}[/td][td]{{#sp_missed}}Missed{{/sp_missed}}{{#sp_unmarked}}Not marked{{/sp_unmarked}}{{#sp_present}}Present{{/sp_present}}[/td][/tr]\n{{/troopers}}',
};

function factory(props = {}) {
    return mount(Rollcall, {
        props: {
            section: SECTION,
            sections: SECTIONS,
            week: WEEK,
            troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'cannot_attend', null, { priorStatus: 'missed' }), trooper(3, 'unsigned', null)],
            priorWeekMarked: true,
            atRisk: [],
            roster: { source: 'milpac', syncedAt: '2026-10-08T09:14:00+00:00' },
            practice: { lastMarkedAt: null },
            template: TEMPLATE,
            canWrite: true,
            ...props,
        },
        attachTo: document.body,
        global: { stubs: { Dialog: true, DialogContent: true, SectionPicker: { props: ['sections'], template: '<div data-picker>{{ sections.length }}</div>' } } },
    });
}

describe('Rollcall page', () => {
    // Relative times ("30 min ago") read the clock; tests that check them pin it with setSystemTime.
    afterEach(() => vi.useRealTimers());

    beforeEach(() => {
        page.url = '/rollcall/12';
        page.props.viewAs = null;
        push.mockClear();
        reload.mockClear();
        post.mockReset().mockResolvedValue({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } });
        toastSuccess.mockClear();
        document.body.innerHTML = '';
    });

    it('titles the week in forum date convention and shows where the roster came from (AD-20)', () => {
        vi.setSystemTime(new Date('2026-10-08T16:50:00Z'));
        const wrapper = factory();

        expect(wrapper.find('h1').text()).toBe('Rollcall, week of 03Oct26');
        expect(wrapper.find('[data-testid="roster-provenance"]').text()).toBe('Roster from milpac, synced 08Oct26 09:14Z (7 h ago)');
        expect(wrapper.find('[data-testid="roster-provenance"] time').attributes('datetime')).toBe('2026-10-08T09:14:00.000Z');
        expect(wrapper.text()).toContain('Signatures close Fri 09Oct26 23:59Z');
    });

    it('says the roster has never synced rather than inventing a time', () => {
        expect(factory({ roster: { source: 'milpac', syncedAt: null } }).find('[data-testid="roster-provenance"]').text()).toBe('Roster from milpac, not synced yet');
    });

    it('opens on step 1 with the unsigned listed first', () => {
        const wrapper = factory();
        const groups = wrapper.findAll('[role="group"][aria-label]').filter((g) => !g.attributes('aria-label').includes(' for '));

        expect(wrapper.find('h2').text()).toBe('Signatures');
        expect(groups.map((g) => g.attributes('aria-label'))).toEqual(['Unsigned', 'Cannot attend', 'Will attend']);
    });

    it('deep-links a step through the URL', async () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory();

        expect(wrapper.find('h2').text()).toBe('Section practice');
        expect(wrapper.find('[data-step="2"]').attributes('aria-current')).toBe('step');

        await wrapper.find('[data-step="3"]').trigger('click');
        expect(push).toHaveBeenCalledWith(expect.objectContaining({ url: '/rollcall/12?step=3', preserveState: true }));
    });

    describe('opening step', () => {
        const PAST = { ...WEEK, isCurrent: false, date: '2026-09-26', start: '2026-09-26T00:00:00+00:00', closes: '2026-10-02T23:59:00+00:00', next: '2026-10-03' };
        const heading = (wrapper) => wrapper.find('h2').text();

        it('opens on Signatures while someone is unsigned and the thread is open', () => {
            const wrapper = factory({ troopers: [trooper(1, 'unsigned', null), trooper(2, 'will_attend', 'present')] });

            expect(heading(wrapper)).toBe('Signatures');
            expect(wrapper.find('[data-step="1"]').attributes('aria-current')).toBe('step');
        });

        it('opens on Section practice once everyone signed and someone is unmarked', () => {
            const wrapper = factory({ troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'cannot_attend', null)] });

            expect(heading(wrapper)).toBe('Section practice');
            expect(wrapper.find('[data-step="2"]').attributes('aria-current')).toBe('step');
        });

        it('skips closed signatures on a past week and opens on Section practice', () => {
            expect(heading(factory({ week: PAST, troopers: [trooper(1, 'unsigned', null)] }))).toBe('Section practice');
        });

        it('opens on Review and post when everyone is marked', () => {
            expect(heading(factory({ troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'cannot_attend', 'missed')] }))).toBe('Review and post');
            expect(heading(factory({ week: PAST, troopers: [trooper(1, 'unsigned', 'missed')] }))).toBe('Review and post');
        });

        it('leaves troopers on LOA out of the work left', () => {
            expect(heading(factory({ troopers: [trooper(1, 'unsigned', 'loa'), trooper(2, 'will_attend', 'present')] }))).toBe('Review and post');
        });

        it('lets a step in the URL win', () => {
            page.url = '/rollcall/12?step=1';

            expect(heading(factory({ troopers: [trooper(1, 'will_attend', 'present')] }))).toBe('Signatures');
        });

        it('lets a clicked step win', async () => {
            const wrapper = factory({ troopers: [trooper(1, 'will_attend', 'present')] });

            await wrapper.find('[data-step="1"]').trigger('click');

            expect(heading(wrapper)).toBe('Signatures');
        });

        it('stays on the step when its last trooper is marked', async () => {
            const wrapper = factory({ troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'will_attend', null)] });

            await wrapper.find('[data-trooper="2"] button[data-value="present"]').trigger('click');

            expect(heading(wrapper)).toBe('Section practice');
        });
    });

    it('lists "said they cannot attend" first on the practice step, and shows last week\'s mark as the pre-fill', () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory();

        expect(wrapper.find('[data-testid="prefill-banner"]').text()).toContain('Pre-filled from last week');
        const first = wrapper.find('[role="group"][aria-label="Said they cannot attend"]');
        expect(first.text()).toContain('Trooper2.A');
        expect(first.find('[data-testid="prior-mark"]').text()).toBe('Last week: Missed');
    });

    it('says nothing was pre-filled when last week has no marks', () => {
        page.url = '/rollcall/12?step=2';

        expect(factory({ priorWeekMarked: false, troopers: [trooper(3, 'unsigned', null)] }).find('[data-testid="prefill-banner"] p').text()).toBe(
            'Nothing pre-filled: no practice marks were recorded last week.',
        );
    });

    it('locks an LOA row: no status buttons, an LOA lock instead', () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory({ troopers: [trooper(4, 'unsigned', 'loa')] });

        const card = wrapper.find('[data-trooper="4"]');
        expect(card.text()).toContain('LOA, nothing to mark');
        expect(card.findAll('button[data-value]')).toHaveLength(0);
    });

    it('shows each practice mark\'s source, and the shared legend (3.9)', () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory({
            troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'will_attend', 'present', { source: 'auto' }), trooper(3, 'will_attend', 'makeup'), trooper(5, 'will_attend', null)],
        });
        const provenance = (id) => wrapper.find(`[data-trooper="${id}"] [data-provenance]`).attributes('data-provenance');

        expect([1, 2, 3, 5].map(provenance)).toEqual(['manual', 'auto', 'makeup', 'unmarked']);
        expect(wrapper.find('[aria-label="Attendance source legend"]').text()).toContain('Auto');
    });

    it('shows the signature, not a practice source, on the signature step', () => {
        const wrapper = factory();

        expect(wrapper.find('[data-trooper="3"] [data-testid="signature-line"]').text()).toBe('Not signed yet');
        expect(wrapper.find('[data-trooper="1"] [data-testid="signature-line"]').text()).toBe('Signed: Will attend');
    });

    it('renders an empty roster as an empty state, not an error', () => {
        expect(factory({ troopers: [] }).text()).toContain('No troopers placed in this section');
    });

    it('flags a past week', () => {
        const wrapper = factory({ week: { ...WEEK, isCurrent: false, date: '2026-09-26', start: '2026-09-26T00:00:00+00:00', next: '2026-10-03' } });

        expect(wrapper.find('[data-testid="past-week-notice"]').exists()).toBe(true);
    });

    // "Changes correct that week's record" beside a notice that nothing here can change would contradict it.
    it.each([
        ['a week before the week floor', { week: { ...WEEK, isCurrent: false, beforeFloor: true, next: '2026-10-10' } }, false],
        ['a section seen through a staff grant', { canWrite: false }, false],
        ['a view-as preview', {}, true],
    ])('does not say a past week is being edited for %s, which is read-only', (_, props, viewAs) => {
        if (viewAs) page.props.viewAs = { label: 'CPL Lindqvist.E', admin: false };
        const wrapper = factory({ week: { ...WEEK, isCurrent: false, date: '2026-09-26', start: '2026-09-26T00:00:00+00:00', next: '2026-10-03' }, ...props });

        expect(wrapper.find('[data-testid="read-only-notice"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="past-week-notice"]').exists()).toBe(false);
    });

    describe('unfinished past weeks', () => {
        const STATUS = [
            { date: '2026-09-26', unmarked: 0, posted: true },
            { date: '2026-09-19', unmarked: 3, posted: false },
            { date: '2026-09-12', unmarked: 0, posted: false },
            { date: '2026-09-05', unmarked: 0, posted: true },
        ];

        it('flags the previous-week arrow, naming each unfinished week for screen readers', () => {
            const wrapper = factory({ weekStatus: STATUS });
            const previous = wrapper.find('[data-testid="previous-week"]');

            expect(previous.find('[data-testid="earlier-weeks-flag"]').exists()).toBe(true);
            expect(previous.attributes('aria-label')).toBe(
                'Previous week. 2 earlier weeks need work: 19Sep26 (3 not marked, not posted), 12Sep26 (not posted)',
            );
            expect(previous.attributes('title')).toBe('2 earlier weeks need work: 19Sep26 (3 not marked, not posted), 12Sep26 (not posted)');
        });

        it('flags the arrow on the side the unfinished weeks are, from a past week', () => {
            const wrapper = factory({
                weekStatus: STATUS,
                week: { ...WEEK, isCurrent: false, date: '2026-09-26', start: '2026-09-26T00:00:00+00:00', previous: '2026-09-19', next: '2026-10-03' },
            });

            expect(wrapper.find('[data-testid="earlier-weeks-flag"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="later-weeks-flag"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="next-week"]').attributes('aria-label')).toBe('Next week');

            const older = factory({
                weekStatus: STATUS,
                week: { ...WEEK, isCurrent: false, date: '2026-09-12', start: '2026-09-12T00:00:00+00:00', previous: '2026-09-05', next: '2026-09-19' },
            });
            expect(older.find('[data-testid="earlier-weeks-flag"]').exists()).toBe(false);
            expect(older.find('[data-testid="next-week"]').attributes('aria-label')).toBe('Next week. 1 later week needs work: 19Sep26 (3 not marked, not posted)');
        });

        it('shows no flag when every past week is marked and posted', () => {
            const wrapper = factory({ weekStatus: [{ date: '2026-09-26', unmarked: 0, posted: true }] });

            expect(wrapper.find('[data-testid="earlier-weeks-flag"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="previous-week"]').attributes('aria-label')).toBe('Previous week');
            expect(wrapper.find('[data-testid="previous-week"]').attributes('title')).toBeUndefined();
        });
    });

    it('reviews totals and at-risk troopers on step 3', () => {
        page.url = '/rollcall/12?step=3';
        const wrapper = factory({ atRisk: [{ trooperId: 2, missed: 2, windowWeeks: 4 }] });

        expect(wrapper.find('[data-testid="review-blockers"]').text()).toContain('2 not marked for section practice: Trooper2.A, Trooper3.A');
        expect(wrapper.find('[data-testid="at-risk"]').text()).toContain('PFC Trooper2.A has missed 2 of the last 4 weeks');
    });

    it('shows the section picker when no section is chosen', () => {
        const wrapper = factory({ section: null, troopers: [] });

        expect(wrapper.find('h1').text()).toBe('Choose a section');
        expect(wrapper.find('[data-picker]').text()).toBe('2');
    });

    it('explains an empty scope instead of an empty picker', () => {
        expect(factory({ section: null, sections: [], troopers: [] }).text()).toContain('No sections in your scope');
    });

    describe('marking (3.8)', () => {
        beforeEach(() => vi.useFakeTimers());
        afterEach(() => vi.useRealTimers());

        const button = (wrapper, id, value) => wrapper.find(`[data-trooper="${id}"] button[data-value="${value}"]`);

        it('sets a signature and saves it in the background through rollcall.store', async () => {
            vi.setSystemTime(new Date('2026-10-08T16:50:00Z'));
            const wrapper = factory();

            await button(wrapper, 3, 'cannot_attend').trigger('click');
            expect(button(wrapper, 3, 'cannot_attend').attributes('aria-pressed')).toBe('true');

            await vi.advanceTimersByTimeAsync(1000);
            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 3, rollcall: 'cannot_attend' }] });
            expect(wrapper.find('[data-testid="save-state"]').text()).toBe('Saved 16:20Z (30 min ago)');
            // The save state is a live region: the ticking part is not read out every minute.
            expect(wrapper.find('[data-testid="save-state"] [data-relative]').attributes('aria-hidden')).toBe('true');

            await vi.advanceTimersByTimeAsync(60_000);
            expect(wrapper.find('[data-testid="save-state"]').text()).toBe('Saved 16:20Z (31 min ago)');
        });

        it('offers present, missed, makeup and attached on the practice step, and records a manual source', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();

            expect(wrapper.find('[data-trooper="3"]').findAll('button[data-value]').map((b) => b.attributes('data-value'))).toEqual([
                'present', 'missed', 'makeup', 'attached',
            ]);
            await button(wrapper, 3, 'attached').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);

            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 3, status: 'attached' }] });
        });

        it('marks every unmarked, non-LOA trooper present in one action, and Undo takes it back unsent', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(1, 'will_attend', 'missed'), trooper(2, 'unsigned', null), trooper(3, 'unsigned', null), trooper(4, 'unsigned', 'loa')] });

            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');

            expect(button(wrapper, 2, 'present').attributes('aria-pressed')).toBe('true');
            expect(button(wrapper, 3, 'present').attributes('aria-pressed')).toBe('true');
            expect(button(wrapper, 1, 'missed').attributes('aria-pressed')).toBe('true');
            expect(wrapper.find('[data-trooper="4"]').text()).toContain('LOA, nothing to mark');

            const [title, options] = toastSuccess.mock.calls[0];
            expect(title).toBe('Marked 2 as present');
            await vi.advanceTimersByTimeAsync(1000);
            expect(post).not.toHaveBeenCalled();

            options.action.onClick();
            await wrapper.vm.$nextTick();
            await vi.advanceTimersByTimeAsync(7000);

            expect(button(wrapper, 2, 'present').attributes('aria-pressed')).toBe('false');
            expect(post).not.toHaveBeenCalled();
        });

        it('saves "Mark all present" once the undo window has passed', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null)] });

            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');
            await vi.advanceTimersByTimeAsync(7000);

            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'present' }] });
        });

        it('saves marks held for Undo as soon as the leader moves to another step, and closes the Undo', async () => {
            page.url = '/rollcall/12?step=2';
            toastSuccess.mockReturnValueOnce('undo-1');
            toastDismiss.mockClear();
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null), trooper(3, 'unsigned', null)] });

            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');
            expect(post).not.toHaveBeenCalled();
            await wrapper.find('[data-step="3"]').trigger('click');
            await flushPromises();

            expect(post).toHaveBeenCalledTimes(1);
            expect(post).toHaveBeenCalledWith('/rollcall/12', {
                week: '2026-10-03',
                marks: [
                    { trooper_id: 2, status: 'present' },
                    { trooper_id: 3, status: 'present' },
                ],
            });
            expect(toastDismiss).toHaveBeenCalledWith('undo-1');
            expect(push).toHaveBeenCalledWith(expect.objectContaining({ url: '/rollcall/12?step=3' }));
            await vi.advanceTimersByTimeAsync(7000);
            expect(post).toHaveBeenCalledTimes(1);
        });

        it('saves marks held for Undo when the screen goes away (browser Back rebuilds it from history)', async () => {
            page.url = '/rollcall/12?step=2';
            toastSuccess.mockReturnValueOnce('undo-1');
            toastDismiss.mockClear();
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null)] });

            await wrapper.find('[data-testid="mark-rest-missed"]').trigger('click');
            wrapper.unmount();

            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'missed' }] }, { keepalive: true });
            expect(toastDismiss).toHaveBeenCalledWith('undo-1');
        });

        it('marks every unmarked, non-LOA trooper missed in one action and leaves set marks alone', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({
                troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'unsigned', null), trooper(3, 'cannot_attend', null), trooper(4, 'unsigned', 'loa')],
            });

            await wrapper.find('[data-testid="mark-rest-missed"]').trigger('click');

            expect(button(wrapper, 2, 'missed').attributes('aria-pressed')).toBe('true');
            expect(button(wrapper, 3, 'missed').attributes('aria-pressed')).toBe('true');
            expect(button(wrapper, 1, 'present').attributes('aria-pressed')).toBe('true');
            expect(wrapper.find('[data-trooper="4"]').text()).toContain('LOA, nothing to mark');

            const [title, options] = toastSuccess.mock.calls[0];
            expect(title).toBe('Marked 2 as missed');
            expect(options.description).toBe('Trooper2.A, Trooper3.A');
            expect(options.duration).toBe(6000);
        });

        it('holds "Mark the rest missed" for the undo window, then saves it', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'unsigned', null)] });

            await wrapper.find('[data-testid="mark-rest-missed"]').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);
            expect(post).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(6000);
            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'missed' }] });
        });

        it('takes "Mark the rest missed" back unsent with Undo', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null), trooper(3, 'unsigned', null)] });

            await wrapper.find('[data-testid="mark-rest-missed"]').trigger('click');
            toastSuccess.mock.calls[0][1].action.onClick();
            await wrapper.vm.$nextTick();
            await vi.advanceTimersByTimeAsync(7000);

            expect(button(wrapper, 2, 'missed').attributes('aria-pressed')).toBe('false');
            expect(button(wrapper, 3, 'missed').attributes('aria-pressed')).toBe('false');
            expect(post).not.toHaveBeenCalled();
        });

        it('offers "Mark the rest missed" only while someone is unmarked and the page is writable', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null)] });
            const action = () => wrapper.find('[data-testid="mark-rest-missed"]');

            expect(action().attributes('disabled')).toBeUndefined();
            await button(wrapper, 2, 'present').trigger('click');
            expect(action().exists()).toBe(false);

            page.props.viewAs = { label: 'CPL Lindqvist.E', admin: false };
            expect(factory({ troopers: [trooper(3, 'unsigned', null)] }).find('[data-testid="mark-rest-missed"]').exists()).toBe(false);
        });

        it('applies last week\'s marks to the troopers still to mark', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();

            await wrapper.find('[data-testid="use-last-week"]').trigger('click');

            expect(button(wrapper, 2, 'missed').attributes('aria-pressed')).toBe('true');
            expect(toastSuccess.mock.calls[0][0]).toBe("Used last week's marks for 1");
        });

        it('leaves a trooper on leave out of "Mark all present" and last week\'s marks, under On LOA with their badge (3.4)', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({
                troopers: [
                    trooper(2, 'cannot_attend', null, { priorStatus: 'missed' }),
                    trooper(5, 'unsigned', null, { priorStatus: 'present', loa: COVER }),
                ],
                loaSync: LOA_SYNC,
            });

            const onLoa = wrapper.find('[role="group"][aria-label="On LOA"]');
            expect(onLoa.text()).toContain('Trooper5.A');
            expect(onLoa.find('[data-testid="loa-badge"]').text()).toBe('LOA ends 20Oct26');
            expect(onLoa.find('[data-testid="loa-details"]').text()).toContain('Source: Forum thread');
            expect(wrapper.find('[data-trooper="5"]').findAll('button[data-value]')).toHaveLength(0);
            expect(wrapper.find('[data-testid="use-last-week"]').text()).toBe("Use last week's marks (1)");

            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');
            expect(toastSuccess.mock.calls[0][0]).toBe('Marked 1 as present');
            await vi.advanceTimersByTimeAsync(7000);

            expect(post).toHaveBeenCalledTimes(1);
            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'present' }] });
        });

        it('keeps a trooper whose leave needs review markable, with the badge saying so', () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(6, 'unsigned', null, { loa: { ...COVER, state: 'needs_review', startsAt: null, endsAt: null } })] });

            const card = wrapper.find('[data-trooper="6"]');
            expect(card.find('[data-testid="loa-badge"]').text()).toBe('LOA needs review');
            expect(card.findAll('button[data-value]')).toHaveLength(4);
            expect(wrapper.find('[role="group"][aria-label="On LOA"]').exists()).toBe(false);
        });

        it('locks a trooper the server says went on leave since the page loaded, and says why, not that they moved', async () => {
            post.mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'on leave', rejected: [3], reason: 'loa_covered' } });
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();

            await button(wrapper, 3, 'present').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);

            const card = wrapper.find('[data-trooper="3"]');
            expect(card.text()).toContain('LOA, nothing to mark');
            expect(wrapper.find('[role="group"][aria-label="On LOA"]').text()).toContain('Trooper3.A');
            expect(wrapper.find('[data-testid="save-on-leave"]').text()).toContain('Trooper3.A is on leave this week');
            expect(wrapper.find('[data-testid="save-rejected"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="save-error"]').exists()).toBe(false);
        });

        it.each([
            ['on leave that synced after the page loaded', { message: 'on leave', rejected: [3], reason: 'loa_covered' }, { present: '1', loa: '1' }, 'Trooper3.A LOA'],
            ['moved out of the section', { message: 'not placed', rejected: [3], reason: 'not_placed' }, { present: '1', loa: '0' }, 'Trooper3.A -'],
        ])('stops showing a refused "Mark all present" mark for a trooper %s, on screen and in the forum post', async (_, refusal, counts, line) => {
            post.mockResolvedValueOnce({ ok: false, status: 422, data: refusal });
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({
                troopers: [trooper(2, 'will_attend', null), trooper(3, 'unsigned', null)],
                template: { ...TEMPLATE, body: '{{#troopers}}{{name}} {{#sp_present}}✓{{/sp_present}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}\n{{/troopers}}attended {{attended}}' },
            });
            const summary = (label) => wrapper.findAll('dt').find((dt) => dt.text() === label).element.nextElementSibling.textContent;

            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');
            await vi.advanceTimersByTimeAsync(7000);

            expect(post).toHaveBeenCalledTimes(2);
            expect(post).toHaveBeenLastCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'present' }] });
            expect(wrapper.find('[data-trooper="3"] button[data-value="present"][aria-pressed="true"]').exists()).toBe(false);
            expect(summary('Present')).toBe(counts.present);
            expect(summary('On LOA')).toBe(counts.loa);

            page.url = '/rollcall/12?step=3';
            await wrapper.vm.$nextTick();
            expect(wrapper.find('[data-testid="forum-bbcode"]').text()).toBe(`Trooper2.A ✓\n${line}\nattended 1`);
        });

        it('moves between cards with J and K and sets the focused one with 1-4', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();
            const faces = () => wrapper.findAll('[data-card-face]');

            faces()[0].element.focus();
            await faces()[0].trigger('keydown', { key: 'j' });
            expect(document.activeElement).toBe(faces()[1].element);

            await faces()[1].trigger('keydown', { key: 'k' });
            expect(document.activeElement).toBe(faces()[0].element);

            const id = faces()[0].element.closest('[data-trooper]').dataset.trooper;
            await faces()[0].trigger('keydown', { key: '3' });
            expect(button(wrapper, id, 'makeup').attributes('aria-pressed')).toBe('true');
        });

        describe('when a mark takes the focused card off the "Needs a look" list', () => {
            const needsALook = async () => {
                page.url = '/rollcall/12?step=2';
                const wrapper = factory({ troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'unsigned', null), trooper(3, 'unsigned', null), trooper(4, 'unsigned', null)] });
                await wrapper.findAll('button[aria-pressed]').find((b) => b.text().startsWith('Needs a look')).trigger('click');

                return wrapper;
            };
            const faces = (wrapper) => wrapper.findAll('[data-card-face]').map((face) => face.element);
            const idOf = (element) => element.closest('[data-trooper]').dataset.trooper;
            const press = async (wrapper, element, key) => {
                element.focus();
                element.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
                await wrapper.vm.$nextTick();
                await wrapper.vm.$nextTick();
            };

            it('moves the focus to the card that took its place, so J and K keep working', async () => {
                const wrapper = await needsALook();
                const [first, second, third] = faces(wrapper);
                const [secondId, thirdId] = [idOf(second), idOf(third)];

                await press(wrapper, first, '1');

                expect(faces(wrapper)).toHaveLength(2);
                expect(idOf(document.activeElement)).toBe(secondId);
                document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', bubbles: true }));
                expect(idOf(document.activeElement)).toBe(thirdId);
            });

            it('moves the focus to the card before it when it was the last', async () => {
                const wrapper = await needsALook();
                const [, second, third] = faces(wrapper);

                await press(wrapper, third, '1');

                expect(idOf(document.activeElement)).toBe(idOf(second));
            });

            it('moves the focus to the step heading once no card is left', async () => {
                const wrapper = await needsALook();

                for (let left = 3; left > 0; left--) await press(wrapper, faces(wrapper)[0], '1');

                expect(faces(wrapper)).toHaveLength(0);
                expect(document.activeElement).toBe(wrapper.find('h2').element);
            });
        });

        it('takes the keyboard card and the number keys from the shortcut list', async () => {
            const wrapper = factory();

            // Signatures: three buttons, so 1 to 3, and 4 does nothing.
            expect(wrapper.find('[data-testid="keyboard-move"]').text()).toContain('J K');
            expect(wrapper.find('[data-testid="keyboard-mark"]').text()).toContain('1 to 3');
            const face = wrapper.findAll('[data-card-face]')[0];
            const id = face.element.closest('[data-trooper]').dataset.trooper;
            const pressed = () => ['will_attend', 'cannot_attend', 'unsigned'].map((value) => button(wrapper, id, value).attributes('aria-pressed'));
            const before = pressed();
            face.element.focus();
            await face.trigger('keydown', { key: '4' });
            expect(pressed()).toEqual(before);
            await face.trigger('keydown', { key: '2' });
            expect(button(wrapper, id, 'cannot_attend').attributes('aria-pressed')).toBe('true');

            page.url = '/rollcall/12?step=2';
            const marks = factory();
            expect(marks.find('[data-testid="keyboard-mark"]').text()).toContain('1 to 4');
        });

        it('keeps the input and offers Retry when a save fails', async () => {
            post.mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'Trooper 3 is not placed in section 12.' } });
            const wrapper = factory();

            await button(wrapper, 3, 'will_attend').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);

            expect(wrapper.find('[data-testid="save-error"]').text()).toContain('Trooper 3 is not placed in section 12.');
            expect(button(wrapper, 3, 'will_attend').attributes('aria-pressed')).toBe('true');

            await wrapper.find('[data-testid="save-error"] button').trigger('click');
            await vi.advanceTimersByTimeAsync(0);
            expect(post).toHaveBeenCalledTimes(2);
            expect(wrapper.find('[data-testid="save-error"]').exists()).toBe(false);
        });

        it('is read-only during a view-as preview', async () => {
            page.props.viewAs = { label: 'CPL Lindqvist.E', admin: false };
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();

            expect(wrapper.find('[data-testid="read-only-notice"]').text()).toContain('Read-only while viewing as CPL Lindqvist.E');
            expect(button(wrapper, 3, 'present').attributes('disabled')).toBeDefined();
            expect(button(wrapper, 3, 'present').attributes('title')).toBe('Read-only while viewing as CPL Lindqvist.E');
            expect(wrapper.find('[data-testid="mark-all-present"]').exists()).toBe(false);
        });

        it('is read-only for a section the viewer only sees through a staff grant', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ canWrite: false });

            expect(wrapper.find('[data-testid="read-only-notice"]').text()).toBe('Read-only: a staff grant lets you view this section, not mark it.');
            expect(button(wrapper, 3, 'present').attributes('disabled')).toBeDefined();
            expect(wrapper.find('[data-testid="mark-all-present"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="use-last-week"]').exists()).toBe(false);
        });

        it('is read-only for a week before the week floor, still letting the leader page back', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ week: { ...WEEK, isCurrent: false, beforeFloor: true, next: '2026-10-10' } });

            expect(wrapper.find('[data-testid="read-only-notice"]').text()).toBe('Read-only, this week is too far back to change.');
            expect(button(wrapper, 3, 'present').attributes('disabled')).toBeDefined();
            expect(wrapper.find('[data-testid="mark-all-present"]').exists()).toBe(false);
            expect(wrapper.find('a[aria-label="Previous week"]').attributes('href')).toContain('2026-09-26');
            await button(wrapper, 3, 'present').trigger('click');
            vi.advanceTimersByTime(5000);
            expect(post).not.toHaveBeenCalled();
        });

        it('is read-only once the week\'s report has been sent, like a preview (AD-12)', async () => {
            page.url = '/rollcall/12?step=2';
            const pastWeek = { ...WEEK, isCurrent: false, date: '2026-09-26', start: '2026-09-26T00:00:00+00:00', next: '2026-10-03' };
            const wrapper = factory({ locked: true, week: pastWeek });

            expect(wrapper.find('[data-testid="locked-notice"]').text()).toContain('The report for this week has been sent, so its rollcall is locked.');
            expect(wrapper.find('[data-testid="read-only-notice"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="past-week-notice"]').exists()).toBe(false);
            expect(button(wrapper, 3, 'present').attributes('disabled')).toBeDefined();
            expect(button(wrapper, 3, 'present').attributes('title')).toBe('The report for this week has been sent, so its rollcall is locked.');
            expect(wrapper.find('[data-testid="mark-all-present"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="use-last-week"]').exists()).toBe(false);

            await button(wrapper, 3, 'present').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);
            expect(post).not.toHaveBeenCalled();
        });

        it('turns read-only and stops saving when a save finds the report sent since the page loaded', async () => {
            post.mockResolvedValue({ ok: false, status: 409, data: { message: 'The report for this week has been sent, so its rollcall is locked.', locked: true } });
            const wrapper = factory();

            await button(wrapper, 3, 'will_attend').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);

            expect(wrapper.find('[data-testid="locked-notice"]').text()).toContain('The report for this week has been sent, so its rollcall is locked.');
            expect(wrapper.find('[data-testid="save-error"]').exists()).toBe(false);
            expect(button(wrapper, 1, 'cannot_attend').attributes('disabled')).toBeDefined();
            expect(reload).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(10000);
            expect(post).toHaveBeenCalledTimes(1);
        });

        it('lists this visit\'s changes on the review step', async () => {
            const wrapper = factory();
            await button(wrapper, 3, 'will_attend').trigger('click');
            await wrapper.find('[data-step="3"]').trigger('click');

            const change = wrapper.find('[data-testid="changes"] li');
            expect(change.findAll('span').map((span) => span.text())).toEqual(expect.arrayContaining(['Trooper3.A', 'Signature', 'Unsigned', 'Will attend']));
        });
    });

    describe('forum post (3.10)', () => {
        beforeEach(() => {
            page.url = '/rollcall/12?step=3';
            toastError.mockClear();
        });

        it('shows the post from the active template, with its version, and keeps it in step with the marks', async () => {
            const wrapper = factory();

            expect(wrapper.find('[data-testid="template-version"]').text()).toBe('Template version 2');
            expect(wrapper.find('[data-testid="forum-bbcode"]').text()).toContain('[b]2/1/A/1-7 Rollcall, week of 03Oct26[/b]');
            expect(wrapper.find('[data-testid="forum-bbcode"]').text()).toContain('[tr][td]PFC[/td][td]Trooper3.A[/td][td]Unsigned[/td][td]Not marked[/td][/tr]');

            page.url = '/rollcall/12?step=2';
            await wrapper.vm.$nextTick();
            await wrapper.find('[data-trooper="3"] button[data-value="missed"]').trigger('click');
            page.url = '/rollcall/12?step=3';
            await wrapper.vm.$nextTick();

            expect(wrapper.find('[data-testid="forum-bbcode"]').text()).toContain('[td]Trooper3.A[/td][td]Unsigned[/td][td]Missed[/td]');
        });

        it('renders from whichever template the server sent, so a new version changes the post', () => {
            const wrapper = factory({ template: { ...TEMPLATE, version: 3, body: 'v3 {{unit}} {{count}}' } });

            expect(wrapper.find('[data-testid="template-version"]').text()).toBe('Template version 3');
            expect(wrapper.find('[data-testid="forum-bbcode"]').text()).toBe('v3 2/1/A/1-7 3');
        });

        it('opens on a preview drawn as the forum shows the post, with the BBCode one tab away', async () => {
            const wrapper = factory({ template: { ...TEMPLATE, body: '[B]{{unit}}[/B] [I]{{count}} troopers[/I]' } });
            const panel = (testid) => wrapper.get(`[data-testid="${testid}"]`).element.closest('[role="tabpanel"]');

            expect(wrapper.get('[data-testid="forum-preview"] strong').text()).toBe('2/1/A/1-7');
            expect(wrapper.get('[data-testid="forum-preview"] em').text()).toBe('3 troopers');
            expect(wrapper.get('[data-testid="forum-view-preview"]').attributes('aria-selected')).toBe('true');
            expect(panel('forum-bbcode').hidden).toBe(true);

            await wrapper.get('[data-testid="forum-view-bbcode"]').trigger('mousedown', { button: 0 });

            expect(panel('forum-bbcode').hidden).toBe(false);
            expect(panel('forum-preview').hidden).toBe(true);
            expect(wrapper.get('[data-testid="forum-bbcode"]').text()).toBe('[B]2/1/A/1-7[/B] [I]3 troopers[/I]');
        });

        it.each([
            ['a company with no active template', { state: 'no_active_template', companyId: 1, versionId: null, version: null, body: null }, 'no rollcall template yet'],
            ['a section under no company', { state: 'no_owning_company', companyId: null, versionId: null, version: null, body: null }, 'does not sit under a company'],
            ['a template that no longer renders', { ...TEMPLATE, body: '{{#troopers}}' }, 'does not render'],
        ])('writes no post for %s, says why, and cannot be copied', (_name, template, message) => {
            const wrapper = factory({ template });

            expect(wrapper.find('[data-testid="forum-bbcode"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="forum-post-unavailable"]').text()).toContain(message);
            expect(wrapper.find('[data-testid="copy-bbcode"]').attributes('disabled')).toBeDefined();
        });

        it('offers no way to edit the template from the rollcall', () => {
            const wrapper = factory();

            expect(wrapper.find('textarea').exists()).toBe(false);
            expect(wrapper.find('a[href*="/settings/templates"]').exists()).toBe(false);
        });

        it('copies the generated BBCode to the clipboard, with no request to the server (AD-20)', async () => {
            const writeText = vi.fn().mockResolvedValue();
            Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
            const wrapper = factory();

            await wrapper.find('[data-testid="copy-bbcode"]').trigger('click');
            await vi.waitFor(() => expect(writeText).toHaveBeenCalled());

            expect(writeText.mock.calls[0][0]).toMatch(/^\[b\]2\/1\/A\/1-7 Rollcall, week of 03Oct26\[\/b\]/);
            expect(writeText.mock.calls[0][0]).toContain('[tr][td]PFC[/td][td]Trooper2.A[/td][td]Cannot attend[/td][td]Not marked[/td][/tr]');
            expect(post).not.toHaveBeenCalled();
            await vi.waitFor(() => expect(wrapper.find('[data-testid="copy-bbcode"]').text()).toBe('Copy again'));
            expect(toastSuccess).toHaveBeenCalledWith('BBCode copied', { description: 'Paste it as a reply in the 2/1/A/1-7 rollcall thread.' });
        });

        describe('a copy made before the marks changed', () => {
            const finishList = (wrapper) => wrapper.findAll('aside li').map((item) => item.text());
            const copyThenChange = async () => {
                toastWarning.mockClear();
                Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue() }, configurable: true });
                page.url = '/rollcall/12?step=3';
                const wrapper = factory();
                await wrapper.find('[data-testid="copy-bbcode"]').trigger('click');
                await vi.waitFor(() => expect(wrapper.find('[data-testid="copy-bbcode"]').text()).toBe('Copy again'));
                expect(wrapper.find('aside li').find('svg').exists()).toBe(true);

                page.url = '/rollcall/12?step=2';
                await wrapper.vm.$nextTick();
                await wrapper.find('[data-trooper="3"] button[data-value="present"]').trigger('click');
                page.url = '/rollcall/12?step=3';
                await wrapper.vm.$nextTick();

                return wrapper;
            };

            it('no longer counts as copied once the post it copied has changed', async () => {
                const wrapper = await copyThenChange();

                expect(wrapper.find('[data-testid="copy-bbcode"]').text()).toBe('Copy BBCode');
                expect(finishList(wrapper)[0]).toContain('Copy the updated BBCode');
                expect(wrapper.find('aside li').find('svg').exists()).toBe(false);
            });

            it('warns when the rollcall is marked posted from it, and asks for a repost', async () => {
                const wrapper = await copyThenChange();
                post.mockImplementation(async (url) =>
                    url.endsWith('/posted')
                        ? { ok: true, status: 200, data: { posted: { at: '2026-10-08T16:21:00+00:00', by: 'Vasquez.R' } } }
                        : { ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } },
                );

                await wrapper.find('[data-testid="mark-posted"]').trigger('click');
                await vi.waitFor(() => expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(true));

                expect(toastWarning).toHaveBeenCalledWith('Marked posted, but the copied BBCode is out of date', {
                    description: 'Marks changed after you copied it. Copy it again, update the forum post, then mark it posted again.',
                });
                expect(toastSuccess).not.toHaveBeenCalledWith('Rollcall marked posted', expect.anything());
                expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Repost the updated BBCode');
            });

            it('marks posted as current once the updated post is copied again', async () => {
                const wrapper = await copyThenChange();
                post.mockImplementation(async (url) =>
                    url.endsWith('/posted')
                        ? { ok: true, status: 200, data: { posted: { at: '2026-10-08T16:21:00+00:00', by: 'Vasquez.R' } } }
                        : { ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } },
                );

                await wrapper.find('[data-testid="copy-bbcode"]').trigger('click');
                await vi.waitFor(() => expect(wrapper.find('[data-testid="copy-bbcode"]').text()).toBe('Copy again'));
                await wrapper.find('[data-testid="mark-posted"]').trigger('click');
                await vi.waitFor(() => expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(true));

                expect(toastWarning).not.toHaveBeenCalled();
                expect(toastSuccess).toHaveBeenCalledWith('Rollcall marked posted', expect.anything());
                expect(wrapper.find('[data-testid="posted-state"]').text()).not.toContain('Repost the updated BBCode');
            });
        });

        it('says so when the clipboard is unavailable', async () => {
            vi.spyOn(console, 'error').mockImplementation(() => {});
            Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) }, configurable: true });
            const wrapper = factory();

            await wrapper.find('[data-testid="copy-bbcode"]').trigger('click');
            await vi.waitFor(() =>
                expect(toastError).toHaveBeenCalledWith('Could not copy the BBCode', { description: expect.stringContaining('Open the BBCode tab and copy it by hand.') }),
            );
            expect(wrapper.find('[data-testid="copy-bbcode"]').text()).toBe('Copy BBCode');
        });

        it('says the browser blocked the clipboard, the same way the roll-up reminder does', async () => {
            vi.spyOn(console, 'error').mockImplementation(() => {});
            const denied = Object.assign(new Error('denied'), { name: 'NotAllowedError' });
            Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockRejectedValue(denied) }, configurable: true });
            const wrapper = factory();

            await wrapper.find('[data-testid="copy-bbcode"]').trigger('click');
            await vi.waitFor(() =>
                expect(toastError).toHaveBeenCalledWith('Could not copy the BBCode', { description: expect.stringContaining('blocked clipboard access') }),
            );
            expect(toastSuccess).not.toHaveBeenCalledWith('BBCode copied', expect.anything());
        });

        it('marks the rollcall posted and shows when and by whom', async () => {
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } } });
            vi.setSystemTime(new Date('2026-10-08T16:50:00Z'));
            const wrapper = factory();

            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(true));

            expect(post).toHaveBeenCalledWith('/rollcall/12/posted', { week: '2026-10-03' });
            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Rollcall posted 08Oct26 16:20Z (30 min ago) by Vasquez.R');
            expect(wrapper.find('[data-step="3"]').attributes('aria-label')).toBe('Step 3, Review and post, Posted');
            expect(wrapper.find('[data-testid="mark-posted"]').text()).toBe('Mark posted again');
        });

        it('saves pending marks before marking posted', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();
            await wrapper.find('[data-trooper="3"] button[data-value="present"]').trigger('click');
            page.url = '/rollcall/12?step=3';
            await wrapper.vm.$nextTick();
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } })
                .mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:21:00+00:00', by: 'Vasquez.R' } } });

            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(2));

            expect(post.mock.calls[0][0]).toBe('/rollcall/12');
            expect(post.mock.calls[1][0]).toBe('/rollcall/12/posted');
        });

        it('flags a posted rollcall whose marks changed since, so it gets reposted', async () => {
            const wrapper = factory({ practice: { lastMarkedAt: '2026-10-08T17:00:00+00:00', posted: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } } });

            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Repost the updated BBCode');
            expect(wrapper.find('[data-step="3"]').attributes('aria-label')).toBe('Step 3, Review and post, Not posted yet');
        });

        it('keeps the forum post usable on a locked week: copy and mark posted', async () => {
            const writeText = vi.fn().mockResolvedValue();
            Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } } });
            const wrapper = factory({ locked: true });

            expect(wrapper.find('[data-testid="locked-notice"]').text()).toContain('You can still copy the forum post and mark it posted.');
            await wrapper.find('[data-testid="copy-bbcode"]').trigger('click');
            await vi.waitFor(() => expect(writeText).toHaveBeenCalled());

            expect(wrapper.find('[data-testid="mark-posted"]').attributes('disabled')).toBeUndefined();
            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(true));
            expect(post).toHaveBeenCalledWith('/rollcall/12/posted', { week: '2026-10-03' });
        });

        it('cannot be marked posted during a view-as preview', () => {
            page.props.viewAs = { label: 'CPL Lindqvist.E', admin: false };

            expect(factory().find('[data-testid="mark-posted"]').attributes('disabled')).toBeDefined();
        });
    });

    describe('going back a step with the browser (a screen rebuilt from history)', () => {
        it('reads the marks again, and keeps the marks it opened with as this visit\'s changes', async () => {
            page.url = '/rollcall/12?step=3';
            window.dispatchEvent(new PopStateEvent('popstate', { state: null }));
            // The history entry still has trooper 2 unmarked; the server has the present saved since.
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null)] });
            await flushPromises();

            expect(reload).toHaveBeenCalledTimes(1);
            const [options] = reload.mock.calls[0];
            expect(options.only).toEqual(expect.arrayContaining(['troopers', 'practice', 'atRisk', 'weekStatus']));

            await wrapper.setProps({ troopers: [trooper(2, 'unsigned', 'present')] });
            options.onFinish();
            await flushPromises();

            const changes = wrapper.find('[data-testid="changes"]').text();
            expect(changes).toContain('Trooper2.A');
            expect(changes).toContain('Present');
        });

        it('does not read the marks again for a screen the server just answered', async () => {
            page.url = '/rollcall/12?step=3';
            factory();
            await flushPromises();

            expect(reload).not.toHaveBeenCalled();
        });
    });

    describe('failure paths (review)', () => {
        beforeEach(() => {
            toastError.mockClear();
            toastWarning.mockClear();
        });

        it('does not mark posted when the pending marks fail to save', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();
            await wrapper.find('[data-trooper="3"] button[data-value="present"]').trigger('click');
            // Moving to the review step sends the mark at once; marking posted tries it again.
            post.mockResolvedValueOnce({ ok: false, status: 500, data: null }).mockResolvedValueOnce({ ok: false, status: 500, data: null });
            await wrapper.find('[data-step="3"]').trigger('click');
            await flushPromises();

            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(toastError).toHaveBeenCalledWith('Changes are not saved yet', expect.anything()));

            expect(post).toHaveBeenCalledTimes(2);
            expect(post.mock.calls.map(([url]) => url)).toEqual(['/rollcall/12', '/rollcall/12']);
            expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(false);
        });

        it('shows the server\'s reason when marking posted is refused', async () => {
            page.url = '/rollcall/12?step=3';
            post.mockResolvedValueOnce({ ok: false, status: 403, data: { message: 'Outside your scope.' } });
            const wrapper = factory();

            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(toastError).toHaveBeenCalledWith('Could not mark the rollcall posted', { description: 'Outside your scope.' }));
        });

        it('flags the post as stale once a mark changes after marking it posted', async () => {
            page.url = '/rollcall/12?step=3';
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } } });
            const wrapper = factory();
            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(true));

            await wrapper.find('[data-step="2"]').trigger('click');
            await wrapper.find('[data-trooper="3"] button[data-value="missed"]').trigger('click');
            await wrapper.find('[data-step="3"]').trigger('click');

            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Repost the updated BBCode');
            expect(wrapper.find('[data-step="3"]').attributes('aria-label')).toBe('Step 3, Review and post, Not posted yet');
        });

        it('flags the post as stale when a mark changes while marking it posted is in flight', async () => {
            page.url = '/rollcall/12?step=3';
            let answer;
            post.mockImplementation((url) => (url.endsWith('/posted') ? new Promise((resolve) => (answer = resolve)) : Promise.resolve({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:22:00+00:00', atRisk: [] } })));
            const wrapper = factory();
            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await flushPromises();

            await wrapper.find('[data-step="2"]').trigger('click');
            await wrapper.find('[data-trooper="3"] button[data-value="missed"]').trigger('click');
            answer({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:21:00+00:00', by: 'Vasquez.R' } } });
            await flushPromises();
            await wrapper.find('[data-step="3"]').trigger('click');

            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Repost the updated BBCode');
        });

        it('warns when Undo comes too late because marking posted already saved the marks', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null)] });
            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');
            const undo = toastSuccess.mock.calls[0][1].action.onClick;
            await wrapper.find('[data-step="3"]').trigger('click');
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } })
                .mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:21:00+00:00', by: 'Vasquez.R' } } });
            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(2));

            undo();
            expect(toastWarning).toHaveBeenCalledWith('Some marks were already saved', expect.anything());
            await wrapper.find('[data-step="2"]').trigger('click');
            expect(wrapper.find('[data-trooper="2"] button[data-value="present"]').attributes('aria-pressed')).toBe('true');
        });

        it('names troopers whose marks were refused because they left the section', async () => {
            vi.useFakeTimers();
            post.mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'not placed', rejected: [3] } });
            const wrapper = factory();

            await wrapper.find('[data-trooper="3"] button[data-value="will_attend"]').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);

            expect(wrapper.find('[data-testid="save-rejected"]').text()).toContain('Trooper3.A is no longer placed in this section');
            vi.useRealTimers();
        });
    });

    describe('unmatched players (7.7)', () => {
        const CAPTURE = { source: 'crcon', capturedAt: '2026-10-08T12:00:00+00:00' };
        const UNMATCHED = [{ platform: 'hll', platformLabel: 'Hell Let Loose', playerName: 'Trooper1', platformId: '7656119000000001' }];

        beforeEach(() => (page.url = '/rollcall/12?step=2'));

        it('shows the banner line and the drawer for a captured practice with unmatched players', () => {
            const wrapper = factory({ capture: CAPTURE, unmatched: UNMATCHED });

            expect(wrapper.find('[data-testid="unmatched-banner"]').text()).toContain('1 player needs a trooper');
            expect(wrapper.find('[data-testid="unmatched-drawer"]').exists()).toBe(true);
        });

        it('renders neither when the practice was not captured, or nothing is unmatched', () => {
            expect(factory({ capture: null, unmatched: UNMATCHED }).find('[data-testid="unmatched-drawer"]').exists()).toBe(false);
            expect(factory({ capture: CAPTURE, unmatched: [] }).find('[data-testid="unmatched-banner"]').exists()).toBe(false);
        });

        it('takes a linked player off the list and shows the trooper as present from the server', async () => {
            post.mockResolvedValue({ ok: true, status: 200, data: { marked: true, trooper: { id: 3, status: 'present', source: 'auto' }, atRisk: [] } });
            const wrapper = factory({ capture: CAPTURE, unmatched: UNMATCHED });

            await wrapper.find('[data-testid="unmatched-trooper"]').setValue('3');
            await wrapper.find('[data-testid="unmatched-link"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="unmatched-drawer"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="unmatched-banner"]').exists()).toBe(false);
            expect(wrapper.find('[data-trooper="3"]').text()).toContain('Present');
        });

        describe('the unlinked count in the stepper (#176)', () => {
            const TWO = [...UNMATCHED, { platform: 'arma', platformLabel: 'Arma Reforger', playerName: 'Stranger', platformId: 'a-77' }];
            const count = (wrapper) => wrapper.find('[data-step="2"] [data-testid="unlinked-count"]');

            it('shows the count on the practice step and keeps that step a warning, even with everyone marked', () => {
                page.url = '/rollcall/12?step=1';
                const wrapper = factory({ capture: CAPTURE, unmatched: TWO, troopers: [trooper(1, 'will_attend', 'present')] });

                expect(count(wrapper).text()).toBe('2 unlinked players');
                expect(wrapper.find('[data-step="2"]').attributes('aria-label')).toBe('Step 2, Section practice, All marked, 2 unlinked players');
                expect(wrapper.find('[data-step="2"]').classes()).toContain('after:bg-warning/70');
            });

            it('is hidden with nobody unlinked, or with no capture', () => {
                expect(count(factory({ capture: CAPTURE, unmatched: [] })).exists()).toBe(false);
                expect(count(factory({ capture: null, unmatched: UNMATCHED })).exists()).toBe(false);
            });

            it('shows on the other steps too, and the review step sends the leader back to the drawer', async () => {
                page.url = '/rollcall/12?step=3';
                const wrapper = factory({ capture: CAPTURE, unmatched: UNMATCHED });

                expect(count(wrapper).text()).toBe('1 unlinked player');
                expect(wrapper.find('[data-testid="unlinked-review"]').text()).toContain('1 unlinked player seen on the server is not accounted for.');
                await wrapper.find('[data-testid="unlinked-go"]').trigger('click');
                expect(push).toHaveBeenCalledWith(expect.objectContaining({ url: '/rollcall/12?step=2' }));
            });

            it('counts down as players are linked or dismissed, and goes once none are left', async () => {
                post.mockImplementation((url) =>
                    Promise.resolve(
                        url.endsWith('/unmatched/link')
                            ? { ok: true, status: 200, data: { marked: true, trooper: { id: 3, status: 'present', source: 'auto' }, atRisk: [] } }
                            : { ok: true, status: 200, data: { dismissed: true } },
                    ),
                );
                const wrapper = factory({ capture: CAPTURE, unmatched: TWO });
                expect(count(wrapper).text()).toBe('2 unlinked players');

                await wrapper.find('[data-testid="unmatched-7656119000000001"] [data-testid="unmatched-trooper"]').setValue('3');
                await wrapper.find('[data-testid="unmatched-7656119000000001"] [data-testid="unmatched-link"]').trigger('click');
                await flushPromises();
                expect(count(wrapper).text()).toBe('1 unlinked player');

                await wrapper.find('[data-testid="unmatched-a-77"] [data-testid="unmatched-dismiss"]').trigger('click');
                await wrapper.find('[data-testid="unmatched-a-77"] [data-testid="confirm-dismiss"]').trigger('click');
                await flushPromises();
                expect(count(wrapper).exists()).toBe(false);
                expect(wrapper.find('[data-step="2"]').attributes('aria-label')).toBe('Step 2, Section practice, 1 left to mark');
            });
        });

        describe('undoing a dismissal (#176)', () => {
            const DISMISSED_OK = { ok: true, status: 200, data: { dismissed: true } };
            const RESTORED_OK = { ok: true, status: 200, data: { restored: true } };
            const answer = (url) => Promise.resolve(url.endsWith('/unmatched/restore') ? RESTORED_OK : DISMISSED_OK);

            async function dismissTrooper1(wrapper) {
                await wrapper.find('[data-testid="unmatched-dismiss"]').trigger('click');
                await wrapper.find('[data-testid="confirm-dismiss"]').trigger('click');
                await flushPromises();
            }

            it('offers Undo on the dismiss toast, which lists the player again', async () => {
                post.mockImplementation(answer);
                const wrapper = factory({ capture: CAPTURE, unmatched: UNMATCHED });

                await dismissTrooper1(wrapper);
                expect(wrapper.find('[data-testid="unmatched-banner"]').exists()).toBe(false);
                const [title, options] = toastSuccess.mock.calls.at(-1);
                expect(title).toBe('Dismissed Trooper1');
                expect(options.action.label).toBe('Undo');

                await options.action.onClick();
                await flushPromises();

                expect(post).toHaveBeenLastCalledWith('/rollcall/12/unmatched/restore', { week: '2026-10-03', platform: 'hll', platform_id: '7656119000000001' });
                expect(wrapper.find('[data-testid="unmatched-7656119000000001"]').exists()).toBe(true);
                expect(wrapper.find('[data-testid="dismissed-list"]').exists()).toBe(false);
                expect(wrapper.find('[data-step="2"] [data-testid="unlinked-count"]').text()).toBe('1 unlinked player');
                // The row is back as it started, so it can be dismissed again.
                expect(wrapper.find('[data-testid="unmatched-7656119000000001"] [data-testid="unmatched-dismiss"]').exists()).toBe(true);
                expect(wrapper.find('[data-testid="dismiss-confirm"]').exists()).toBe(false);
            });

            it('keeps the drawer for the Dismissed list once the last player is dismissed, and undoes from there', async () => {
                post.mockImplementation(answer);
                const wrapper = factory({ capture: CAPTURE, unmatched: UNMATCHED });

                await dismissTrooper1(wrapper);
                expect(wrapper.find('[data-testid="unmatched-drawer"]').exists()).toBe(true);
                expect(wrapper.find('[data-testid="dismissed-list"] summary').text()).toBe('Dismissed (1)');

                await wrapper.find('[data-testid="dismissed-7656119000000001"] [data-testid="undo-dismiss"]').trigger('click');
                await flushPromises();

                expect(wrapper.find('[data-testid="unmatched-7656119000000001"]').exists()).toBe(true);
                expect(wrapper.find('[data-testid="dismissed-list"]').exists()).toBe(false);
                expect(toastSuccess).toHaveBeenLastCalledWith('Trooper1 is listed again', expect.anything());
            });

            it('lists the dismissed players the page loaded with', () => {
                const wrapper = factory({ capture: CAPTURE, unmatched: [], dismissed: UNMATCHED });

                expect(wrapper.find('[data-testid="dismissed-7656119000000001"]').exists()).toBe(true);
                expect(wrapper.find('[data-step="2"] [data-testid="unlinked-count"]').exists()).toBe(false);
            });

            it('says so and keeps the player dismissed when the undo is refused', async () => {
                post.mockResolvedValue({ ok: false, status: 422, data: { message: 'That player is not dismissed, so there is nothing to undo. Reload to see the current list.' } });
                const wrapper = factory({ capture: CAPTURE, unmatched: [], dismissed: UNMATCHED });

                await wrapper.find('[data-testid="undo-dismiss"]').trigger('click');
                await flushPromises();

                expect(toastError).toHaveBeenCalledWith('Could not undo dismissing Trooper1', {
                    description: 'That player is not dismissed, so there is nothing to undo. Reload to see the current list.',
                });
                expect(wrapper.find('[data-testid="dismissed-7656119000000001"]').exists()).toBe(true);
            });
        });

        const LINKED = { ok: true, status: 200, data: { marked: true, trooper: { id: 3, status: 'present', source: 'auto' }, atRisk: [] } };
        const SAVED = { ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } };
        const pressed = (wrapper, value) => wrapper.find(`[data-trooper="3"] button[data-value="${value}"]`).attributes('aria-pressed');

        it('saves a mark just set before linking, so the server keeps it as manual', async () => {
            // What the server answers once the manual mark is saved: kept, not pre-marked.
            const KEPT = { ok: true, status: 200, data: { marked: false, trooper: { id: 3, status: 'missed', source: 'manual' }, atRisk: [] } };
            post.mockImplementation((url) => Promise.resolve(url.endsWith('/unmatched/link') ? KEPT : SAVED));
            const wrapper = factory({ capture: CAPTURE, unmatched: UNMATCHED });

            await wrapper.find('[data-trooper="3"] button[data-value="missed"]').trigger('click');
            await wrapper.find('[data-testid="unmatched-trooper"]').setValue('3');
            await wrapper.find('[data-testid="unmatched-link"]').trigger('click');
            await flushPromises();

            expect(post.mock.calls.map(([url]) => url)).toEqual(['/rollcall/12', '/rollcall/12/unmatched/link']);
            expect(post.mock.calls[0][1]).toEqual({ week: '2026-10-03', marks: [{ trooper_id: 3, status: 'missed' }] });
            expect(pressed(wrapper, 'missed')).toBe('true');
            expect(toastSuccess).toHaveBeenLastCalledWith('Linked Trooper1 to Trooper3.A', { description: 'Their existing mark was kept.' });
        });

        it('keeps a mark the leader sets while the link is in flight', async () => {
            let answer;
            post.mockImplementation((url) => (url.endsWith('/unmatched/link') ? new Promise((resolve) => (answer = resolve)) : Promise.resolve(SAVED)));
            const wrapper = factory({ capture: CAPTURE, unmatched: UNMATCHED });

            await wrapper.find('[data-testid="unmatched-trooper"]').setValue('3');
            await wrapper.find('[data-testid="unmatched-link"]').trigger('click');
            await flushPromises();
            await wrapper.find('[data-trooper="3"] button[data-value="makeup"]').trigger('click');
            answer(LINKED);
            await flushPromises();

            expect(pressed(wrapper, 'makeup')).toBe('true');
            expect(pressed(wrapper, 'present')).toBe('false');
            expect(toastSuccess).toHaveBeenLastCalledWith('Linked Trooper1 to Trooper3.A', { description: 'The mark you just set stays.' });
        });
    });

    describe('capture review and confirm (7.8)', () => {
        const CAPTURE = { source: 'battlemetrics', capturedAt: '2026-10-08T12:00:00+00:00' };

        beforeEach(() => (page.url = '/rollcall/12?step=2'));

        it('shows the capture source and time with the legend, and the confirm action', () => {
            vi.setSystemTime(new Date('2026-10-08T16:50:00Z'));
            const wrapper = factory({ capture: CAPTURE });

            expect(wrapper.find('[data-testid="capture-banner"]').text()).toContain('Marks filled from BattleMetrics, read 08Oct26 12:00Z (4 h ago).');
            expect(wrapper.find('[data-testid="capture-banner"]').text()).toContain('Auto');
            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Not confirmed');
            expect(wrapper.find('[data-testid="confirm-practice"]').text()).toContain('Confirm practice');
        });

        it('names the game server, not "null", when a capture has no source recorded', () => {
            const wrapper = factory({ capture: { ...CAPTURE, source: null } });

            expect(wrapper.find('[data-testid="capture-banner"]').text()).toContain('Marks filled from the game server, read 08Oct26 12:00Z');
            expect(wrapper.find('[data-testid="capture-banner"]').text()).not.toContain('null');
        });

        it('renders the unchanged manual screen, with no banner or confirm action, when nothing was captured', () => {
            const wrapper = factory({ capture: null });

            expect(wrapper.find('[data-testid="capture-banner"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="confirm-cluster"]').exists()).toBe(false);
        });

        it('confirms through the server and shows the badge', async () => {
            post.mockResolvedValue({ ok: true, status: 200, data: { confirmation: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } } });
            const wrapper = factory({ capture: CAPTURE });

            await wrapper.find('[data-testid="confirm-practice"]').trigger('click');
            await flushPromises();

            expect(post).toHaveBeenCalledWith('/rollcall/12/confirm', { week: '2026-10-03', confirmedAt: null });
            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Confirmed');
        });

        it('sends the confirmation it shows when confirming again, so the server can tell a racing one', async () => {
            post.mockResolvedValue({ ok: true, status: 200, data: { confirmation: { at: '2026-10-09T08:00:00+00:00', by: 'Vasquez.R' } } });
            const wrapper = factory({
                capture: CAPTURE,
                practice: { lastMarkedAt: null, confirmation: { at: '2026-10-08T16:20:00+00:00', by: 'Ortega.M' } },
            });

            await wrapper.find('[data-testid="confirm-practice"]').trigger('click');
            await flushPromises();

            expect(post).toHaveBeenCalledWith('/rollcall/12/confirm', { week: '2026-10-03', confirmedAt: '2026-10-08T16:20:00+00:00' });
        });

        it('stays not confirmed and says so when the server refuses the confirmation', async () => {
            post.mockResolvedValue({ ok: false, status: 403, data: { message: 'Outside your scope.' } });
            const wrapper = factory({ capture: CAPTURE });

            await wrapper.find('[data-testid="confirm-practice"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Not confirmed');
            expect(toastError).toHaveBeenCalledWith('Could not confirm the practice', expect.anything());
            expect(wrapper.find('[data-testid="confirm-practice"]').attributes('disabled')).toBeUndefined();
        });

        it('disables confirming on a locked week, with the reason', () => {
            const wrapper = factory({ capture: CAPTURE, locked: true });
            const confirm = wrapper.find('[data-testid="confirm-practice"]');

            expect(confirm.attributes('disabled')).toBeDefined();
            expect(confirm.attributes('title')).toBe('The report for this week has been sent, so its rollcall is locked.');
        });

        it('reloads the week when a confirmation is refused because the report was sent since the page loaded', async () => {
            reload.mockClear();
            post.mockResolvedValue({ ok: false, status: 409, data: { message: 'The report for this week has been sent, so its rollcall is locked.', locked: true } });
            const wrapper = factory({ capture: CAPTURE });

            await wrapper.find('[data-testid="confirm-practice"]').trigger('click');
            await flushPromises();

            expect(toastError).toHaveBeenCalledWith('Could not confirm the practice', { description: 'The report for this week has been sent, so its rollcall is locked.' });
            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Not confirmed');
            expect(reload).toHaveBeenCalledTimes(1);
        });

        it('starts confirmed from the server, and drops the badge once a saved hand edit clears it', async () => {
            vi.useFakeTimers();
            post.mockResolvedValue({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [], confirmation: null } });
            const wrapper = factory({
                capture: CAPTURE,
                practice: { lastMarkedAt: null, confirmation: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } },
            });
            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Confirmed');

            const missed = wrapper.findAll('[data-trooper="1"] button').find((b) => b.text().startsWith('Missed'));
            await missed.trigger('click');
            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Confirmed');
            await vi.advanceTimersByTimeAsync(1000);

            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Not confirmed');
            vi.useRealTimers();
        });

        it('re-reads confirmed and posted from the practice a reload brings', async () => {
            const wrapper = factory({ capture: CAPTURE });
            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Not confirmed');

            await wrapper.setProps({
                practice: {
                    lastMarkedAt: '2026-10-08T16:00:00+00:00',
                    posted: { at: '2026-10-08T16:20:00+00:00', by: 'Ortega.M' },
                    confirmation: { at: '2026-10-08T16:30:00+00:00', by: 'Ortega.M' },
                },
            });
            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Confirmed');
            await wrapper.find('[data-step="3"]').trigger('click');
            page.url = '/rollcall/12?step=3';
            await wrapper.vm.$nextTick();
            // The stamp carries how long ago it was, which this test does not pin.
            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Rollcall posted 08Oct26 16:20Z');
            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('by Ortega.M');
            expect(wrapper.find('[data-testid="posted-state"]').text()).not.toContain('Repost');

            await wrapper.setProps({ practice: { lastMarkedAt: '2026-10-08T16:40:00+00:00', posted: null, confirmation: null } });
            expect(wrapper.find('[data-testid="confirm-badge"]').text()).toBe('Not confirmed');
            expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(false);
        });

        it('keeps a post flagged stale across a reload while the edit since it is still on its way', async () => {
            const POSTED = { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' };
            const wrapper = factory({ capture: CAPTURE, practice: { lastMarkedAt: '2026-10-08T16:00:00+00:00', posted: POSTED } });

            await wrapper.find('[data-trooper="3"] button[data-value="missed"]').trigger('click');
            await wrapper.setProps({ practice: { lastMarkedAt: '2026-10-08T16:00:00+00:00', posted: { ...POSTED } } });
            page.url = '/rollcall/12?step=3';
            await wrapper.vm.$nextTick();

            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Repost the updated BBCode');
        });
    });

    describe('telemetry unavailable (7.9)', () => {
        const FAILED = { state: 'failed', platformLabel: 'Hell Let Loose', feeds: ['crcon'], lastAttemptAt: '2026-10-08T15:42:00+00:00', canRetry: true };
        const NOT_CONFIGURED = { state: 'not_configured', platformLabel: 'Hell Let Loose', feeds: [], lastAttemptAt: null, canRetry: false };
        let wrapper;
        const click = async () => {
            await wrapper.find('[data-testid="telemetry-retry"]').trigger('click');
            await vi.advanceTimersByTimeAsync(2600);
        };

        beforeEach(() => {
            page.url = '/rollcall/12?step=2';
            reload.mockReset();
            toastError.mockClear();
            toastWarning.mockClear();
            vi.useFakeTimers();
        });
        afterEach(() => vi.useRealTimers());

        it('shows when the server was last tried, says nothing was pre-filled, and leaves the manual flow as it was', () => {
            vi.setSystemTime(new Date('2026-10-08T15:47:00Z'));
            wrapper = factory({ telemetry: FAILED });

            expect(wrapper.find('[data-testid="telemetry-banner"]').text()).toContain(
                'Hell Let Loose server (CRCON) could not be read, last tried 08Oct26 15:42 UTC (5 min ago). Nothing was pre-filled. Mark attendance by hand.',
            );
            expect(wrapper.find('[data-testid="capture-banner"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="unmatched-drawer"]').exists()).toBe(false);
            expect(wrapper.find('[data-provenance="auto"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="mark-all-present"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="use-last-week"]').exists()).toBe(true);
            // Manual marks are confirmed the same way (mockup C).
            expect(wrapper.find('[data-testid="confirm-practice"]').exists()).toBe(true);
        });

        it('names every feed that was tried, and falls back to the game server when no game is known (#176)', () => {
            wrapper = factory({ telemetry: { ...FAILED, feeds: ['crcon', 'battlemetrics'] } });
            expect(wrapper.find('[data-testid="telemetry-banner"]').text()).toMatch(/^Hell Let Loose server \(CRCON, BattleMetrics\) could not be read/);

            wrapper = factory({ telemetry: { ...FAILED, platformLabel: null, feeds: [], canRetry: false } });
            expect(wrapper.find('[data-testid="telemetry-banner"]').text()).toMatch(/^Game server could not be read/);
        });

        it('says telemetry is not configured, with no retry to offer', () => {
            wrapper = factory({ telemetry: NOT_CONFIGURED });

            expect(wrapper.find('[data-testid="telemetry-banner"]').text()).toContain('Telemetry is not configured for Hell Let Loose, so nothing was pre-filled. Mark attendance by hand.');
            expect(wrapper.find('[data-testid="telemetry-retry"]').exists()).toBe(false);
        });

        it('shows no banner for a practice that was never attempted', () => {
            expect(factory({ telemetry: null }).find('[data-testid="telemetry-banner"]').exists()).toBe(false);
        });

        it('asks the server to try again and says out loud when the server is still down', async () => {
            post.mockResolvedValue({ ok: true, status: 202, data: { queued: true } });
            wrapper = factory({ telemetry: FAILED });
            reload.mockImplementation(async (options) => {
                await wrapper.setProps({ telemetry: { ...FAILED, lastAttemptAt: '2026-10-08T16:20:00+00:00' } });
                options.onFinish();
            });

            await click();
            await flushPromises();

            expect(post).toHaveBeenCalledWith('/rollcall/12/capture', { week: '2026-10-03' });
            expect(reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['telemetry', 'capture'] }));
            expect(toastError).toHaveBeenCalledWith('Hell Let Loose server (CRCON) still could not be read', expect.anything());
            expect(wrapper.find('[data-testid="telemetry-retry"]').text()).toBe('Try again');
        });

        it('reloads to show the pre-filled marks once a capture succeeds', async () => {
            post.mockResolvedValue({ ok: true, status: 202, data: { queued: true } });
            wrapper = factory({ telemetry: FAILED });
            reload.mockImplementation(async (options) => {
                if (options.only) await wrapper.setProps({ telemetry: null, capture: { source: 'crcon', capturedAt: '2026-10-08T16:20:00+00:00' } });
                options.onFinish?.();
            });

            await click();
            await flushPromises();

            expect(toastSuccess).toHaveBeenCalledWith('Read CRCON', expect.anything());
            expect(reload).toHaveBeenLastCalledWith({ preserveScroll: true });
            expect(wrapper.find('[data-testid="telemetry-banner"]').exists()).toBe(false);
        });

        it('keeps waiting visibly, then tells the leader to reload, when no result arrives', async () => {
            post.mockResolvedValue({ ok: true, status: 202, data: { queued: true } });
            wrapper = factory({ telemetry: FAILED });
            reload.mockImplementation(async (options) => options.onFinish());

            await click();
            expect(wrapper.find('[data-testid="telemetry-retry"]').text()).toBe('Trying...');
            await vi.advanceTimersByTimeAsync(2500 * 30);
            await flushPromises();

            expect(toastWarning).toHaveBeenCalledWith('Still waiting for the game server', expect.anything());
        });

        it('polls through the leave guard without asking while marks are still held', async () => {
            const confirm = vi.fn().mockReturnValue(false);
            vi.stubGlobal('confirm', confirm);
            post.mockResolvedValue({ ok: true, status: 202, data: { queued: true } });
            wrapper = factory({ telemetry: FAILED });
            reload.mockImplementation((options) =>
                visitThroughGuard(options, async () => {
                    await wrapper.setProps({ telemetry: { ...FAILED, lastAttemptAt: '2026-10-08T16:20:00+00:00' } });
                    options.onFinish();
                }),
            );

            // Held for the undo window, so still unsaved while the poll runs.
            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');
            await click();
            await flushPromises();

            expect(confirm).not.toHaveBeenCalled();
            expect(toastError).toHaveBeenCalledWith('Hell Let Loose server (CRCON) still could not be read', expect.anything());
            expect(wrapper.find('[data-testid="telemetry-retry"]').text()).toBe('Try again');
            vi.unstubAllGlobals();
        });

        it('stops polling once the leader leaves the screen', async () => {
            post.mockResolvedValue({ ok: true, status: 202, data: { queued: true } });
            wrapper = factory({ telemetry: FAILED });
            reload.mockImplementation(async (options) => options.onFinish?.());

            await click();
            expect(reload).toHaveBeenCalledTimes(1);
            wrapper.unmount();
            await vi.advanceTimersByTimeAsync(2500 * 30);
            await flushPromises();

            expect(reload).toHaveBeenCalledTimes(1);
            expect(toastWarning).not.toHaveBeenCalled();
            expect(vi.getTimerCount()).toBe(0);
        });

        describe('when the capture lands while a hand mark is on its way', () => {
            const CAPTURED = { source: 'crcon', capturedAt: '2026-10-08T16:20:00+00:00' };
            // The server's marks: the page's troopers, with every save that answered applied.
            let saved;
            const serverTroopers = () =>
                [trooper(1, 'will_attend', 'present'), trooper(2, 'cannot_attend', null, { priorStatus: 'missed' }), trooper(3, 'unsigned', null)].map((t) =>
                    saved.has(t.id) ? { ...t, status: saved.get(t.id), source: 'manual' } : t,
                );
            const answered = (body) => {
                for (const mark of body.marks) if (mark.status) saved.set(mark.trooper_id, mark.status);

                return { ok: true, status: 200, data: { savedAt: '2026-10-08T16:22:00+00:00', atRisk: [] } };
            };
            const pressed = (id, value) => wrapper.find(`[data-trooper="${id}"] button[data-value="${value}"]`).attributes('aria-pressed');

            beforeEach(() => (saved = new Map()));

            it('saves a mark set during the pre-reload save first, and reloads without asking to leave', async () => {
                const confirm = vi.fn().mockReturnValue(true);
                vi.stubGlobal('confirm', confirm);
                let firstSave;
                post.mockImplementation((url, body) => {
                    if (url.endsWith('/capture')) return Promise.resolve({ ok: true, status: 202, data: { queued: true } });
                    if (!firstSave) return new Promise((resolve) => (firstSave = () => resolve(answered(body))));

                    return Promise.resolve(answered(body));
                });
                wrapper = factory({ telemetry: FAILED });
                reload.mockImplementation((options) =>
                    visitThroughGuard(options, async () => {
                        if (options.only) await wrapper.setProps({ telemetry: null, capture: CAPTURED });
                        else await wrapper.setProps({ troopers: serverTroopers() });
                        options.onFinish?.();
                    }),
                );

                await wrapper.find('[data-testid="telemetry-retry"]').trigger('click');
                await vi.advanceTimersByTimeAsync(2000);
                await wrapper.find('[data-trooper="3"] button[data-value="missed"]').trigger('click');
                // The poll sees the capture and saves the pending mark before showing it.
                await vi.advanceTimersByTimeAsync(600);
                expect(firstSave).toBeTypeOf('function');
                await wrapper.find('[data-trooper="2"] button[data-value="present"]').trigger('click');
                firstSave();
                await flushPromises();

                expect(confirm).not.toHaveBeenCalled();
                expect(reload).toHaveBeenLastCalledWith({ preserveScroll: true });
                expect(post.mock.calls.filter(([url]) => url === '/rollcall/12').map(([, body]) => body.marks)).toEqual([
                    [{ trooper_id: 3, status: 'missed' }],
                    [{ trooper_id: 2, status: 'present' }],
                ]);
                expect(pressed(2, 'present')).toBe('true');
                vi.unstubAllGlobals();
            });

            it('keeps a mark set while the reload is in flight on screen, and saves it', async () => {
                let land;
                post.mockImplementation((url, body) =>
                    Promise.resolve(url.endsWith('/capture') ? { ok: true, status: 202, data: { queued: true } } : answered(body)),
                );
                wrapper = factory({ telemetry: FAILED });
                reload.mockImplementation(async (options) => {
                    if (options.only) {
                        await wrapper.setProps({ telemetry: null, capture: CAPTURED });
                        options.onFinish();

                        return;
                    }
                    // The server reads the marks now; the page gets them a moment later.
                    const troopers = serverTroopers();
                    land = () => wrapper.setProps({ troopers });
                });

                await click();
                await flushPromises();
                expect(land).toBeTypeOf('function');
                await wrapper.find('[data-trooper="1"] button[data-value="missed"]').trigger('click');
                await land();

                expect(pressed(1, 'missed')).toBe('true');
                await vi.advanceTimersByTimeAsync(1000);
                expect(saved.get(1)).toBe('missed');
                expect(pressed(1, 'missed')).toBe('true');
            });
        });

        it('surfaces a refusal and does not poll', async () => {
            post.mockResolvedValue({ ok: false, status: 429, data: { message: 'The game server was just tried. Try again in 41 seconds.' } });
            wrapper = factory({ telemetry: FAILED });

            await click();
            await flushPromises();

            expect(toastError).toHaveBeenCalledWith('Could not try again', { description: 'The game server was just tried. Try again in 41 seconds.' });
            expect(reload).not.toHaveBeenCalled();
            expect(wrapper.find('[data-testid="telemetry-banner"]').exists()).toBe(true);
        });

        it('reloads instead of polling when the retry is refused because the week is locked', async () => {
            post.mockResolvedValue({ ok: false, status: 409, data: { message: 'The report for this week has been sent, so its rollcall is locked.', locked: true } });
            wrapper = factory({ telemetry: FAILED });

            await click();
            await flushPromises();

            expect(toastError).toHaveBeenCalledWith('Could not try again', { description: 'The report for this week has been sent, so its rollcall is locked.' });
            expect(reload).toHaveBeenCalledTimes(1);
            expect(reload).toHaveBeenCalledWith();
        });

        it('disables the retry on a locked week', () => {
            wrapper = factory({ telemetry: FAILED, locked: true });

            expect(wrapper.find('[data-testid="telemetry-retry"]').attributes('disabled')).toBeDefined();
        });

        it('disables the retry in a read-only view-as preview', () => {
            page.props.viewAs = { label: 'SGT Ortega' };
            wrapper = factory({ telemetry: FAILED });

            expect(wrapper.find('[data-testid="telemetry-retry"]').attributes('disabled')).toBeDefined();
        });
    });

    describe('order and fireteams (the owner, 2026-10-16)', () => {
        const FIRETEAMS = {
            show: false,
            order: 'leaders_first',
            section: { show: null, order: null },
            company: { id: 3, designation: 'A/1-7', show: null, order: null },
            can: { edit: true, editPolicy: false },
        };
        const SQUAD = [
            trooper(1, 'will_attend', 'present', { name: 'Able.A', rankShort: 'PVT' }),
            trooper(2, 'will_attend', 'present', { name: 'Ftl.B', rankShort: 'CPL', role: 'ftl' }),
            trooper(3, 'will_attend', 'present', { name: 'Asl.C', rankShort: 'CPL', role: 'asl' }),
            trooper(4, 'will_attend', 'present', { name: 'Member.D', rankShort: 'SPC', fireteamLeaderId: 2 }),
            trooper(5, 'will_attend', 'present', { name: 'Sl.E', rankShort: 'SGT', role: 'sl' }),
        ];
        const names = (wrapper, selector) => wrapper.findAll(`${selector} [data-trooper]`).map((card) => card.text().match(/(Able|Ftl|Asl|Member|Sl)\.\w/)?.[0]);

        it('lists the SL, then the ASL, then everyone by rank in each step-1 group', () => {
            // Everyone has signed and is marked, so without a step the page would open on the review step.
            page.url = '/rollcall/12?step=1';
            const wrapper = factory({ troopers: SQUAD, fireteams: FIRETEAMS });

            expect(names(wrapper, '[role="group"][aria-label="Will attend"]')).toEqual(['Sl.E', 'Asl.C', 'Ftl.B', 'Member.D', 'Able.A']);
        });

        it('numbers the forum post in that order, and shows the fireteam controls on the review step', () => {
            page.url = '/rollcall/12?step=3';
            const wrapper = factory({ troopers: SQUAD, fireteams: FIRETEAMS, template: { ...TEMPLATE, body: '{{#troopers}}{{n}}.{{#role}}{{role}} {{/role}}{{name}};{{/troopers}}' } });

            expect(wrapper.find('[data-testid="fireteams"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="forum-bbcode"]').text()).toBe('1.Section Leader Sl.E;2.Assistant Section Leader Asl.C;3.Ftl.B;4.Member.D;5.Able.A;');
        });

        it('takes a saved fireteam change into the post, and flags a posted rollcall for reposting', async () => {
            page.url = '/rollcall/12?step=3';
            const wrapper = factory({
                troopers: SQUAD,
                fireteams: FIRETEAMS,
                practice: { lastMarkedAt: null, posted: { at: '2026-10-08T16:00:00+00:00', by: 'Vasquez.R' } },
                template: { ...TEMPLATE, body: '{{#troopers}}{{n}}.{{#is_ftl}}(FTL) {{/is_ftl}}{{name}};{{/troopers}}' },
            });
            expect(wrapper.find('[data-testid="posted-state"]').text()).not.toContain('Repost');

            post.mockResolvedValueOnce({
                ok: true,
                status: 200,
                data: {
                    fireteams: { ...FIRETEAMS, show: true, order: 'by_fireteam', section: { show: true, order: 'by_fireteam' } },
                    troopers: SQUAD.map((row) => ({ id: row.id, role: row.role ?? null, fireteamLeaderId: row.fireteamLeaderId ?? null })),
                },
            });
            await wrapper.find('[data-testid="fireteams-show"]').findAll('button')[1].trigger('click');
            await flushPromises();

            expect(post).toHaveBeenCalledWith('/rollcall/12/fireteams/options', { show: true });
            expect(wrapper.find('[data-testid="forum-bbcode"]').text()).toBe('1.Sl.E;2.Asl.C;3.(FTL) Ftl.B;4.Member.D;5.Able.A;');
            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Repost the updated BBCode');
        });

        it('shows the controls read-only during a view-as preview', () => {
            page.url = '/rollcall/12?step=3';
            page.props.viewAs = { label: '1/1/A/1-7' };
            const wrapper = factory({ troopers: SQUAD, fireteams: FIRETEAMS });

            expect(wrapper.find('[data-testid="fireteams-read-only"]').text()).toBe('Read-only while viewing as 1/1/A/1-7');
            expect(wrapper.find('[data-testid="fireteams-show"] button').attributes('disabled')).toBeDefined();
        });
    });

    describe('report deadline banner', () => {
        const REPORT = { due: '2026-10-10T23:59:00+00:00', overdueUntil: '2026-10-31T00:00:00+00:00', sent: false, href: '/reports/12/2026-10-03' };

        beforeEach(() => vi.useFakeTimers({ now: new Date('2026-10-10T18:30:00Z') }));
        afterEach(() => vi.useRealTimers());

        it("shows when the week's report is due within a day, linking to it", () => {
            const banner = factory({ report: REPORT }).find('[data-testid="report-deadline"]');

            expect(banner.text()).toContain('Report due in 5 h');
            expect(banner.find('a').attributes('href')).toBe('/reports/12/2026-10-03');
        });

        it('says overdue after the deadline, and nothing once the report is sent', () => {
            vi.setSystemTime(new Date('2026-10-11T02:00:00Z'));

            expect(factory({ report: REPORT }).find('[data-testid="report-deadline"]').text()).toContain('Report overdue by 2 h');
            expect(factory({ report: { ...REPORT, sent: true } }).find('[data-testid="report-deadline"]').exists()).toBe(false);
        });

        it('shows nothing for an unsent week older than the 4-week window', () => {
            vi.setSystemTime(new Date('2026-10-31T00:00:00Z'));

            expect(factory({ report: REPORT }).find('[data-testid="report-deadline"]').exists()).toBe(false);
        });

        it('shows without the link in a view-as preview', () => {
            page.props.viewAs = { label: 'CPL Lindqvist.E', admin: false };
            const banner = factory({ report: REPORT }).find('[data-testid="report-deadline"]');

            expect(banner.text()).toContain('Report due in 5 h');
            expect(banner.find('a').exists()).toBe(false);
        });

        it('is not on the section picker', () => {
            expect(factory({ section: null, troopers: [] }).find('[data-testid="report-deadline"]').exists()).toBe(false);
        });
    });

    describe('choosing a section', () => {
        it('keeps a "Choose a section" link to the picker, for the same week', () => {
            expect(factory().find('[data-testid="choose-section"]').attributes('href')).toBe('/rollcall?pick=1');
            expect(factory({ week: { ...WEEK, date: '2026-09-26', isCurrent: false } }).find('[data-testid="choose-section"]').attributes('href')).toBe(
                '/rollcall?pick=1&week=2026-09-26',
            );
            expect(factory({ sections: [SECTION] }).find('[data-testid="choose-section"]').exists()).toBe(false);
        });
    });
});

describe('jumping to a trooper from their milpac (?focus=)', () => {
    let scrolled;

    beforeEach(() => {
        page.url = '/rollcall/12';
        push.mockClear();
        document.body.innerHTML = '';
        scrolled = [];
        // happy-dom may not lay pages out; give it the method so the spy has something to wrap.
        Element.prototype.scrollIntoView ??= () => {};
        vi.spyOn(Element.prototype, 'scrollIntoView').mockImplementation(function (options) {
            scrolled.push([this.dataset.trooper, options]);
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    const face = (wrapper, id) => wrapper.find(`[data-trooper="${id}"] [data-card-face]`).element;
    const spotlit = (wrapper) => wrapper.findAll('[data-spotlight]').map((card) => card.attributes('data-trooper'));

    it('opens on the trooper\'s card: scrolled into view, focused and outlined', async () => {
        page.url = '/rollcall/12?focus=2';
        const wrapper = factory();
        await flushPromises();

        expect(wrapper.find('h2').text()).toBe('Signatures');
        expect(document.activeElement).toBe(face(wrapper, 2));
        expect(scrolled).toEqual([['2', { block: 'center' }]]);
        expect(spotlit(wrapper)).toEqual(['2']);
    });

    it('opens a week with no work left on the practice step, since the review step has no cards', async () => {
        page.url = '/rollcall/12?focus=2';
        const wrapper = factory({ troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'cannot_attend', 'missed')] });
        await flushPromises();

        expect(wrapper.find('h2').text()).toBe('Section practice');
        expect(document.activeElement).toBe(face(wrapper, 2));
        expect(spotlit(wrapper)).toEqual(['2']);
    });

    it('works on whichever step the link names', async () => {
        page.url = '/rollcall/12?step=2&focus=3';
        const wrapper = factory();
        await flushPromises();

        expect(wrapper.find('h2').text()).toBe('Section practice');
        expect(document.activeElement).toBe(face(wrapper, 3));
        expect(spotlit(wrapper)).toEqual(['3']);
    });

    it('does nothing without a focus, or for a trooper with no card here', async () => {
        for (const url of ['/rollcall/12', '/rollcall/12?focus=99', '/rollcall/12?focus=abc', '/rollcall/12?focus=-2']) {
            page.url = url;
            const wrapper = factory();
            await flushPromises();

            expect(spotlit(wrapper), url).toEqual([]);
            expect(document.activeElement?.hasAttribute('data-card-face'), url).toBe(false);
            expect(scrolled, url).toEqual([]);
            wrapper.unmount();
        }
    });

    it('lets go of the trooper when the leader moves to another step', async () => {
        page.url = '/rollcall/12?focus=2';
        const wrapper = factory();
        await flushPromises();

        await wrapper.findAll('button').find((b) => b.text().startsWith('Next: section practice')).trigger('click');
        await flushPromises();

        expect(push).toHaveBeenCalledWith(expect.objectContaining({ url: '/rollcall/12?step=2' }));
        expect(spotlit(wrapper)).toEqual([]);
    });
});
