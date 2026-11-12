import { afterEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import RollcallClose from '../RollcallClose.vue';
import { Checkbox } from '@/components/ui/checkbox';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

enableAutoUnmount(afterEach);

const COUNTS = { total: 7, present: 6, makeup: 0, missed: 1, excused: 0, unsigned: 1, unmarked: 0, loa: 0 };
const REPORT = { sent: false, href: '/reports/7/2026-11-14' };
const POSTED = { at: '2026-11-14T18:00:00+00:00', by: 'Practice.L' };
const factory = (props = {}) =>
    mount(RollcallClose, {
        props: {
            posted: POSTED,
            editedSincePost: true,
            copied: false,
            postReady: true,
            counts: COUNTS,
            confirmed: true,
            trainingStart: '2026-11-18T23:00:00Z',
            topic: 'Land navigation: map and compass',
            platoon: '1st Platoon',
            report: REPORT,
            ...props,
        },
    });
const checks = (wrapper) => Object.fromEntries(wrapper.findAll('[data-check]').map((li) => [li.attributes('data-check'), li.attributes('data-done') === 'true']));

describe('RollcallClose (#703)', () => {
    it('sums up the report: the training and who attended', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="close-training"]').text()).toMatch(/^Wed 18NOV26 23\s00Z · Land navigation: map and compass$/);
        expect(wrapper.get('[data-testid="close-attendance"]').text()).toBe('6 attended · 1 not attended · 1 unsigned');
        expect(wrapper.get('[data-testid="close-post"]').text()).toContain('edit the post you made on 14NOV26');
    });

    it('lists what is done before closing, the forum post only once it was edited after the last change', () => {
        expect(checks(factory())).toEqual({ confirmed: true, marked: true, edited: false });
        expect(checks(factory({ editedSincePost: false }))).toEqual({ confirmed: true, marked: true, edited: true });
        expect(checks(factory({ confirmed: false, counts: { ...COUNTS, unmarked: 2 } }))).toMatchObject({ confirmed: false, marked: false });
    });

    it('copies the updated post, then stamps it posted when the leader ticks that they edited the forum post', async () => {
        const wrapper = factory();

        expect(wrapper.findComponent(Checkbox).props('disabled')).toBe(true);
        await wrapper.get('[data-testid="close-copy"]').trigger('click');
        expect(wrapper.emitted('copy')).toHaveLength(1);

        await wrapper.setProps({ copied: true });
        expect(wrapper.findComponent(Checkbox).props('disabled')).toBe(false);
        wrapper.findComponent(Checkbox).vm.$emit('update:modelValue', true);
        expect(wrapper.emitted('mark-posted')).toHaveLength(1);
    });

    it('closes and sends for review, unless the screen is read-only', async () => {
        const wrapper = factory();

        await wrapper.get('[data-testid="close-send"]').trigger('click');
        expect(wrapper.emitted('send')).toHaveLength(1);
        expect(factory({ disabledReason: 'Read-only' }).get('[data-testid="close-send"]').attributes('disabled')).toBeDefined();
        // The report's send needs the practice confirmed (#634), so the close waits for it and says so.
        const unconfirmed = factory({ confirmed: false }).get('[data-testid="close-send"]');
        expect(unconfirmed.attributes('disabled')).toBeDefined();
        expect(unconfirmed.attributes('title')).toBe('Confirm the practice took place first');
    });

    it('says a sent week is closed and locked, and points to the report', () => {
        const wrapper = factory({ report: { ...REPORT, sent: true } });

        expect(wrapper.find('[data-testid="close-send"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="close-sent"]').text()).toContain('Closed and sent to 1st Platoon staff');
        expect(wrapper.get('[data-testid="close-sent"] a').attributes('href')).toBe('/reports/7/2026-11-14');
    });
});
