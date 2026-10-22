import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import MatrixView from '../MatrixView.vue';
import { CURRENT, roster, WEEKS } from './fixture';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

function factory(props = {}) {
    const { units, troopers } = roster();

    return mount(MatrixView, {
        props: { units, troopers, weeks: WEEKS, currentWeek: CURRENT, query: '', filter: 'all', ...props },
        attachTo: document.body,
    });
}

describe('Attendance matrix (roster-s3)', () => {
    it('scrolls in its own box with the week headers in view, at every width', () => {
        expect(factory().find('thead').element.parentElement.parentElement.dataset.stickyHead).toBe('box');
    });

    it('shows 20 week columns with the current week outlined, then RC, SP and at-risk', () => {
        const head = factory().findAll('thead th');

        expect(head).toHaveLength(1 + 20 + 3);
        expect(head[20].classes()).toContain('is-now');
        expect(head[20].text()).toBe('03Oct');
        expect(head.slice(-3).map((th) => th.text())).toEqual(['RC 30d', 'SP 30d', 'At-risk']);
    });

    it('rolls sections into aggregate rows at platoon scope, expandable', async () => {
        const wrapper = factory();

        expect(wrapper.find('[data-testid="agg-2"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="matrix-trooper-10"]').exists()).toBe(true); // ≤ 4 sections open
        await wrapper.find('[data-testid="matrix-toggle-2"]').trigger('click');
        expect(wrapper.find('[data-testid="matrix-trooper-10"]').exists()).toBe(false);
    });

    it('slashes missed and unsigned cells so colour is not the only cue', () => {
        const cells = factory().find('[data-testid="matrix-trooper-12"]').findAll('.roster-cc');

        expect(cells[17].find('.roster-h--bad').exists()).toBe(true);
        expect(cells[17].attributes('class')).toBeDefined();
    });

    it('narrows to 8 weeks', async () => {
        const wrapper = factory();
        await wrapper.find('[data-testid="window-8"]').trigger('click');

        expect(wrapper.findAll('thead th.wk')).toHaveLength(8);
    });

    it('keeps the week window when the active button is pressed again', async () => {
        const wrapper = factory();
        await wrapper.find('[data-testid="window-20"]').trigger('click');

        expect(wrapper.findAll('thead th.wk')).toHaveLength(20);
        expect(wrapper.find('[data-testid="window-20"]').attributes('data-state')).toBe('on');
    });

    it('sorts at-risk first from the column header', async () => {
        const wrapper = factory();
        await wrapper.find('[data-testid="sort-risk-header"]').trigger('click');

        const order = wrapper.findAll('tbody tr').map((tr) => tr.attributes('data-testid')).filter(Boolean);
        expect(order[0]).toBe('agg-3');
        expect(order[1]).toBe('matrix-trooper-12');
    });

    it('moves focus between cells with the arrow keys and opens on Enter', async () => {
        const wrapper = factory();
        const first = wrapper.find('[data-nav][data-r="0"][data-c="0"]');
        first.element.focus();

        await first.trigger('keydown', { key: 'ArrowRight' });
        expect(document.activeElement.dataset.c).toBe('1');
        await wrapper.find('[data-nav][data-r="0"][data-c="1"]').trigger('keydown', { key: 'End' });
        expect(document.activeElement.dataset.c).toBe('20');
        await wrapper.find('[data-nav][data-r="0"][data-c="20"]').trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement.dataset.r).toBe('1');

        await wrapper.find('[data-nav][data-r="1"][data-c="20"]').trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('open')).toEqual([[10]]);
        wrapper.unmount();
    });

    it('shows a tooltip with status and source for a cell', async () => {
        const wrapper = factory();
        await nextTick();
        wrapper.find('[data-tip="m:12:19"]').element.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
        await nextTick();

        const tip = document.body.querySelector('[data-testid="roster-tip"]');
        expect(tip.textContent).toContain('Reyes.M, week of 03Oct26 (this week)');
        expect(tip.textContent).toContain('Missed');
        expect(tip.textContent).toContain('Manual, section leader');
        wrapper.unmount();
    });

    it('shows flat rows plus a section average at section scope', () => {
        const { units, troopers } = roster();
        const wrapper = mount(MatrixView, {
            props: { units: [units[1]], troopers: troopers.filter((t) => t.sectionId === 2), weeks: WEEKS, currentWeek: CURRENT, query: '', filter: 'all' },
        });

        expect(wrapper.text()).toContain('Section average');
        expect(wrapper.find('[data-testid="agg-2"]').classes()).toContain('tr-avg');
    });
});
