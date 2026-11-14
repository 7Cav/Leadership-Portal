import { afterEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import RollcallSummary from '../RollcallSummary.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

enableAutoUnmount(afterEach);

const t = (id, rollcall, status) => ({ id, rollcall, status, loa: null });
const ROLLCALL = { trainingStart: '2026-11-18T23:00:00Z', threadUrl: 'https://7cav.us/threads/roll-call.1/', href: '/rollcall/7?week=2026-11-14' };
const TROOPERS = [t(1, 'will_attend', 'present'), t(2, 'will_attend', 'present'), t(3, 'unsigned', 'missed')];
const factory = (props = {}) =>
    mount(RollcallSummary, {
        props: {
            rollcall: ROLLCALL,
            subject: 'Land navigation: map and compass',
            enforced: true,
            troopers: TROOPERS,
            unaccounted: [{ trooper_id: 3, reason: 'no_notice' }],
            names: { 3: 'SPC Demo.K' },
            reasonLabels: { no_notice: 'No notice given' },
            ...props,
        },
    });

describe('RollcallSummary (#703)', () => {
    it('sums up the week\'s roll call for Platoon Staff', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="summary-training"]').text()).toMatch(/^Wed 18NOV26 23\s00Z$/);
        expect(wrapper.get('[data-testid="summary-topic"]').text()).toBe('Land navigation: map and compass (platoon topic, enforced)');
        expect(wrapper.get('[data-testid="summary-thread"] a').attributes('href')).toBe(ROLLCALL.threadUrl);
        expect(wrapper.get('[data-testid="summary-signatures"]').text()).toBe('2 will attend · 1 unsigned');
        expect(wrapper.get('[data-testid="summary-practice"]').text()).toBe('2 present · 1 missed');
        expect(wrapper.get('[data-testid="summary-unaccounted"]').text()).toBe('SPC Demo.K: No notice given');
    });

    it('names those on LOA or excused apart from the signatures, never as signed', () => {
        const troopers = [
            { id: 1, rollcall: 'will_attend', status: 'present' },
            { id: 2, rollcall: 'unsigned', status: null, onLoa: true },
            { id: 3, rollcall: 'excused', status: 'excused' },
        ];

        expect(factory({ troopers, unaccounted: [] }).get('[data-testid="summary-signatures"]').text()).toBe('1 will attend · 1 excused · 1 on LOA');
    });

    it('says what is missing instead of inventing it', () => {
        const wrapper = factory({ rollcall: { ...ROLLCALL, trainingStart: null, threadUrl: null }, subject: null, unaccounted: [] });

        expect(wrapper.get('[data-testid="summary-training"]').text()).toBe('No practice this week');
        expect(wrapper.get('[data-testid="summary-topic"]').text()).toBe('Not set');
        expect(wrapper.get('[data-testid="summary-thread"]').text()).toBe('No link given');
        expect(wrapper.find('[data-testid="summary-unaccounted"]').exists()).toBe(false);
    });
});
