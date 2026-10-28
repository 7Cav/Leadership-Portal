import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Show from '../Show.vue';

const put = vi.fn();
const post = vi.fn();
const del = vi.fn();
let pageProps = {};
// The page's Inertia `before` listener (its leave guard).
let visitGuard = null;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: pageProps }),
    router: {
        put: (...args) => put(...args),
        post: (...args) => post(...args),
        delete: (...args) => del(...args),
        on: (event, callback) => ((visitGuard = callback), () => (visitGuard = null)),
    },
}));

// Whether closing the tab would ask first.
const closeAsks = () => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);

    return event.defaultPrevented;
};

vi.mock('vue-sonner', async (importOriginal) => ({
    ...(await importOriginal()),
    toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}));

const KPIS = { strength: 2, rc: { numerator: 7, denominator: 8 }, sp: { numerator: 3, denominator: 4 }, atRisk: 0, onLoa: 0, makeups: 0 };

function props(overrides = {}) {
    return {
        unit: { id: 5, name: '2nd Section', callsign: '2/1/A/1-7', type: 'section', isLeaf: true, parent: { id: 2, name: '1st Platoon', callsign: '1/A/1-7', type: 'platoon' } },
        week: { start: '2026-10-03T00:00:00+00:00', due: '2026-10-10T23:59:00+00:00', overdueUntil: '2026-10-31T00:00:00+00:00', previous: '2026-09-26', next: null },
        report: { status: 'draft', notes: '', trainingSubject: '', unaccounted: [], savedAt: null, sentAt: null, sentBy: null },
        kpis: KPIS,
        delta: null,
        kpiError: null,
        baseline: { kind: 'first', week: null },
        trend: [{ week: '2026-10-03T00:00:00+00:00', current: true, rc: KPIS.rc, sp: KPIS.sp }],
        troopers: [
            { id: 1, name: 'SGT Vasquez.R', rollcall: 'will_attend', status: 'present', source: 'manual', atRisk: false, missed: 0, onLoa: false },
            { id: 2, name: 'PFC Okafor.T', rollcall: 'will_attend', status: 'missed', source: 'manual', atRisk: false, missed: 1, onLoa: false },
        ],
        names: { 1: 'SGT Vasquez.R', 2: 'PFC Okafor.T' },
        children: null,
        missingChildren: [],
        history: [],
        reasons: [{ value: 'sick', label: 'Sick' }],
        rosterSync: { source: 'milpac', at: null },
        loaSync: { source: 'Forum thread', lastSyncedAt: '2026-10-08T09:14:00+00:00', stale: false, neverSynced: false, latestStatus: 'success', latestFinishedAt: '2026-10-08T09:14:00+00:00' },
        computedAt: '2026-10-08T16:20:00+00:00',
        canWrite: true,
        ...overrides,
    };
}

// Unmounted after each test, so one test's leave guard never answers for the next.
const mounted = [];
const mountShow = (overrides) => {
    const wrapper = mount(Show, { props: props(overrides), attachTo: document.body });
    mounted.push(wrapper);

    return wrapper;
};

beforeEach(() => {
    pageProps = {};
    put.mockReset();
    post.mockReset();
    del.mockReset();
});

afterEach(() => {
    for (const wrapper of mounted.splice(0)) wrapper.unmount();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('Reports/Show', () => {
    it('renders a live draft with its checklist, preview and send bar', () => {
        const wrapper = mountShow();

        expect(wrapper.get('[data-testid="status-badge"]').text()).toBe('Draft');
        expect(wrapper.find('[data-testid="checklist"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="baseline"]').text()).toMatch(/^First report/);
        expect(wrapper.get('[data-testid="trooper-table"]').text()).toContain('PFC Okafor.T');
        expect(wrapper.find('[data-testid="send-bar"]').exists()).toBe(true);
    });

    it("names the leave source and last sync under a live draft's KPIs, and nothing when the server sends none (4.3, AD-20)", () => {
        expect(mountShow().get('[data-testid="loa-sync"]').text()).toBe('Leave from Forum thread, synced 08Oct26 09:14Z');

        document.body.innerHTML = '';
        expect(mountShow({ loaSync: null }).find('[data-testid="loa-sync"]').exists()).toBe(false);
    });

    it('gates the primary send on the checklist but offers "Send anyway"', () => {
        const wrapper = mountShow();

        expect(wrapper.get('[data-testid="send-button"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="send-anyway"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[data-testid="send-bar"]').text()).toContain('3 checks left before you can send');
    });

    it('autosaves the draft after the leader stops typing', async () => {
        vi.useFakeTimers();
        const wrapper = mountShow();

        await wrapper.get('#training-subject').setValue('Room clearing');
        expect(wrapper.get('[data-testid="save-state"]').text()).toContain('Unsaved changes');
        vi.advanceTimersByTime(900);

        expect(put).toHaveBeenCalledWith(
            '/reports/5/2026-10-03',
            { notes: '', training_subject: 'Room clearing', unaccounted: [] },
            expect.objectContaining({ preserveState: true, only: ['report', 'computedAt'] }),
        );
    });

    it('guards leaving while an edit waits out the debounce, and not once it is saved', async () => {
        vi.useFakeTimers();
        const confirm = vi.fn().mockReturnValue(false);
        vi.stubGlobal('confirm', confirm);
        const wrapper = mountShow();

        expect(closeAsks()).toBe(false);
        await wrapper.get('#report-notes').setValue('Comms tighter.');
        expect(wrapper.get('[data-testid="save-state"]').text()).toContain('Unsaved changes');
        expect(closeAsks()).toBe(true);
        expect(visitGuard({ detail: { visit: { method: 'get' } } })).toBe(false);
        expect(confirm).toHaveBeenCalledWith('Your latest changes to this report are not saved yet. Leave anyway?');

        vi.advanceTimersByTime(900);
        put.mock.calls[0][2].onSuccess();
        await flushPromises();
        expect(closeAsks()).toBe(false);
        vi.unstubAllGlobals();
    });

    it('stays unsaved, not "saved", when an edit lands while the previous save is in flight', async () => {
        vi.useFakeTimers();
        const wrapper = mountShow();

        await wrapper.get('#report-notes').setValue('Comms tighter.');
        vi.advanceTimersByTime(900);
        expect(put).toHaveBeenCalledTimes(1);

        await wrapper.get('#report-notes').setValue('Comms tighter. Breaching drills next week.');
        put.mock.calls[0][2].onSuccess();
        await flushPromises();

        expect(wrapper.get('[data-testid="save-state"]').text()).toContain('Unsaved changes');
        expect(closeAsks()).toBe(true);

        vi.advanceTimersByTime(900);
        expect(put).toHaveBeenCalledTimes(2);
        expect(put.mock.calls[1][1].notes).toBe('Comms tighter. Breaching drills next week.');
        put.mock.calls[1][2].onSuccess();
        await flushPromises();
        expect(wrapper.get('[data-testid="save-state"]').text()).toContain('Draft saved');
        expect(closeAsks()).toBe(false);
    });

    it('records a reason for an unaccounted trooper and saves it with the draft', async () => {
        vi.useFakeTimers();
        const wrapper = mountShow();

        await wrapper.get('[data-reason="2"]').setValue('sick');
        vi.advanceTimersByTime(900);

        expect(put.mock.calls[0][1].unaccounted).toEqual([{ trooper_id: 2, reason: 'sick' }]);
    });

    it('sends through the pre-send overview with the reason and the confirmed content', async () => {
        const wrapper = mountShow();

        await wrapper.get('[data-testid="send-anyway"]').trigger('click');
        await flushPromises();
        const dialog = document.querySelector('[data-testid="send-dialog"]');
        expect(dialog.textContent).toContain('Send this report with items open?');
        expect(dialog.textContent).toContain('Nothing is posted outside the portal');
        expect(document.querySelector('#send-reason').value).toMatch(/^Weekly report, sent with open items: /);

        document.querySelector('[data-testid="send-confirm"]').click();
        expect(post).toHaveBeenCalledWith(
            '/reports/5/2026-10-03/send',
            expect.objectContaining({ reason: expect.stringMatching(/^Weekly report/), training_subject: '', notes: '' }),
            expect.any(Object),
        );
    });

    it('cancelling the overview changes nothing', async () => {
        const wrapper = mountShow();

        await wrapper.get('[data-testid="send-anyway"]').trigger('click');
        await flushPromises();
        [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Keep editing')).click();
        await flushPromises();

        expect(post).not.toHaveBeenCalled();
        expect(put).not.toHaveBeenCalled();
    });

    it('says when the draft was last saved, in Zulu and how long ago', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-08T16:40:00Z'));
        const wrapper = mountShow({ report: { status: 'draft', notes: '', trainingSubject: '', unaccounted: [], savedAt: '2026-10-08T16:22:00+00:00', sentAt: null, sentBy: null } });

        expect(wrapper.get('[data-testid="save-state"]').text()).toBe('Draft saved 16:22Z (18 min ago)');
        // The save state is a live region: the ticking part stays out of what a screen reader announces.
        expect(wrapper.get('[data-testid="save-state"] [data-relative]').attributes('aria-hidden')).toBe('true');
    });

    it('renders a sent report read-only from its snapshot, with who sent it', () => {
        vi.setSystemTime(new Date('2026-10-08T18:24:00Z'));
        const wrapper = mountShow({
            report: { status: 'sent', notes: 'Good.', trainingSubject: 'Land nav', unaccounted: [], savedAt: null, sentAt: '2026-10-08T16:24:00+00:00', sentBy: 'SGT Vasquez.R' },
            troopers: null,
        });

        expect(wrapper.get('[data-testid="sent-line"]').text()).toBe('Sent Thu 08Oct26 16:24Z (2 h ago) by SGT Vasquez.R');
        expect(wrapper.find('[data-testid="sent-panel"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="send-bar"]').exists()).toBe(false);
        expect(wrapper.get('#training-subject').attributes('disabled')).toBeDefined();
    });

    it('shows a rate with nothing eligible as a bare dash in the sent panel, not "—%"', () => {
        const wrapper = mountShow({
            report: { status: 'sent', notes: '', trainingSubject: '', unaccounted: [], savedAt: null, sentAt: '2026-10-08T16:24:00+00:00', sentBy: null },
            kpis: { ...KPIS, rc: { numerator: 0, denominator: 0 } },
            troopers: null,
        });

        expect(wrapper.get('[data-testid="sent-panel"]').text()).toContain('RC —, SP 75%, 0 at-risk');
    });

    it('is read-only while viewing as someone else, saying so', async () => {
        vi.useFakeTimers();
        pageProps = { viewAs: { label: 'SGT Haddad.N' } };
        const wrapper = mountShow();

        expect(wrapper.get('[data-testid="send-anyway"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('#report-notes').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Read-only while viewing as SGT Haddad.N');
        vi.advanceTimersByTime(2000);
        expect(put).not.toHaveBeenCalled();
    });

    it('is read-only for a unit the viewer only sees through a staff grant, saying so', async () => {
        vi.useFakeTimers();
        const wrapper = mountShow({ canWrite: false });

        expect(wrapper.get('[data-testid="send-anyway"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="send-button"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('#report-notes').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="save-state"]').text()).toContain('Read-only: a staff grant lets you view this report, not edit or send it');
        vi.advanceTimersByTime(2000);
        expect(put).not.toHaveBeenCalled();
    });

    it('is read-only for a week before the week floor, with no send controls', () => {
        vi.useFakeTimers();
        const wrapper = mountShow({ week: { ...props().week, beforeFloor: true } });

        expect(wrapper.find('[data-testid="send-bar"]').exists()).toBe(false);
        expect(wrapper.get('#report-notes').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Read-only, this week is too far back to change');
        expect(wrapper.find('a[aria-label^="Previous week"]').exists()).toBe(true);
        vi.advanceTimersByTime(2000);
        expect(put).not.toHaveBeenCalled();
    });

    it('shows a roll-up with its children and warns about the ones holding up the chain', async () => {
        const wrapper = mountShow({
            unit: { id: 2, name: '1st Platoon', callsign: '1/A/1-7', type: 'platoon', isLeaf: false, parent: null },
            troopers: null,
            children: [
                { id: 4, name: '1st Section', callsign: '1/1/A/1-7', type: 'section', status: 'sent', kpis: KPIS, sentAt: null, trainingSubject: 'Breaching' },
                { id: 5, name: '2nd Section', callsign: '2/1/A/1-7', type: 'section', status: 'draft', kpis: null, sentAt: null, trainingSubject: null },
            ],
        });

        expect(wrapper.find('#training-subject').exists()).toBe(false);
        expect(wrapper.get('[data-testid="children-table"]').text()).toContain('Not in');

        await wrapper.get('[data-testid="send-anyway"]').trigger('click');
        await flushPromises();
        expect(document.querySelector('[data-testid="send-warning"]').textContent).toContain('Waiting on 2/1/A/1-7');
    });

    it('says when there is no attendance yet rather than showing a misleading zero', () => {
        const wrapper = mountShow({
            troopers: [{ id: 1, name: 'SGT Vasquez.R', rollcall: 'unsigned', status: null, source: null, atRisk: false, missed: 0, onLoa: false }],
        });

        expect(wrapper.find('[data-testid="no-attendance"]').exists()).toBe(true);
    });

    it('drops the pending autosave when the leader sends, freezing what they typed', async () => {
        vi.useFakeTimers();
        const wrapper = mountShow();

        await wrapper.get('#report-notes').setValue('Comms tighter.');
        await wrapper.get('[data-testid="send-anyway"]').trigger('click');
        await flushPromises();
        document.querySelector('[data-testid="send-confirm"]').click();
        vi.advanceTimersByTime(2000);

        expect(put).not.toHaveBeenCalled();
        expect(post.mock.calls[0][1].notes).toBe('Comms tighter.');
    });

    it('keeps the notes and saves them when the overview is cancelled', async () => {
        vi.useFakeTimers();
        const wrapper = mountShow();

        await wrapper.get('#report-notes').setValue('Comms tighter.');
        await wrapper.get('[data-testid="send-anyway"]').trigger('click');
        await flushPromises();
        [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Keep editing')).click();
        await flushPromises();
        vi.advanceTimersByTime(900);

        expect(wrapper.get('#report-notes').element.value).toBe('Comms tighter.');
        expect(post).not.toHaveBeenCalled();
        expect(put.mock.calls[0][1].notes).toBe('Comms tighter.');
    });

    it('shows a failed send inside the overview and keeps it open', async () => {
        post.mockImplementation((url, data, options) => {
            options.onError({ report: 'This report was already sent.' });
            options.onFinish();
        });
        const wrapper = mountShow();

        await wrapper.get('[data-testid="send-anyway"]').trigger('click');
        await flushPromises();
        document.querySelector('[data-testid="send-confirm"]').click();
        await flushPromises();

        expect(document.querySelector('[data-testid="send-dialog"]').textContent).toContain('This report was already sent.');
    });

    it('un-ticks the review when the notes change afterwards', async () => {
        const wrapper = mountShow();

        await wrapper.get('#reviewed').trigger('click');
        expect(wrapper.get('#reviewed').attributes('data-state')).toBe('checked');
        await wrapper.get('#report-notes').setValue('Changed.');

        expect(wrapper.get('#reviewed').attributes('data-state')).toBe('unchecked');
        expect(wrapper.text()).toContain('You edited the notes after confirming');
    });

    it('colours each change by what it means for that KPI', () => {
        const wrapper = mountShow({
            delta: { strength: 1, rcBp: 500, spBp: -300, atRisk: 1, onLoa: 0, makeups: 0 },
            baseline: { kind: 'prior', week: '2026-09-26T00:00:00+00:00' },
        });
        const badge = (key) => wrapper.get(`[data-testid="kpi-grid"] [data-kpi="${key}"] [data-testid="delta"]`);

        expect(badge('rc').text()).toContain('+5');
        expect(badge('rc').classes()).toContain('text-success');
        expect(badge('sp').text()).toContain('-3');
        expect(badge('sp').classes()).toContain('text-destructive');
        expect(badge('atRisk').classes()).toContain('text-destructive');
        expect(wrapper.get('[data-testid="baseline"]').text()).toContain('26Sep26');
    });

    it('copies a plain-text summary of the sent report', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const wrapper = mountShow({
            report: { status: 'sent', notes: 'Good.', trainingSubject: 'Land nav', unaccounted: [], savedAt: null, sentAt: '2026-10-08T16:24:00+00:00', sentBy: 'SGT Vasquez.R' },
            troopers: null,
        });

        await wrapper.get('[data-testid="copy-summary"]').trigger('click');
        await flushPromises();

        const text = writeText.mock.calls[0][0];
        expect(text.split('\n').slice(0, 3)).toEqual([
            '2nd Section (2/1/A/1-7) weekly report, week of 03Oct26',
            'Sent Thu 08Oct26 16:24Z by SGT Vasquez.R',
            'In rollcall 2',
        ]);
        expect(text).toContain('Trained: Land nav');
        expect(text).toContain('Notes:\nGood.');
    });

    it('copies the draft as it stands, saying it is not sent', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const wrapper = mountShow();

        await wrapper.get('#training-subject').setValue('Room clearing');
        await wrapper.get('[data-testid="copy-summary"]').trigger('click');
        await flushPromises();

        expect(writeText.mock.calls[0][0]).toContain('Draft, not sent yet');
        expect(writeText.mock.calls[0][0]).toContain('Trained: Room clearing');
    });

    it('blocks sending when the numbers cannot be computed, saying why', () => {
        const wrapper = mountShow({ kpis: null, kpiError: "Last week's sent report cannot be read." });

        expect(wrapper.get('[data-testid="send-anyway"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[role="alert"]').text()).toContain("Last week's sent report cannot be read.");
    });
});

describe('Reports/Show training topic', () => {
    const PLATOON = { id: 2, name: '1st Platoon', callsign: '1/A/1-7', type: 'platoon' };
    const topic = (enforced, subject = 'Room clearing') => ({ subject, enforced, platoon: PLATOON, setBy: 'SFC Hale.J', setAt: '2026-10-05T12:00:00+00:00' });
    const platoonPage = (overrides = {}) =>
        mountShow({ unit: { ...PLATOON, isLeaf: false, parent: null }, troopers: null, children: [], ...overrides });

    it('locks the subject to an enforced platoon topic, saying who set it, and saves it as is', async () => {
        vi.useFakeTimers();
        const wrapper = mountShow({ topic: topic(true), report: { ...props().report, trainingSubject: 'Radio procedure' } });

        expect(wrapper.get('#training-subject').element.value).toBe('Room clearing');
        expect(wrapper.get('#training-subject').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="topic-note"]').text()).toContain('Set by 1/A/1-7 for this week');
        expect(wrapper.find('[data-testid="subject-suggestion"]').exists()).toBe(false);

        await wrapper.get('#report-notes').setValue('Good week.');
        vi.advanceTimersByTime(900);
        expect(put.mock.calls[0][1].training_subject).toBe('Room clearing');
    });

    it('prefills a platoon topic that is not enforced and lets the leader change it', async () => {
        const wrapper = mountShow({ topic: topic(false) });

        expect(wrapper.get('#training-subject').element.value).toBe('Room clearing');
        expect(wrapper.get('#training-subject').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[data-testid="topic-note"]').text()).toContain('You can change it');
    });

    it("keeps the section's own subject over a platoon topic that is not enforced", () => {
        const wrapper = mountShow({ topic: topic(false), report: { ...props().report, trainingSubject: 'Radio procedure' } });

        expect(wrapper.get('#training-subject').element.value).toBe('Radio procedure');
    });

    it("offers last week's subject on an empty field without saving it until the leader takes it", async () => {
        vi.useFakeTimers();
        const wrapper = mountShow({ suggestedSubject: 'Land navigation' });

        expect(wrapper.get('#training-subject').element.value).toBe('');
        expect(wrapper.get('[data-testid="subject-suggestion"]').text()).toContain('Last week: Land navigation.');
        vi.advanceTimersByTime(2000);
        expect(put).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="subject-suggestion"] button').trigger('click');
        expect(wrapper.get('#training-subject').element.value).toBe('Land navigation');
        expect(wrapper.find('[data-testid="subject-suggestion"]').exists()).toBe(false);
        vi.advanceTimersByTime(900);
        expect(put.mock.calls[0][1].training_subject).toBe('Land navigation');
    });

    it('does not offer a suggestion when the platoon set a topic', () => {
        const wrapper = mountShow({ topic: topic(false), suggestedSubject: 'Land navigation' });

        expect(wrapper.find('[data-testid="subject-suggestion"]').exists()).toBe(false);
    });

    it("lets the platoon leader apply an enforced topic to their sections from the platoon's report", async () => {
        const wrapper = platoonPage({ canSetTopic: true });

        await wrapper.get('#platoon-topic').setValue('  Room clearing ');
        await wrapper.get('[data-testid="topic-enforced"]').trigger('click');
        await wrapper.get('[data-testid="topic-apply"]').trigger('click');

        expect(put).toHaveBeenCalledWith(
            '/reports/2/2026-10-03/topic',
            { subject: 'Room clearing', enforced: true },
            expect.objectContaining({ only: ['topic', 'canSetTopic'], preserveState: true }),
        );
    });

    it('clears a topic', async () => {
        const wrapper = platoonPage({ canSetTopic: true, topic: topic(true) });

        expect(wrapper.get('[data-testid="topic-status"]').text()).toContain('Locked on every section report this week. Set by SFC Hale.J');
        await wrapper.get('[data-testid="topic-clear"]').trigger('click');

        expect(del).toHaveBeenCalledWith('/reports/2/2026-10-03/topic', expect.objectContaining({ only: ['topic', 'canSetTopic'] }));
    });

    it('shows the topic read-only to someone who cannot set it', () => {
        const wrapper = platoonPage({ canSetTopic: false, topic: topic(false) });

        expect(wrapper.get('#platoon-topic').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="topic-status"]').text()).toContain('Each section starts with it and can change it.');
        expect(wrapper.find('[data-testid="topic-apply"]').exists()).toBe(false);
    });

    it('hides the topic on a platoon with none set for a viewer who cannot set one', () => {
        expect(platoonPage().find('[data-testid="platoon-topic"]').exists()).toBe(false);
    });

    it('links a platoon to the training plan for the week\'s month, and a section to none (#380)', () => {
        expect(platoonPage().get('[data-testid="training-plan-link"]').attributes('href')).toBe('/reports/2/plans/2026-10');
        expect(platoonPage({ week: { ...props().week, beforeFloor: true } }).find('[data-testid="training-plan-link"]').exists()).toBe(false);
        expect(mountShow().find('[data-testid="training-plan-link"]').exists()).toBe(false);
    });
});

describe('Reports/Show deadline banner', () => {
    it('shows an unsent report due within a day, without a link to the page it is on', () => {
        vi.useFakeTimers({ now: new Date('2026-10-10T18:30:00Z') });
        const banner = mountShow().find('[data-testid="report-deadline"]');

        expect(banner.text()).toContain('Report due in 5 h');
        expect(banner.find('a').exists()).toBe(false);
    });

    it('says how long an unsent report is overdue', () => {
        vi.useFakeTimers({ now: new Date('2026-10-12T00:30:00Z') });

        expect(mountShow().find('[data-testid="report-deadline"]').text()).toContain('Report overdue by 24 h');
    });

    it('hides for a sent report and while more than a day is left', () => {
        vi.useFakeTimers({ now: new Date('2026-10-10T18:30:00Z') });
        expect(mountShow({ report: { ...props().report, status: 'sent', sentAt: '2026-10-10T12:00:00+00:00' } }).find('[data-testid="report-deadline"]').exists()).toBe(false);

        vi.setSystemTime(new Date('2026-10-08T16:20:00Z'));
        expect(mountShow().find('[data-testid="report-deadline"]').exists()).toBe(false);

        vi.setSystemTime(new Date('2026-10-31T00:00:00Z'));
        expect(mountShow().find('[data-testid="report-deadline"]').exists()).toBe(false);
    });
});
