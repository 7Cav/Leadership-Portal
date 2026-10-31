import { describe, expect, it } from 'vitest';
import { localLabel, minuteToTime, nextStart, slotLabel, startInWeek, startLabel, timeToMinute } from '../practiceSlot';

describe('practiceSlot', () => {
    it('labels a slot in Zulu, with a hair space on screen and none in plain text', () => {
        expect(slotLabel({ weekday: 4, startMinute: 120 }, { plain: true })).toBe('Thu 0200Z');
        expect(slotLabel({ weekday: 3, startMinute: 1439 })).toBe('Wed 23 59Z');
        expect(startLabel('2026-10-30T01:00:00Z', { plain: true })).toBe('Fri 0100Z');
    });

    it('finds the next start, this week or the next', () => {
        const now = new Date('2026-10-29T02:30:00Z'); // a Thursday, after 0200Z
        expect(nextStart({ weekday: 4, startMinute: 120 }, now).toISOString()).toBe('2026-11-05T02:00:00.000Z');
        expect(nextStart({ weekday: 4, startMinute: 180 }, now).toISOString()).toBe('2026-10-29T03:00:00.000Z');
    });

    it("shows the viewer's own clock as a hint", () => {
        expect(localLabel('2026-10-29T02:00:00Z', 'en-US', 'America/New_York')).toBe('Wed 22:00');
    });

    it('places a weekday in the Cav week opening on Saturday', () => {
        expect(startInWeek('2026-10-24', 6, 0).toISOString()).toBe('2026-10-24T00:00:00.000Z');
        expect(startInWeek('2026-10-24', 5, 60).toISOString()).toBe('2026-10-30T01:00:00.000Z');
    });

    it('converts time inputs both ways', () => {
        expect(minuteToTime(1439)).toBe('23:59');
        expect(timeToMinute('02:00')).toBe(120);
        expect(timeToMinute('24:00')).toBeNull();
        expect(timeToMinute('')).toBeNull();
    });
});
