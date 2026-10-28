import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ReadinessMark from '../ReadinessMark.vue';

const mark = (props) => mount(ReadinessMark, { props });

describe('ReadinessMark', () => {
    it.each([
        ['qualified', 'Qualified'],
        ['in_progress', 'In progress'],
        ['required', 'Required, not held'],
        ['none', 'Not held'],
    ])('draws %s with the words for a screen reader', (status, words) => {
        const wrapper = mark({ status });

        expect(wrapper.attributes('data-status')).toBe(status);
        expect(wrapper.get('.sr-only').text()).toBe(words);
    });

    it('reads a status it does not know as not held, not a broken cell', () => {
        const wrapper = mark({ status: 'banana' });

        expect(wrapper.attributes('data-status')).toBe('none');
        expect(wrapper.get('.sr-only').text()).toBe('Not held');
    });

    it('hides the words in the legend, where the visible text already says them', () => {
        for (const status of ['qualified', 'in_progress', 'required', 'none']) {
            expect(mark({ status, decorative: true }).find('.sr-only').exists()).toBe(false);
        }
    });

    it('hides every glyph from assistive tech', () => {
        expect(mark({ status: 'none' }).get('[aria-hidden="true"]').text()).toBe('—');
        expect(mark({ status: 'qualified' }).get('svg').attributes('aria-hidden')).toBe('true');
    });

    it('is smaller in the legend', () => {
        expect(mark({ status: 'qualified', size: 'sm' }).classes()).toContain('size-5');
        expect(mark({ status: 'qualified' }).classes()).toContain('size-6');
    });
});
