import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { Link } from '@inertiajs/vue3';
import Home from '../Home.vue';

// The page reads the shared `auth` prop through usePage(), which needs a real Inertia app —
// stub just that, keeping the rest of the adapter (Head, Link) intact for shallowMount.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: { user: { username: 'Gryspeert.J' } } } }),
}));

// Vitest unit test (Story 1.5). The sign-out control is the only way a leader ends a
// session, so its method matters as much as its presence: a GET would slip CSRF protection.
describe('Home page', () => {
    // renderStubDefaultSlot keeps <Link>'s label in the output while still stubbing the
    // component itself, so the control's text and its props can be asserted together.
    const factory = () => shallowMount(Home, { global: { renderStubDefaultSlot: true } });

    it('offers exactly one sign-out control, posting to the logout route (AC #1)', () => {
        const links = factory().findAllComponents(Link);
        const signOut = links.filter((link) => link.props('href') === '/logout');

        expect(signOut).toHaveLength(1);
        expect(signOut[0].props('method')).toBe('post');
        expect(signOut[0].text()).toContain('Sign out');
    });

    it('shows who is signed in, mirroring the mockup top-nav user cluster', () => {
        const wrapper = factory();

        expect(wrapper.find('.user').text()).toContain('Gryspeert.J');
    });
});
