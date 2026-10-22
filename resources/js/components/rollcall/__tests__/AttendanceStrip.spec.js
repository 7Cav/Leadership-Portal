import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import AttendanceStrip from '../AttendanceStrip.vue';
import TrooperCard from '../TrooperCard.vue';

enableAutoUnmount(afterEach);

const week = (date, status, { held = status !== null, loa = false } = {}) => ({ week: date, status, held, loa });
const HISTORY = [week('2026-09-05', 'present'), week('2026-09-12', 'present'), week('2026-09-19', 'missed'), week('2026-09-26', null, { loa: true })];

const strip = (props) => mount(AttendanceStrip, { props: { weeks: HISTORY, ...props } });
const squares = (wrapper) => wrapper.findAll('[data-state]');
const states = (wrapper) => squares(wrapper).map((square) => square.attributes('data-state'));

describe('AttendanceStrip', () => {
    it('draws four squares, oldest first, in the roster matrix colours, and reads them as one label', () => {
        const wrapper = strip();

        expect(squares(wrapper)).toHaveLength(4);
        expect(states(wrapper)).toEqual(['ok', 'ok', 'bad', 'loa']);
        expect(squares(wrapper)[2].find('.roster-h').classes()).toContain('roster-h--bad');
        const label = wrapper.get('[data-testid="attendance-strip"]');
        expect(label.attributes('role')).toBe('img');
        expect(label.attributes('aria-label')).toBe('Last 4 weeks: present, present, missed, LOA');
    });

    it('titles each square with its week and state', () => {
        const titles = squares(strip()).map((square) => square.attributes('title'));

        expect(titles).toEqual(['Week of 05Sep26: Present', 'Week of 12Sep26: Present', 'Week of 19Sep26: Missed', 'Week of 26Sep26: On LOA']);
    });

    it('tells makeup, attached, not marked yet and no practice held apart', () => {
        const wrapper = strip({
            weeks: [week('2026-09-05', 'makeup'), week('2026-09-12', 'attached'), week('2026-09-19', null, { held: true }), week('2026-09-26', null)],
        });

        expect(states(wrapper)).toEqual(['mk', 'at', 'open', 'nil']);
        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe('Last 4 weeks: makeup, attached, not marked yet, no SP held');
    });

    it('shows LOA over whatever mark the week carries, and an old loa mark as LOA', () => {
        const wrapper = strip({ weeks: [week('2026-09-05', 'present', { loa: true }), week('2026-09-12', 'loa', { held: false, loa: true })] });

        expect(states(wrapper)).toEqual(['loa', 'loa']);
    });

    it('shows the weeks before a recruit joined as not in the unit yet', () => {
        const wrapper = strip({
            joined: '2026-09-21',
            weeks: [week('2026-09-05', null, { held: true }), week('2026-09-12', null, { held: true }), week('2026-09-19', null), week('2026-09-26', 'present')],
        });

        expect(states(wrapper)).toEqual(['out', 'out', 'nil', 'ok']);
        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe(
            'Last 4 weeks: not in the unit yet, not in the unit yet, no SP held, present',
        );
    });
});

describe('TrooperCard attendance strip', () => {
    const trooper = (extra = {}) => ({
        id: 7,
        cavId: 1234,
        name: 'Adams.P',
        displayName: 'PVT Adams.P',
        rollcall: 'unsigned',
        status: null,
        source: null,
        priorStatus: null,
        joined: '2024-01-01',
        history: HISTORY,
        ...extra,
    });

    it.each(['signature', 'practice'])('shows the strip on the %s step', (kind) => {
        const wrapper = mount(TrooperCard, { props: { trooper: trooper(), kind, editable: true } });

        expect(wrapper.get('[data-testid="attendance-strip"]').attributes('aria-label')).toBe('Last 4 weeks: present, present, missed, LOA');
    });

    it('shows it on a locked LOA card too, and leaves it out when there is no history', () => {
        expect(mount(TrooperCard, { props: { trooper: trooper({ status: 'loa' }), kind: 'practice' } }).find('[data-testid="attendance-strip"]').exists()).toBe(true);
        expect(mount(TrooperCard, { props: { trooper: trooper({ history: [] }), kind: 'practice' } }).find('[data-testid="attendance-strip"]').exists()).toBe(false);
    });
});
