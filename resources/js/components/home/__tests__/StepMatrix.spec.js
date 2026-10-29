import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StepMatrix from '../StepMatrix.vue';
import StepLanes from '../StepLanes.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const step = (id, designation, over = {}) => ({
    id, designation, name: designation, platoon: '1/A/1-7', strength: 4, postedAt: null, signed: 0, held: false, marked: 0, report: 'not_started', sentAt: null,
    links: {
        posted: `/rollcall/${id}?week=2026-10-03&step=3`,
        signed: `/rollcall/${id}?week=2026-10-03&step=1`,
        held: `/rollcall/${id}?week=2026-10-03&step=2`,
        marked: `/rollcall/${id}?week=2026-10-03&step=2`,
        report: `/reports/${id}/2026-10-03`,
    },
    ...over,
});

describe('StepMatrix', () => {
    it('keeps the step headers in view inside its capped box', () => {
        const box = mount(StepMatrix, { props: { steps: [step(1, '1/1/A/1-7')] } }).find('[data-slot="table-container"]');

        expect(box.attributes('data-sticky-head')).toBe('box');
        expect(box.attributes('style')).toContain('max-height: 28rem');
    });

    it('draws a row per section with the five step columns', () => {
        const wrapper = mount(StepMatrix, { props: { steps: [step(1, '1/1/A/1-7'), step(2, '2/1/A/1-7')] } });

        expect(wrapper.findAll('th').map((th) => th.text())).toEqual(['Unit', 'Roll call posted', 'Roll call signed', 'SP held', 'Attendance marked', 'Report sent']);
        expect(wrapper.findAll('tbody tr')).toHaveLength(2);
        expect(wrapper.find('tbody tr').text()).toContain('1/A/1-7');
    });

    it('links each rollcall step to its step of the rollcall and the last to the report', () => {
        const row = mount(StepMatrix, { props: { steps: [step(1, '1/1/A/1-7')] } }).find('tbody tr');

        expect(row.findAll('a').map((a) => a.attributes('href'))).toEqual([
            '/rollcall/1?week=2026-10-03&step=3',
            '/rollcall/1?week=2026-10-03&step=1',
            '/rollcall/1?week=2026-10-03&step=2',
            '/rollcall/1?week=2026-10-03&step=2',
            '/reports/1/2026-10-03',
        ]);
    });

    it('shows each step\'s state and the signed percent with a text equivalent', () => {
        const row = mount(StepMatrix, { props: { steps: [step(1, '1/1/A/1-7', { postedAt: '2026-10-05T08:00:00+00:00', signed: 3, held: true, marked: 4, report: 'draft' })] } }).find('tbody tr');

        expect(row.find('[data-step="posted"]').text()).toBe('Mon');
        expect(row.find('[data-step="signed"]').text()).toContain('75%');
        expect(row.find('[data-step="signed"]').text()).toContain('3 of 4 signed');
        expect(row.find('[data-step="held"]').attributes('data-state')).toBe('ok');
        expect(row.find('[data-step="report"]').text()).toBe('Draft');
    });

    it('keeps the empty state when no section is in scope', () => {
        const wrapper = mount(StepMatrix, { props: { steps: [] } });

        expect(wrapper.text()).toContain('No sections reporting yet');
        expect(wrapper.find('tbody').exists()).toBe(false);
    });
});

describe('StepLanes', () => {
    it('keeps the empty state when no section is in scope', () => {
        expect(mount(StepLanes, { props: { steps: [] } }).text()).toContain('Step tracking starts with roll calls');
    });

    it('draws the five lanes with their totals, for wide and phone layouts', () => {
        const wrapper = mount(StepLanes, { props: { steps: [step(1, 'A', { held: true, marked: 4, signed: 4 }), step(2, 'B')] } });

        expect(wrapper.findAll('[data-lane]').map((lane) => lane.attributes('data-lane'))).toEqual(['Roll call posted', 'Roll call signed', 'SP held', 'Attendance marked', 'Report sent']);
        expect(wrapper.find('[data-lane="Roll call signed"]').text()).toContain('4 of 8 signed');
        expect(wrapper.find('[data-testid="step-lanes-mobile"]').text()).toContain('1 of 2 held');
    });
});
