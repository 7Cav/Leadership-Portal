import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import Rollup from '../Rollup.vue';

const getSpy = vi.fn();
const toastSuccess = vi.fn();
const toastError = vi.fn();
const docked = ref(true);

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: (...args) => getSpy(...args) },
}));
vi.mock('vue-sonner', () => ({ toast: { success: (...a) => toastSuccess(...a), error: (...a) => toastError(...a) } }));
vi.mock('@vueuse/core', async (importOriginal) => ({ ...(await importOriginal()), useMediaQuery: () => docked }));

const kpis = (strength, rc, sp, atRisk = 0) => ({
    strength, rc: { numerator: rc[0], denominator: rc[1] }, sp: { numerator: sp[0], denominator: sp[1] }, atRisk, onLoa: 0, makeups: 0,
});
const unit = (id, type, name, callsign, extra = {}) => ({
    id, type, name, callsign, status: 'not_started', holding: false, open: true, live: true, kpis: kpis(0, [0, 0], [0, 0]),
    delta: null, coverage: null, notes: null, savedAt: null, sentAt: null, sentBy: null, reportUrl: `/reports/${id}/2026-10-03`, children: [], ...extra,
});

// A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7 (sent)
//                  └─ 2/1/A/1-7 (draft)
function roots() {
    const sent = unit(11, 'section', '1st Section', '1/1/A/1-7', {
        status: 'sent', open: false, live: false, kpis: kpis(6, [20, 24], [10, 12], 1), sentAt: '2026-10-06T22:14:00+00:00', sentBy: 'Haddad.Y',
        notes: 'Full house.', delta: { strength: 0, rcBp: 200, spBp: -150, atRisk: 1, onLoa: 0, makeups: 0 },
    });
    const draft = unit(21, 'section', '2nd Section', '2/1/A/1-7', { status: 'draft', kpis: kpis(8, [6, 8], [3, 4]), savedAt: '2026-10-08T14:12:00+00:00', notes: 'Waiting on two.' });
    const platoon = unit(2, 'platoon', '1st Platoon', '1/A/1-7', {
        holding: true, kpis: kpis(6, [20, 24], [10, 12], 1), coverage: { sent: 1, of: 2, missing: [21] }, children: [sent, draft],
    });

    return [unit(1, 'company', 'Alpha Company', 'A/1-7', { holding: true, coverage: { sent: 0, of: 1, missing: [2] }, children: [platoon] })];
}

const WEEK = {
    start: '2026-10-03T00:00:00+00:00',
    reportDue: '2026-10-10T23:59:00+00:00',
    isCurrent: true,
    options: ['2026-10-03', '2026-09-26', '2026-09-19'],
};

let wrapper;
function mountRollup(props = {}) {
    wrapper = mount(Rollup, { props: { week: WEEK, scopeUnitId: null, roots: roots(), ...props }, attachTo: document.body });

    return wrapper;
}

const rows = () => wrapper.findAll('[role="row"]');
const rowIds = () => rows().map((row) => Number(row.attributes('data-row-id')));
const panel = () => wrapper.find('[data-testid="report-panel"]');

beforeEach(() => {
    docked.value = true;
    getSpy.mockReset();
    toastSuccess.mockReset();
    toastError.mockReset();
});
afterEach(() => {
    wrapper?.unmount();
    vi.restoreAllMocks();
});

describe('Reports/Rollup', () => {
    it('renders the chain as a treegrid, unsent branches only by default', () => {
        mountRollup();

        expect(wrapper.find('[role="treegrid"]').exists()).toBe(true);
        expect(rowIds()).toEqual([1, 2, 21]);
        expect(rows().map((row) => row.attributes('aria-level'))).toEqual(['1', '2', '3']);
        expect(rows()[0].attributes('aria-expanded')).toBe('true');
        expect(rows()[2].attributes('aria-expanded')).toBeUndefined();
        expect(wrapper.find('[data-testid="progress-label"]').text()).toBe('Sections sent 1 of 2');
        expect(rows()[1].text()).toContain('Holding');
        expect(rows()[1].text()).toContain('1 of 2 in');
    });

    it('shows every report under the All filter, with each status pill', async () => {
        mountRollup();
        await wrapper.find('[data-testid="filter-all"]').trigger('click');

        expect(rowIds()).toEqual([1, 2, 11, 21]);
        expect(rows()[2].find('[data-status]').attributes('data-status')).toBe('sent');
        expect(rows()[3].find('[data-status]').attributes('data-status')).toBe('draft');
        expect(rows()[2].text()).toContain('83%');
    });

    it('moves and expands with the arrow keys and opens a report with Enter', async () => {
        mountRollup();
        const first = rows()[0];
        first.element.focus();

        await first.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement.dataset.rowId).toBe('2');

        await rows()[1].trigger('keydown', { key: 'ArrowLeft' });
        expect(rowIds()).toEqual([1, 2]);
        expect(rows()[1].attributes('aria-expanded')).toBe('false');

        await rows()[1].trigger('keydown', { key: 'ArrowRight' });
        expect(rowIds()).toEqual([1, 2, 21]);

        await rows()[1].trigger('keydown', { key: 'Enter' });
        expect(rows()[1].attributes('aria-selected')).toBe('true');
        expect(panel().find('h2').text()).toBe('1/A/1-7');
        expect(panel().find('[data-testid="holding"]').text()).toContain('Holding up the chain');
        expect(panel().find('[data-testid="holding"]').text()).toContain('2/1/A/1-7');
    });

    it('collapses to the parent row with ArrowLeft on a leaf', async () => {
        mountRollup();
        rows()[2].element.focus();
        await rows()[2].trigger('keydown', { key: 'ArrowLeft' });

        expect(document.activeElement.dataset.rowId).toBe('2');
    });

    it('shows a sent report as its frozen snapshot, with deltas and the leader\'s notes', async () => {
        mountRollup();
        await wrapper.find('[data-testid="filter-all"]').trigger('click');
        await rows()[2].trigger('click');

        expect(panel().text()).toContain('Frozen snapshot');
        expect(panel().text()).toContain('Frozen Tue 06Oct26 22:14Z by Haddad.Y');
        expect(panel().find('[data-testid="kpis"]').text()).toContain('+2 pts');
        expect(panel().find('[data-testid="kpis"]').text()).toContain('-1.5 pts');
        expect(panel().text()).toContain('Full house.');
        expect(panel().find('[data-testid="copy-reminder"]').exists()).toBe(false);
        expect(panel().find('[data-testid="open-report"]').attributes('href')).toBe('/reports/11/2026-10-03');
    });

    it('labels an unsent report as a live draft and links to it', async () => {
        mountRollup();
        await rows()[2].trigger('click');

        expect(panel().find('[data-testid="live-label"]').text()).toContain('Live draft, not sent');
        expect(panel().text()).toContain('No sent report last week to compare');
        expect(panel().text()).toContain('Waiting on two.');
        expect(panel().find('[data-testid="open-report"]').attributes('href')).toBe('/reports/21/2026-10-03');
    });

    it('copies a forum-ready reminder and confirms it', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        mountRollup();
        await rows()[2].trigger('click');
        await panel().find('[data-testid="copy-reminder"]').trigger('click');
        await flushPromises();

        expect(writeText.mock.calls[0][0]).toMatch(/^Reminder: the 2nd Section \(2\/1\/A\/1-7\) weekly report for the week of 03Oct26 /);
        expect(writeText.mock.calls[0][0]).toContain('/reports/21/2026-10-03');
        expect(toastSuccess).toHaveBeenCalledWith('Reminder copied', expect.anything());
    });

    it('says so when the clipboard is blocked', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const denied = Object.assign(new Error('denied'), { name: 'NotAllowedError' });
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockRejectedValue(denied) }, configurable: true });
        mountRollup();
        await rows()[2].trigger('click');
        await panel().find('[data-testid="copy-reminder"]').trigger('click');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('Could not copy the reminder', { description: expect.stringContaining('blocked clipboard access') });
        expect(toastSuccess).not.toHaveBeenCalled();
        expect(console.error).toHaveBeenCalled();
    });

    it('points elsewhere when there is no clipboard at all', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
        mountRollup();
        await rows()[2].trigger('click');
        await panel().find('[data-testid="copy-reminder"]').trigger('click');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('Could not copy the reminder', { description: expect.stringContaining('Open the report') });
    });

    it('opens on every report when the whole chain is sent, and again after a visit to such a week', async () => {
        const sentEverywhere = (nodes) => nodes.map((n) => ({ ...n, status: 'sent', open: false, holding: false, children: sentEverywhere(n.children) }));
        mountRollup({ roots: sentEverywhere(roots()) });
        expect(wrapper.find('[data-testid="filter-all"]').attributes('aria-pressed')).toBe('true');
        expect(rowIds()).toEqual([1, 2, 11, 21]);
        wrapper.unmount();

        mountRollup();
        expect(rowIds()).toEqual([1, 2, 21]);
        await wrapper.setProps({ roots: sentEverywhere(roots()) });
        expect(rowIds()).toEqual([1, 2, 11, 21]);
    });

    it('drops a selection the new week would hide, so the panel never shows an off-screen row', async () => {
        mountRollup();
        await wrapper.find('[data-testid="filter-all"]').trigger('click');
        await rows()[2].trigger('click'); // 1/1/A/1-7, sent
        expect(panel().find('h2').text()).toBe('1/1/A/1-7');

        await wrapper.setProps({ roots: roots() });

        expect(wrapper.find('[data-testid="filter-open"]').attributes('aria-pressed')).toBe('true');
        expect(panel().find('h2').text()).toBe('A/1-7');
        expect(rows()[0].attributes('aria-selected')).toBe('true');
    });

    it('reveals a unit picked from the report panel in the tree', async () => {
        mountRollup();
        await rows()[1].trigger('keydown', { key: 'ArrowLeft' }); // collapse the platoon
        expect(rowIds()).toEqual([1, 2]);

        await rows()[1].trigger('click');
        const holding = panel().find('[data-testid="holding"]');
        await holding.findAll('button').find((b) => b.text() === '2/1/A/1-7').trigger('click');
        expect(rowIds()).toEqual([1, 2, 21]);
        expect(rows()[2].attributes('aria-selected')).toBe('true');

        // A sent child, hidden by the "Not sent" filter, switches the tree to All to show it.
        await rows()[1].trigger('click');
        await panel().findAll('li button').find((b) => b.text().startsWith('1/1/A/1-7')).trigger('click');
        expect(wrapper.find('[data-testid="filter-all"]').attributes('aria-pressed')).toBe('true');
        expect(rows().find((r) => r.attributes('data-row-id') === '11').attributes('aria-selected')).toBe('true');
    });

    it('changes week through the selector and the arrows, keeping the unit in the URL', async () => {
        mountRollup({ scopeUnitId: 2 });
        await wrapper.find('[data-testid="week-select"]').setValue('2026-09-19');
        expect(getSpy).toHaveBeenLastCalledWith('/reports/2', { week: '2026-09-19' }, expect.anything());

        await wrapper.find('button[aria-label="Previous week"]').trigger('click');
        expect(getSpy).toHaveBeenLastCalledWith('/reports/2', { week: '2026-09-26' }, expect.anything());
        expect(wrapper.find('button[aria-label="Next week"]').attributes('disabled')).toBeDefined();
    });

    it('shows a closed week without a countdown', () => {
        mountRollup({ week: { ...WEEK, start: '2026-09-26T00:00:00+00:00', reportDue: '2026-10-03T23:59:00+00:00', isCurrent: false } });

        expect(wrapper.find('[data-testid="countdown"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Closed week');
    });

    it('explains an empty scope instead of rendering an empty tree', () => {
        mountRollup({ roots: [] });

        expect(wrapper.find('[role="treegrid"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No units in your scope yet');
    });

    it('opens the report in a sheet below 1280px', async () => {
        docked.value = false;
        mountRollup();
        expect(panel().exists()).toBe(false);

        await rows()[2].trigger('click');
        await flushPromises();

        expect(document.body.querySelector('[data-testid="report-panel"] h2')?.textContent).toBe('2/1/A/1-7');
    });
});
