import { describe, it, expect } from 'vitest';
import { endsOnIn, grantStatus, sortGrants, todayZulu } from '../grantExpiry';

const NOW = Date.parse('2026-11-01T12:00:00Z');
const grant = (over) => ({ id: 1, mode: 'none', expires_at: null, past_end: false, ...over });

describe('grantExpiry', () => {
    it('picks Zulu days from today', () => {
        expect(todayZulu(NOW)).toBe('2026-11-01');
        expect(endsOnIn(7, NOW)).toBe('2026-11-08');
        expect(endsOnIn(30, NOW)).toBe('2026-12-01');
    });

    it('labels each kind of end date', () => {
        expect(grantStatus(grant(), NOW).label).toBeNull();
        expect(grantStatus(grant({ mode: 'ends', expires_at: '2026-11-30T23:59:59Z' }), NOW)).toEqual({ label: 'Ends 30NOV26', tone: 'outline', soon: false });
        expect(grantStatus(grant({ mode: 'renew', expires_at: '2026-11-30T23:59:59Z' }), NOW).label).toBe('Renew by 30NOV26');
        expect(grantStatus(grant({ mode: 'renew', expires_at: '2026-10-31T23:59:59Z', past_end: true }), NOW).label).toBe('Lapsed since 31OCT26, renew to restore');
        expect(grantStatus(grant({ mode: 'ends', expires_at: '2026-10-31T23:59:59Z', past_end: true }), NOW).label).toBe('Ended');
    });

    it('warns about a grant ending within 7 days', () => {
        expect(grantStatus(grant({ mode: 'ends', expires_at: '2026-11-05T23:59:59Z' }), NOW)).toMatchObject({ tone: 'warning', soon: true });
    });

    it('trusts the server on whether the date has passed', () => {
        expect(grantStatus(grant({ mode: 'ends', expires_at: '2026-10-31T23:59:59Z', past_end: false }), NOW).label).toBe('Ends 31OCT26');
    });

    it('lists grants lapsing soon first, soonest first, keeping the rest in order', () => {
        const grants = [
            grant({ id: 1 }),
            grant({ id: 2, mode: 'ends', expires_at: '2026-11-06T23:59:59Z' }),
            grant({ id: 3, mode: 'ends', expires_at: '2027-01-01T23:59:59Z' }),
            grant({ id: 4, mode: 'renew', expires_at: '2026-10-30T23:59:59Z', past_end: true }),
        ];

        expect(sortGrants(grants, NOW).map((g) => g.id)).toEqual([4, 2, 1, 3]);
    });
});
