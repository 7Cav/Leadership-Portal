import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Show from '../Show.vue';

const put = vi.fn();
const post = vi.fn();
let pageProps = {};

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: pageProps }),
    router: { put: (...args) => put(...args), post: (...args) => post(...args) },
}));

vi.mock('vue-sonner', async (importOriginal) => ({
    ...(await importOriginal()),
    toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}));

const KPIS = { strength: 2, rc: { numerator: 7, denominator: 8 }, sp: { numerator: 3, denominator: 4 }, atRisk: 0, onLoa: 0, makeups: 0 };

function props(overrides = {}) {
    return {
        unit: { id: 5, name: '2nd Section', callsign: '2/1/A/1-7', type: 'section', isLeaf: true, parent: { id: 2, name: '1st Platoon', callsign: '1/A/1-7', type: 'platoon' } },
        week: { start: '2026-10-03T00:00:00+00:00', due: '2026-10-10T23:59:00+00:00', previous: '2026-09-26', next: null },
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
        rosterSync: { source: 'Cav roster (api.7cav.us)', at: null },
        computedAt: '2026-10-08T16:20:00+00:00',
        ...overrides,
    };
}

const mountShow = (overrides) => mount(Show, { props: props(overrides), attachTo: document.body });

beforeEach(() => {
    pageProps = {};
    put.mockReset();
    post.mockReset();
});

afterEach(() => {
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

    it('renders a sent report read-only from its snapshot, with who sent it', () => {
        const wrapper = mountShow({
            report: { status: 'sent', notes: 'Good.', trainingSubject: 'Land nav', unaccounted: [], savedAt: null, sentAt: '2026-10-08T16:24:00+00:00', sentBy: 'SGT Vasquez.R' },
            troopers: null,
        });

        expect(wrapper.get('[data-testid="sent-line"]').text()).toBe('Sent Thu 08Oct26 16:24Z by SGT Vasquez.R');
        expect(wrapper.find('[data-testid="sent-panel"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="send-bar"]').exists()).toBe(false);
        expect(wrapper.get('#training-subject').attributes('disabled')).toBeDefined();
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
        expect(document.querySelector('[data-testid="send-warning"]').textContent).toContain('Holding up the chain: 2/1/A/1-7');
    });

    it('says when there is no attendance yet rather than showing a misleading zero', () => {
        const wrapper = mountShow({
            troopers: [{ id: 1, name: 'SGT Vasquez.R', rollcall: 'unsigned', status: null, source: null, atRisk: false, missed: 0, onLoa: false }],
        });

        expect(wrapper.find('[data-testid="no-attendance"]').exists()).toBe(true);
    });
});
