import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { Link } from '@inertiajs/vue3';
import Home from '../Home.vue';

// The page reads the shared `auth` prop through usePage(), which needs a real Inertia app —
// stub just that, keeping the rest of the adapter (Head, Link) intact for shallowMount.
// `mockUser` is reassigned per test so the fallback paths are reachable.
let mockUser = { username: 'Gryspeert.J' };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: { user: mockUser } } }),
}));

// Vitest unit test (Story 1.5). The sign-out control is the only way a leader ends a
// session, so its method matters as much as its presence: a GET would slip CSRF protection.
describe('Home page', () => {
    // renderStubDefaultSlot keeps <Link>'s label in the output while still stubbing the
    // component itself, so the control's text and its props can be asserted together.
    const factory = (user = { username: 'Gryspeert.J' }) => {
        mockUser = user;

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
});
