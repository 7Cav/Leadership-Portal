import { afterEach, describe, expect, it, vi } from 'vitest';
import { useIdentity } from '../useIdentity';

// The identity rules moved here from Home.vue in Story 1.15; these are Story 1.5–1.7's cases,
// tested on the composable directly. AD-20 rule 1 / NFR7: a synced value never appears without its
// source and age, so a stale rank cannot pass for a fact the portal is sure of.
let mockAuth = {};
let mockViewAs = null;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: mockAuth, viewAs: mockViewAs } }),
}));

const enriched = (overrides = {}) => ({
    rank_short: 'WO1',
    rank_full: 'Warrant Officer 1',
    billet: 'S1 Personnel NCO',
    mos: '42A',
    source: 'Cav roster (api.7cav.us)',
    fetched_at: '2026-08-03T09:14:00+00:00',
    ...overrides,
});

function identityFor(user, profile = null, viewAs = null) {
    mockAuth = { user, profile };
    mockViewAs = viewAs;

    return useIdentity();
}

describe('useIdentity', () => {
    it('renders rank, username and billet as one line', () => {
        expect(identityFor({ username: 'Hale.S' }, enriched()).identity.value).toBe('WO1 Hale.S · S1 Personnel NCO');
    });

    it.each([
        ['no billet (mid-transfer)', { billet: null }, 'WO1 Hale.S'],
        ['no rank', { rank_short: null }, 'Hale.S · S1 Personnel NCO'],
        ['neither rank nor billet', { rank_short: null, billet: null }, 'Hale.S'],
    ])('renders a partial roster record with %s, with no dangling separator', (_label, overrides, expected) => {
        expect(identityFor({ username: 'Hale.S' }, enriched(overrides)).identity.value).toBe(expected);
    });

    it('shows the MOS, source and fetch time together', () => {
        const { provenance } = identityFor({ username: 'Hale.S' }, enriched());

        expect(provenance.value).toContain('MOS 42A');
        expect(provenance.value).toContain('Cav roster (api.7cav.us)');
        expect(provenance.value).toMatch(/\d{1,2}:\d{2}/);
    });

    describe('the fetch time, in UTC like the rest of the portal', () => {
        afterEach(() => vi.useRealTimers());

        it.each([
            ['today', '2026-08-03T22:00:00Z', 'Cav roster (api.7cav.us) · 09:14Z'],
            ['a day ago', '2026-08-04T10:00:00Z', 'Cav roster (api.7cav.us) · 03Aug26 09:14Z'],
        ])('shows the date only when the fetch was not %s', (_label, now, expected) => {
            // The session lasts 24h (SESSION_LIFETIME=1440): a time-only stamp would let a day-old rank
            // read as an hour old.
            vi.useFakeTimers({ toFake: ['Date'] });
            vi.setSystemTime(new Date(now));

            expect(identityFor({ username: 'Hale.S' }, enriched()).provenance.value).toBe(`MOS 42A · ${expected}`);
        });

        it('puts the exact UTC time in the detail', () => {
            expect(identityFor({ username: 'Hale.S' }, enriched()).provenanceDetail.value).toBe('Warrant Officer 1, fetched 03Aug26 09:14Z');
        });
    });

    it('omits the MOS when the roster has none, keeping the source', () => {
        const { provenance } = identityFor({ username: 'Hale.S' }, enriched({ mos: null }));

        expect(provenance.value).not.toContain('MOS');
        expect(provenance.value).toContain('Cav roster (api.7cav.us)');
    });

    it('still attributes the source when the fetch time is unusable', () => {
        const { provenance, provenanceDetail } = identityFor({ username: 'Hale.S' }, enriched({ fetched_at: 'not-a-date' }));

        expect(provenance.value).toContain('Cav roster (api.7cav.us)');
        expect(provenance.value).not.toContain('NaN');
        expect(provenanceDetail.value).not.toContain('Invalid');
    });

    it('puts the full rank title and exact time in the detail, not in place of the source', () => {
        const { provenanceDetail } = identityFor({ username: 'Hale.S' }, enriched());

        expect(provenanceDetail.value).toContain('Warrant Officer 1');
        expect(provenanceDetail.value).toContain('fetched');
    });

    it('has no provenance at all when unenriched', () => {
        const { identity, provenance } = identityFor({ username: 'Hale.S' }, null);

        expect(identity.value).toBe('Hale.S');
        expect(provenance.value).toBeNull();
    });

    it.each([
        ['a missing user', null],
        ['a user with no username', {}],
        ['an empty username', { username: '' }],
    ])('falls back to a placeholder identity for %s', (_label, user) => {
        const { identity, initials } = identityFor(user);

        expect(identity.value).toBe('Trooper');
        expect(initials.value).toBe('T');
    });

    it.each([
        [true, true],
        [false, false],
        [1, false],
        [null, false],
        [undefined, false],
    ])('reads is_admin %s as Admin = %s (only a real true counts)', (value, expected) => {
        expect(identityFor({ username: 'Hale.S', is_admin: value }).isAdmin.value).toBe(expected);
    });

    // Story 1.13: a view-as preview shows the shell as the previewed leader sees it, while the
    // real flag (which the server's gates read) is untouched.
    it.each([
        ['a unit preview', { kind: 'unit', admin: false }, false],
        ['a non-Admin person', { kind: 'user', admin: false }, false],
        ['an Admin person', { kind: 'user', admin: true }, true],
        ['a non-boolean admin flag', { kind: 'user', admin: 1 }, false],
    ])('follows the preview while an Admin views as %s', (_label, viewAs, expected) => {
        const { isAdmin, isRealAdmin } = identityFor({ username: 'Gryspeert.J', is_admin: true }, null, viewAs);

        expect(isAdmin.value).toBe(expected);
        expect(isRealAdmin.value).toBe(true);
    });
});
