import { describe, it, expect } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import Login from '../Login.vue';

// Vitest unit test (Story 1.4). shallowMount stubs Inertia's <Head> while keeping the
// page's own markup, so we assert on the real rendered login panel.
describe('Login page', () => {
    const factory = (props = {}) => shallowMount(Login, { props });

    it('presents a single "Continue with 7Cav" SSO action pointing at the redirect route (AC #1)', () => {
        const wrapper = factory();
        const ssoButtons = wrapper.findAll('.sso-btn');

        expect(ssoButtons).toHaveLength(1);
        expect(ssoButtons[0].text()).toContain('Continue with 7Cav');
        expect(ssoButtons[0].attributes('href')).toBe('/auth/redirect');
    });

    it('hides the error notice when no error is present', () => {
        expect(factory().find('.error').exists()).toBe(false);
    });

    it('surfaces a flashed error message (AC #5)', () => {
        const wrapper = factory({ error: 'Sign-in was cancelled.' });
        const error = wrapper.find('.error');

        expect(error.exists()).toBe(true);
        expect(error.text()).toBe('Sign-in was cancelled.');
    });
});
