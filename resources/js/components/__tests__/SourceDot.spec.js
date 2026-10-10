import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SourceDot from '../SourceDot.vue';
import SourceLegend from '../SourceLegend.vue';

describe('SourceDot', () => {
    it('shows a manual dot for a mark made by hand', () => {
        const wrapper = mount(SourceDot, { props: { source: 'manual', status: 'present' } });

        expect(wrapper.get('[data-testid="source-dot"]').attributes('data-source-kind')).toBe('manual');
        expect(wrapper.get('span span').classes()).toContain('bg-warning');
    });

    it('shows an auto dot, distinct from manual, with no change to the component', () => {
        const wrapper = mount(SourceDot, { props: { source: 'auto', status: 'present' } });

        expect(wrapper.get('[data-testid="source-dot"]').attributes('data-source-kind')).toBe('auto');
        expect(wrapper.get('span span').classes()).toContain('bg-success');
    });

    it('shows the makeup dot for a makeup status, distinct from the present dot', () => {
        const makeup = mount(SourceDot, { props: { source: 'manual', status: 'makeup' } });
        const present = mount(SourceDot, { props: { source: 'manual', status: 'present' } });

        expect(makeup.get('[data-testid="source-dot"]').attributes('data-source-kind')).toBe('makeup');
        expect(makeup.get('span span').classes()).toContain('bg-info');
        expect(makeup.get('span span').classes()).not.toEqual(present.get('span span').classes());
    });

    it('says in words where the mark came from, on hover and for a screen reader', () => {
        const wrapper = mount(SourceDot, { props: { source: 'auto', status: 'present' } });

        expect(wrapper.get('[data-testid="source-dot"]').attributes('title')).toBe('Captured automatically from the game server');
        expect(wrapper.get('.sr-only').text()).toBe('Captured automatically from the game server');
    });

    it('draws nothing for a value that was never stored', () => {
        expect(mount(SourceDot, { props: { source: null, status: 'present' } }).find('[data-testid="source-dot"]').exists()).toBe(false);
        expect(mount(SourceDot).find('[data-testid="source-dot"]').exists()).toBe(false);
    });

    it('draws nothing for a derived leave', () => {
        expect(mount(SourceDot, { props: { source: null, status: 'loa' } }).find('[data-testid="source-dot"]').exists()).toBe(false);
    });
});

describe('SourceLegend', () => {
    it('lists auto, manual and makeup with their dots, in that order', () => {
        const wrapper = mount(SourceLegend);

        expect(wrapper.findAll('[data-legend]').map((item) => item.text())).toEqual(['auto', 'manual', 'makeup']);
        expect(wrapper.get('[data-legend="auto"] span').classes()).toContain('bg-success');
        expect(wrapper.get('[data-legend="manual"] span').classes()).toContain('bg-warning');
        expect(wrapper.get('[data-legend="makeup"] span').classes()).toContain('bg-info');
    });

    it('uses the same dot colours as the dots, so a legend never disagrees with a row', () => {
        const legend = mount(SourceLegend);

        for (const [kind, source, status] of [['auto', 'auto', 'present'], ['manual', 'manual', 'present'], ['makeup', 'manual', 'makeup']]) {
            const dot = mount(SourceDot, { props: { source, status } });

            expect(legend.get(`[data-legend="${kind}"] span`).classes()).toEqual(dot.get('span span').classes());
        }
    });

    it('is labelled for a screen reader', () => {
        expect(mount(SourceLegend).get('[data-testid="source-legend"]').attributes('aria-label')).toBe('Source legend');
    });
});
