import { describe, expect, it, vi, beforeEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ClockChangeCard from '../ClockChangeCard.vue';

const post = vi.fn();
vi.mock('@/lib/postJson', () => ({ postJson: (...args) => post(...args) }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

// US clocks went back an hour on 01NOV26: Thu 0200Z was 22:00 EDT the Wednesday before, and is 21:00 EST after.
const CHANGE = { at: '2026-11-01T06:00:00Z', phase: 'before', timezone: 'America/New_York', slot: [4, 120], keepLocal: [4, 180], confirmed: null, canConfirm: true };
const factory = (change = {}) =>
    mount(ClockChangeCard, { props: { sectionId: 98, week: '2026-10-24', change: { ...CHANGE, ...change }, practiceStart: '2026-10-29T02:00:00Z', timeZone: 'America/New_York' } });

describe('ClockChangeCard', () => {
    beforeEach(() => post.mockReset());

    it('shows the practice in Zulu and in the platoon leader\'s time, before and after the change', () => {
        const wrapper = factory();
        const text = wrapper.get('[data-testid="clock-change-times"]').text();

        expect(text).toContain('In New York, where your platoon leader is, practice at Thu 02 00Z was Wed 22:00 and is Wed 21:00 from next week');
        expect(text).toContain('Move it to Thu 03 00Z to keep it at Wed 22:00 there');
        // The viewer is in the same zone: no second line.
        expect(wrapper.find('[data-testid="clock-change-own"]').exists()).toBe(false);
    });

    it('adds the viewer\'s own time where it reads differently', () => {
        const wrapper = mount(ClockChangeCard, {
            props: { sectionId: 98, week: '2026-10-31', change: { ...CHANGE, phase: 'of' }, practiceStart: '2026-11-05T02:00:00Z', timeZone: 'Europe/Amsterdam' },
        });

        expect(wrapper.get('[data-testid="clock-change-own"]').text()).toBe('Your own time: Thu 03:00, which stays the same if the slot is kept.');
    });

    it('lets the platoon leader move or keep the slot, and passes on the answer', async () => {
        post.mockResolvedValue({ ok: true, status: 200, data: { practiceSlot: { weekday: 4 }, clockChange: { ...CHANGE, confirmed: { moved: true, by: 'Pike.S', slot: [4, 180] } } } });
        const wrapper = factory();

        expect(wrapper.get('[data-testid="clock-change-move"]').text()).toContain('(Wed 22:00 in New York)');
        await wrapper.get('[data-testid="clock-change-move"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/rollcall/98/slot/clock-change', { week: '2026-10-24', move: true });
        expect(wrapper.emitted('confirmed')[0][0].clockChange.confirmed.moved).toBe(true);
    });

    it('tells a section leader the report waits for the platoon leader', () => {
        const wrapper = factory({ canConfirm: false });

        expect(wrapper.find('[data-testid="clock-change-move"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="clock-change-waiting"]').text()).toContain('The weekly report waits for it.');
    });

    it('says what was answered', () => {
        expect(factory({ confirmed: { moved: false, by: 'Pike.S', slot: [4, 120] } }).get('[data-testid="clock-change-answer"]').text()).toBe('Pike.S kept it at Thu 02 00Z.');
    });
});
