import { describe, it, expect } from 'vitest';
import { forumDate, forumDateTime, HAIR_SPACE, weekEnding, weekLabel, weekStartTick, weekTick, weekTitle, zuluTime } from '../forumDate';

describe('forumDate', () => {
    it('formats an ISO timestamp as day + 3-letter month + 2-digit year', () => {
        expect(forumDate('2026-05-30T14:22:00Z')).toBe('30MAY26');
    });

    it('pads a single-digit day', () => {
        expect(forumDate('2026-05-03T00:00:00Z')).toBe('03MAY26');
    });

    it('reads the date in UTC, matching how the server stores timestamps (AD-11)', () => {
        // 23:30 UTC on the 30th is still the 31st in UTC+1 — a local-time read would drift the day.
        expect(forumDate('2026-05-30T23:30:00Z')).toBe('30MAY26');
    });
});

describe('forumDateTime', () => {
    it('appends a zero-padded UTC clock to the forum date, a hair space between hours and minutes', () => {
        expect(forumDateTime('2026-05-30T04:05:00Z')).toBe('30MAY26 04\u200A05');
    });

    it('drops the hair space for plain text', () => {
        expect(forumDateTime('2026-05-30T04:05:00Z', { plain: true })).toBe('30MAY26 0405');
    });
});

describe('zuluTime', () => {
    it('reads 23 59Z on screen, with a hair space (U+200A) where the colon was', () => {
        expect(zuluTime('2026-10-10T23:59:00Z')).toBe(`23${HAIR_SPACE}59Z`);
        expect(HAIR_SPACE).toBe('\u200A');
    });

    it('reads 2359Z as plain text, for Discord, the forum and the clipboard', () => {
        expect(zuluTime('2026-10-10T23:59:00Z', { plain: true })).toBe('2359Z');
    });

    it('reads the clock in UTC', () => {
        expect(zuluTime('2026-10-11T01:05:00+02:00')).toBe('23\u200A05Z');
    });
});

describe('weekLabel', () => {
    it('names the week opening on a week_start by its Saturday and Friday, month in capitals', () => {
        expect(weekLabel('2026-10-24')).toBe('24OCT26 to 30OCT26');
        expect(weekLabel('2026-10-24T00:00:00Z')).toBe('24OCT26 to 30OCT26');
        expect(weekLabel('2026-10-24T00:00:00.000000Z')).toBe('24OCT26 to 30OCT26');
    });

    it('names the Cav week a timestamp falls in, not the timestamp plus six days', () => {
        expect(weekLabel('2026-10-27T15:00:00Z')).toBe('24OCT26 to 30OCT26');
        expect(weekLabel('2026-10-30T23:59:59Z')).toBe('24OCT26 to 30OCT26');
        expect(weekLabel('2026-10-31T00:00:00Z')).toBe('31OCT26 to 06NOV26');
    });

    it('buckets by the UTC instant, so an offset time lands in the week of its UTC moment (AD-11)', () => {
        // Friday 23:30 in UTC-2 is already Saturday 01:30 UTC: the next Cav week.
        expect(weekLabel('2026-10-30T23:30:00-02:00')).toBe('31OCT26 to 06NOV26');
    });

    it('crosses a month and a year end', () => {
        expect(weekLabel('2026-12-26')).toBe('26DEC26 to 01JAN27');
    });
});

describe('weekTitle, weekEnding and weekTick', () => {
    it('gives the long form for titles and headers', () => {
        expect(weekTitle('2026-10-24')).toBe('Week Ending 30OCT26 (24OCT26 to 30OCT26)');
    });

    it('gives the end date alone, as the Google Form names a week', () => {
        expect(weekEnding('2026-10-24')).toBe('30OCT26');
    });

    it('gives a chart tick with the month once, or twice when the week spans two months', () => {
        expect(weekTick('2026-10-17')).toBe('17 to 23OCT');
        expect(weekTick('2026-10-31')).toBe('31OCT to 06NOV');
    });

    it('gives the start date alone for a narrow spot', () => {
        expect(weekStartTick('2026-10-31')).toBe('31OCT');
        expect(weekStartTick('2026-09-12')).toBe('12SEP');
    });
});
