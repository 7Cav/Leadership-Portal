import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LineDiff from '../LineDiff.vue';

const lines = (count) => Array.from({ length: count }, (_, i) => `line ${i + 1}`).join('\n');

describe('LineDiff', () => {
    it('marks each changed line with a sign and a word for a screen reader, not only a colour', () => {
        const wrapper = mount(LineDiff, { props: { before: 'a\nb', after: 'a\nc' } });
        const removed = wrapper.get('[data-testid="diff-removed"]');
        const added = wrapper.get('[data-testid="diff-added"]');

        expect(removed.text()).toContain('−');
        expect(removed.get('.sr-only').text()).toBe('Removed:');
        expect(removed.classes()).toContain('bg-destructive/10');
        expect(added.text()).toContain('+');
        expect(added.get('.sr-only').text()).toBe('Added:');
        expect(added.classes()).toContain('bg-success/10');
        expect(wrapper.get('[data-testid="diff-same"]').find('.sr-only').exists()).toBe(false);
    });

    it('folds long runs of unchanged lines, keeping the context around the change', () => {
        const before = lines(20);
        const wrapper = mount(LineDiff, { props: { before, after: before.replace('line 10', 'line ten'), context: 2 } });

        expect(wrapper.findAll('[data-testid="diff-skip"]').map((row) => row.text())).toEqual(['7 unchanged lines', '8 unchanged lines']);
        expect(wrapper.findAll('[data-testid="diff-same"]')).toHaveLength(4);
    });

    it('numbers each line on the side it is on', () => {
        const wrapper = mount(LineDiff, { props: { before: 'a\nb', after: 'b\nc' } });
        const numbers = (row) => row.findAll('span[aria-hidden="true"]').slice(0, 2).map((cell) => cell.text());

        expect(numbers(wrapper.get('[data-testid="diff-removed"]'))).toEqual(['1', '']);
        expect(numbers(wrapper.get('[data-testid="diff-same"]'))).toEqual(['2', '1']);
        expect(numbers(wrapper.get('[data-testid="diff-added"]'))).toEqual(['', '2']);
    });

    it('keeps a blank line visible', () => {
        const wrapper = mount(LineDiff, { props: { before: 'a', after: 'a\n' } });

        expect(wrapper.get('[data-testid="diff-added"]').element.textContent).toContain(String.fromCharCode(160));
    });

    it('shows HTML in a template as text', () => {
        const wrapper = mount(LineDiff, { props: { before: '', after: '<img src=x onerror=alert(1)>' } });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('[data-testid="diff-added"]').text()).toContain('<img src=x onerror=alert(1)>');
    });
});
