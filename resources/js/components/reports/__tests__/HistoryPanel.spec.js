import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import HistoryPanel from '../HistoryPanel.vue';

const entry = (week, sentAt) => ({ week, sentAt, sentBy: 'SGT Vasquez.R', kpis: {}, trainingSubject: null, notes: null, highlights: [] });

describe('HistoryPanel', () => {
    it('names each sent week, and the week it is compared with, by its end date (#568)', () => {
        const wrapper = mount(HistoryPanel, {
            props: { entries: [entry('2026-10-03T00:00:00+00:00', '2026-10-08T16:24:00Z'), entry('2026-09-26T00:00:00+00:00', '2026-10-01T16:24:00Z')], reasonLabels: {} },
        });

        expect(wrapper.get('[data-testid="history-list"]').text()).toContain('Week Ending 09Oct26');
        expect(wrapper.get('[data-testid="history-list"]').text()).toContain('Week Ending 02Oct26');
        expect(wrapper.get('#history-title').text()).toBe('Week Ending 09Oct26');
        expect(wrapper.text()).toContain('Changes compared with the week ending 02Oct26');
        expect(wrapper.text()).not.toMatch(/week of/i);
    });
});
