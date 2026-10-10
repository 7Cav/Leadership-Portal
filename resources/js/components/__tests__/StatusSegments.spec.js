import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import StatusSegments from '../StatusSegments.vue';

const OPTIONS = [
    { value: 'present', label: 'Yes', title: 'Attended' },
    { value: 'missed', label: 'No', title: 'Missed' },
];

const mountIt = (props = {}) => mount(StatusSegments, { props: { options: OPTIONS, label: 'Attended SP, Hollis.D', modelValue: 'present', ...props } });

describe('StatusSegments', () => {
    it('draws every option inside a labelled group', () => {
        const wrapper = mountIt();

        expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Attended SP, Hollis.D');
        expect(wrapper.findAll('[data-value]').map((o) => o.text().replace('(selected)', '').trim())).toEqual(['Yes', 'No']);
    });

    it('marks only the chosen option as selected, and says so to a screen reader', () => {
        const wrapper = mountIt();

        expect(wrapper.get('[data-value="present"]').attributes('data-selected')).toBe('true');
        expect(wrapper.get('[data-value="present"]').text()).toContain('(selected)');
        expect(wrapper.get('[data-value="missed"]').attributes('data-selected')).toBe('false');
        expect(wrapper.get('[data-value="missed"]').text()).not.toContain('(selected)');
    });

    it('selects nothing when the value is null', () => {
        const wrapper = mountIt({ modelValue: null });

        expect(wrapper.findAll('[data-selected="true"]')).toHaveLength(0);
    });

    it('is not a control while read-only: no buttons, and a click changes nothing', async () => {
        const wrapper = mountIt();

        expect(wrapper.findAll('button')).toHaveLength(0);
        await wrapper.get('[data-value="missed"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('marks the selected segment as current while read-only, since it is not pressable', () => {
        const wrapper = mountIt();

        expect(wrapper.get('[data-value="present"]').attributes('aria-current')).toBe('true');
        expect(wrapper.get('[data-value="present"]').attributes('aria-pressed')).toBeUndefined();
    });

    it('becomes a row of toggle buttons when it may be changed, and emits the choice', async () => {
        const wrapper = mountIt({ readonly: false });

        expect(wrapper.findAll('button')).toHaveLength(2);
        expect(wrapper.get('[data-value="present"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('[data-value="missed"]').attributes('aria-pressed')).toBe('false');

        await wrapper.get('[data-value="missed"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['missed']]);
    });

    it('gives the selected segment its own tone', () => {
        const wrapper = mountIt({ tones: { present: 'bg-success/25' } });

        expect(wrapper.get('[data-value="present"]').classes()).toContain('bg-success/25');
        expect(wrapper.get('[data-value="missed"]').classes()).not.toContain('bg-success/25');
    });

    it('outlines a suggestion with a dashed edge so it reads as unsaved', () => {
        const dashed = mountIt({ dashed: true });
        const solid = mountIt();

        expect(dashed.get('[data-value="present"]').classes().join(' ')).toContain('outline-dashed');
        expect(solid.get('[data-value="present"]').classes().join(' ')).not.toContain('outline-dashed');
    });

    it('keeps each option\'s title for hover', () => {
        expect(mountIt().get('[data-value="missed"]').attributes('title')).toBe('Missed');
    });
});
