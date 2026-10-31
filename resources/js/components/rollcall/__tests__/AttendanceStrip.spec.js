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
        expect(label.attributes('aria-label')).toBe('Last 4 weeks: attended, attended, not attended, LOA');
    });

    it('titles each square with its week and state', () => {
        const titles = squares(strip()).map((square) => square.attributes('title'));

        expect(titles).toEqual(['05SEP26 to 11SEP26: Attended', '12SEP26 to 18SEP26: Attended', '19SEP26 to 25SEP26: Not attended', '26SEP26 to 02OCT26: On LOA']);
    });

    it('tells make-up complete, excused, not marked yet and no practice held apart', () => {
        const wrapper = strip({
            weeks: [week('2026-09-05', 'makeup'), week('2026-09-12', 'excused'), week('2026-09-19', null, { held: true }), week('2026-09-26', null)],
        });

        expect(states(wrapper)).toEqual(['mk', 'ex', 'open', 'nil']);
        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe('Last 4 weeks: make-up complete, excused, not marked yet, no SP held');
    });

    it('shows an automatically excused first week as excused until a leader marks it', () => {
        const wrapper = strip({
            weeks: [
                { ...week('2026-09-05', null, { held: true }), excused: 'transfer' },
                { ...week('2026-09-12', null), excused: 'recruit' },
                { ...week('2026-09-19', 'present'), source: 'manual', excused: 'transfer' },
                { ...week('2026-09-26', 'missed'), source: 'auto', excused: 'transfer' },
            ],
        });

        expect(states(wrapper)).toEqual(['ex', 'ex', 'ok', 'ex']);
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
            'Last 4 weeks: not in the unit yet, not in the unit yet, no SP held, attended',
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

        expect(wrapper.get('[data-testid="attendance-strip"]').attributes('aria-label')).toBe('Last 4 weeks: attended, attended, not attended, LOA');
    });

    it('shows it on a locked LOA card too, and leaves it out when there is no history', () => {
        expect(mount(TrooperCard, { props: { trooper: trooper({ status: 'loa' }), kind: 'practice' } }).find('[data-testid="attendance-strip"]').exists()).toBe(true);
        expect(mount(TrooperCard, { props: { trooper: trooper({ history: [] }), kind: 'practice' } }).find('[data-testid="attendance-strip"]').exists()).toBe(false);
    });
});
