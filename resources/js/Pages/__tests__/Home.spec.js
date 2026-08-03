import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { Link } from '@inertiajs/vue3';
import Home from '../Home.vue';

// The page reads the shared `auth` prop through usePage(), which needs a real Inertia app —
// stub just that, keeping the rest of the adapter (Head, Link) intact for shallowMount.
// `mockUser` is reassigned per test so the fallback paths are reachable.
let mockUser = { username: 'Gryspeert.J' };
// The Cav roster snapshot the server shares (Story 1.6); null for an unenriched session.
let mockProfile = null;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: { user: mockUser, profile: mockProfile } } }),
}));

// Vitest unit test (Story 1.5). The sign-out control is the only way a leader ends a
// session, so its method matters as much as its presence: a GET would slip CSRF protection.
describe('Home page', () => {
    // renderStubDefaultSlot keeps <Link>'s label in the output while still stubbing the
    // component itself, so the control's text and its props can be asserted together.
    const factory = (user = { username: 'Gryspeert.J' }, profile = null) => {
        mockUser = user;
        mockProfile = profile;

        return shallowMount(Home, { global: { renderStubDefaultSlot: true } });
    };

    const signOutLinks = (wrapper) =>
        wrapper.findAllComponents(Link).filter((link) => link.props('href') === '/logout');

    it('offers exactly one sign-out control, posting to the logout route (AC #1)', () => {
        const signOut = signOutLinks(factory());

        expect(signOut).toHaveLength(1);
        expect(signOut[0].props('method')).toBe('post');
        expect(signOut[0].text()).toContain('Sign out');
    });

    it('renders sign-out as a button, not an anchor (AC #1)', () => {
        // An anchor would be prefetchable and would issue a GET — the pairing for the
        // server-side "not reachable by GET" assertion in LogoutTest.
        const signOut = signOutLinks(factory())[0];

        expect(signOut.props('as')).toBe('button');
        expect(signOut.attributes('type')).toBe('button');
    });

    it('shows who is signed in, mirroring the mockup top-nav user cluster', () => {
        expect(factory().find('.user').text()).toContain('Gryspeert.J');
    });

    it.each([
        ['Gryspeert.J', 'GJ'],
        ['Hale.S', 'HS'],
        ['Trooper', 'T'],
        ['some_long_user_name', 'SL'],
    ])('builds the avatar monogram from %s as %s', (username, expected) => {
        expect(factory({ username }).find('[aria-hidden="true"]').text()).toBe(expected);
    });

    it.each([
        ['a missing user', null],
        ['a user with no username', {}],
        ['an empty username', { username: '' }],
    ])('falls back to a placeholder identity for %s', (_label, user) => {
        const wrapper = factory(user);

        expect(wrapper.find('.user').text()).toContain('Trooper');
        expect(wrapper.find('[aria-hidden="true"]').text()).toBe('T');
    });

    // Story 1.6 — Cav roster context. The rule these pin is AD-20 rule 1 / NFR7: a synced
    // value never appears without its source and age, so a stale rank cannot pass for a fact
    // the portal is sure of.
    describe('Cav roster enrichment', () => {
        const enriched = (overrides = {}) => ({
            rank_short: 'WO1',
            rank_full: 'Warrant Officer 1',
            billet: 'S1 Personnel NCO',
            mos: '42A',
            roster: 'ROSTER_TYPE_COMBAT',
            source: 'Cav roster (api.7cav.us)',
            fetched_at: '2026-08-03T09:14:00+00:00',
            ...overrides,
        });

        it('renders rank, identity and billet as the mockup top-nav does', () => {
            const identity = factory({ username: 'Hale.S' }, enriched()).find('.identity');

            expect(identity.text()).toBe('WO1 Hale.S · S1 Personnel NCO');
        });

        it('shows the MOS, source and fetch time alongside the enriched values', () => {
            const provenance = factory({ username: 'Hale.S' }, enriched()).find('.provenance');

            expect(provenance.text()).toContain('MOS 42A');
            expect(provenance.text()).toContain('Cav roster (api.7cav.us)');
            // Rendered in the viewer's locale, so assert the parts rather than a format.
            expect(provenance.text()).toMatch(/\d{1,2}:\d{2}/);
        });

        it('puts the full rank title and exact timestamp in the tooltip, not the source', () => {
            const provenance = factory({ username: 'Hale.S' }, enriched()).find('.provenance');

            // The tooltip only *adds* detail: removing it must not hide the attribution.
            expect(provenance.attributes('title')).toContain('Warrant Officer 1');
            expect(provenance.text()).toContain('Cav roster (api.7cav.us)');
        });

        it('degrades to the bare username, with no provenance line, when unenriched', () => {
            const wrapper = factory({ username: 'Hale.S' }, null);

            expect(wrapper.find('.identity').text()).toBe('Hale.S');
            expect(wrapper.find('.provenance').exists()).toBe(false);
        });

        it.each([
            ['no billet (mid-transfer)', { billet: null }, 'WO1 Hale.S'],
            ['no rank', { rank_short: null }, 'Hale.S · S1 Personnel NCO'],
            ['neither rank nor billet', { rank_short: null, billet: null }, 'Hale.S'],
        ])('renders a partial roster record with %s', (_label, overrides, expected) => {
            // Each field is independently optional: one missing value must not suppress the
            // others, and must not leave a dangling separator.
            expect(factory({ username: 'Hale.S' }, enriched(overrides)).find('.identity').text()).toBe(expected);
        });

        it('omits the MOS from the provenance line when the roster has none', () => {
            const provenance = factory({ username: 'Hale.S' }, enriched({ mos: null })).find('.provenance');

            expect(provenance.text()).not.toContain('MOS');
            expect(provenance.text()).toContain('Cav roster (api.7cav.us)');
        });

        it('still attributes the source when the fetch time is unusable', () => {
            const provenance = factory({ username: 'Hale.S' }, enriched({ fetched_at: 'not-a-date' })).find(
                '.provenance',
            );

            // Better to show the source with no time than to drop the attribution entirely.
            expect(provenance.text()).toContain('Cav roster (api.7cav.us)');
            expect(provenance.text()).not.toContain('NaN');
            expect(provenance.attributes('title')).not.toContain('Invalid');
        });
    });
});
