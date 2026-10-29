import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import PracticeGuestsPanel from '../PracticeGuestsPanel.vue';

enableAutoUnmount(afterEach);

const CRITERIA = { minVisits: 3, minOwnSpPercent: 75, windowWeeks: 12 };
const guest = (trooperId, over = {}) => ({
    trooperId,
    name: `Guest${trooperId}.G`,
    displayName: `PFC Guest${trooperId}.G`,
    rank: 'PFC',
    rankFull: 'Private First Class',
    section: { id: 40, designation: '3/2/B/1-7' },
    visits: 1,
    lastSeen: '2026-09-26',
    ownSp: [3, 4],
    candidate: false,
    ...over,
});

const factory = (rows, criteria = CRITERIA) => mount(PracticeGuestsPanel, { props: { guests: { criteria, rows }, unitLabel: '1/1/A/1-7' } });
const order = (wrapper) => wrapper.findAll('tbody tr').map((row) => row.attributes('data-testid'));

describe('PracticeGuestsPanel', () => {
    it('lists each guest with their own section, visits, last week seen and own SP', () => {
        const wrapper = factory([guest(1, { visits: 2, ownSp: [3, 4], lastSeen: '2026-10-03' })]);
        const row = wrapper.find('[data-testid="practice-guest-1"]');

        expect(row.text()).toContain('Guest1.G');
        expect(row.text()).toContain('3/2/B/1-7');
        expect(row.find('[data-testid="practice-guest-visits"]').text()).toBe('2');
        expect(row.find('[data-testid="practice-guest-sp"]').text()).toBe('75%');
        expect(row.text()).toContain('Week of');
        expect(wrapper.find('[data-testid="practice-guests-count"]').text()).toBe('1 guest');
    });

    it('badges a JSAM candidate, and only a candidate', () => {
        const wrapper = factory([guest(1, { candidate: true, visits: 3 }), guest(2, { visits: 4, ownSp: [1, 4] })]);

        expect(wrapper.find('[data-testid="practice-guest-1"] [data-testid="jsam-candidate"]').text()).toContain('JSAM candidate');
        expect(wrapper.find('[data-testid="practice-guest-2"] [data-testid="jsam-candidate"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="practice-guests-count"]').text()).toBe('2 guests, 1 JSAM candidate');
    });

    it('sorts candidates first, then by visits, then the most recently seen', () => {
        const wrapper = factory([
            guest(1, { visits: 1 }),
            guest(2, { visits: 5 }),
            guest(3, { visits: 3, candidate: true }),
            guest(4, { visits: 5, lastSeen: '2026-10-03' }),
        ]);

        expect(order(wrapper)).toEqual(['practice-guest-3', 'practice-guest-4', 'practice-guest-2', 'practice-guest-1']);
    });

    it('says what a candidate is, from the configured thresholds', () => {
        const text = factory([], { minVisits: 4, minOwnSpPercent: 80, windowWeeks: 8 }).find('[data-testid="practice-guests-criteria"]').text();

        expect(text).toContain('last 8 Cav weeks');
        expect(text).toContain('at least 4');
        expect(text).toContain('80% or more of their own section');
        expect(text).toContain('makeups do not count');
        // Guests come from the host leaders for now; nothing promises the game server adds them.
        expect(text).toContain('section leaders add them on the roll call');
        expect(text).not.toMatch(/seen|server/i);
    });

    it('labels the own SP as their own section only, makeups left out', () => {
        const wrapper = factory([guest(1, { ownSp: [3, 4] })]);
        const header = wrapper.findAll('th').find((th) => th.text() === 'Own section SP');

        expect(header.attributes('title')).toContain('Makeups are not counted');
        expect(wrapper.find('[data-testid="practice-guest-sp"]').attributes('title')).toBe('3 of 4 practices of their own section. Makeups are not counted.');
    });

    it('shows an empty state when nobody visited', () => {
        const wrapper = factory([]);

        expect(wrapper.find('[data-testid="practice-guests-empty"]').text()).toContain('No guests yet');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('is read-only: nothing on it can be changed', () => {
        const wrapper = factory([guest(1, { candidate: true, visits: 3 })]);

        expect(wrapper.findAll('button')).toHaveLength(0);
        expect(wrapper.findAll('input, select, a')).toHaveLength(0);
    });

    it('shows n/a for an own SP with nothing to count', () => {
        expect(factory([guest(1, { ownSp: [0, 0] })]).find('[data-testid="practice-guest-sp"]').text()).toBe('n/a');
    });
});
