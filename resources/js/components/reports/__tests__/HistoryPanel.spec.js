import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import HistoryPanel from '../HistoryPanel.vue';

const entry = (week, sentAt) => ({ week, sentAt, sentBy: 'SGT Vasquez.R', kpis: {}, trainingSubject: null, notes: null, highlights: [] });

describe('HistoryPanel', () => {
    it('names each sent week, and the week it is compared with, by its end date (#568)', () => {
        const wrapper = mount(HistoryPanel, {
            props: { entries: [entry('2026-10-03T00:00:00+00:00', '2026-10-08T16:24:00Z'), entry('2026-09-26T00:00:00+00:00', '2026-10-01T16:24:00Z')], reasonLabels: {} },
        });

        expect(wrapper.get('[data-testid="history-list"]').text()).toContain('03OCT26 to 09OCT26');
        expect(wrapper.get('[data-testid="history-list"]').text()).toContain('26SEP26 to 02OCT26');
        expect(wrapper.get('#history-title').text()).toBe('Week Ending 09OCT26 (03OCT26 to 09OCT26)');
        expect(wrapper.text()).toContain('Changes compared with the week 26SEP26 to 02OCT26');
        expect(wrapper.text()).not.toMatch(/week of/i);
    });

    it('lists the versions an edit replaced and opens one to what it froze, and who reopened and sent it again', async () => {
        const revision = {
            ...entry('2026-10-03T00:00:00+00:00', '2026-10-07T18:00:00Z'),
            notes: 'All good.',
            unaccounted: [],
            reopened: { at: '2026-10-08T12:00:00Z', by: 'SGT Vasquez.R', reason: 'Wrong marks' },
            replaced: { at: '2026-10-08T12:05:00Z', by: 'SGT Vasquez.R', reason: 'Fixed the marks' },
        };
        const wrapper = mount(HistoryPanel, {
            props: { entries: [{ ...entry('2026-10-03T00:00:00+00:00', '2026-10-08T12:05:00Z'), revisions: [revision] }], reasonLabels: {} },
        });

        const toggle = wrapper.get('[data-testid="history-revision"] button');
        expect(toggle.text()).toBe('Sent 07OCT26 18\u200A00Z by SGT Vasquez.R');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="history-revision-body"]').exists()).toBe(false);

        await toggle.trigger('click');

        const body = wrapper.get('[data-testid="history-revision-body"]').text();
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(body).toContain('08OCT26 12\u200A00Z by SGT Vasquez.R: Wrong marks');
        expect(body).toContain('08OCT26 12\u200A05Z by SGT Vasquez.R: Fixed the marks');
        expect(body).toContain('All good.');
    });

    it('lists no earlier versions for a report never edited', () => {
        const wrapper = mount(HistoryPanel, { props: { entries: [entry('2026-10-03T00:00:00+00:00', '2026-10-08T16:24:00Z')], reasonLabels: {} } });

        expect(wrapper.find('[data-testid="history-revisions"]').exists()).toBe(false);
    });
});
