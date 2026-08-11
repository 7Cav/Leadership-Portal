import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StaffGrants from '../StaffGrants.vue';

const grantSubmit = vi.fn();
let deleteSpy = vi.fn();
let grantFormState = { user_id: null, unit_id: null, reason: '', processing: false, errors: {} };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: (...args) => deleteSpy(...args) },
    useForm: () => ({ ...grantFormState, post: grantSubmit, reset: vi.fn() }),
}));

const UNITS = [
    { id: 1, name: '7th Cavalry Regiment' },
    { id: 2, name: '2nd Battalion' },
];

const USERS = [{ id: 10, username: 'jsmith' }];

const GRANTS = [{ id: 100, user_id: 10, username: 'jsmith', unit_id: 2, unit_name: '2nd Battalion', created_at: '2026-08-11T00:00:00Z' }];

describe('Settings/StaffGrants', () => {
    it('renders every current grant', () => {
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: false } });

        expect(wrapper.text()).toContain('jsmith');
        expect(wrapper.text()).toContain('2nd Battalion');
    });

    it('shows an empty state when there are no grants', () => {
        const wrapper = mount(StaffGrants, { props: { grants: [], units: UNITS, users: USERS, isAdmin: false } });

        expect(wrapper.text()).toContain('No staff grants yet.');
    });

    it('hides the grant form and revoke controls for a non-Admin (AC #4 — UI mirrors the server gate)', () => {
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: false } });

        expect(wrapper.find('[data-testid="grant-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="revoke-100"]').exists()).toBe(false);
    });

    it('shows the grant form and revoke controls for an Admin', () => {
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        expect(wrapper.find('[data-testid="grant-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="revoke-100"]').exists()).toBe(true);
    });

    it('submits the grant form to the staff-grants store route', async () => {
        grantFormState = { user_id: 10, unit_id: 1, reason: 'S1 staff, no line billet', processing: false, errors: {} };
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        await wrapper.find('[data-testid="grant-form"]').trigger('submit');

        expect(grantSubmit).toHaveBeenCalledWith('/settings/staff-grants', expect.objectContaining({ preserveScroll: true }));
    });

    it('renders a server-side validation error next to its field', () => {
        grantFormState = {
            user_id: null,
            unit_id: 1,
            reason: 'reason',
            processing: false,
            errors: { user_id: 'User #10 is already an Admin and cannot receive a staff grant.' },
        };
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        expect(wrapper.find('[data-testid="error-user_id"]').text()).toBe('User #10 is already an Admin and cannot receive a staff grant.');
    });

    it('asks for a reason and calls router.delete with it when revoking', async () => {
        vi.stubGlobal('prompt', vi.fn(() => 'no longer staff'));
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        await wrapper.find('[data-testid="revoke-100"]').trigger('click');

        expect(prompt).toHaveBeenCalled();
        expect(deleteSpy).toHaveBeenCalledWith(
            '/settings/staff-grants/100',
            expect.objectContaining({ data: { reason: 'no longer staff' }, preserveScroll: true }),
        );
        vi.unstubAllGlobals();
    });

    it('does not revoke when no reason is given', async () => {
        deleteSpy.mockClear();
        vi.stubGlobal('prompt', vi.fn(() => null));
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        await wrapper.find('[data-testid="revoke-100"]').trigger('click');

        expect(deleteSpy).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });

    it('shows the server\'s reason when a revoke is refused', async () => {
        deleteSpy = vi.fn((url, { onError }) => onError({ reason: 'The action "portal_role_grant.revoked" requires a reason before it can be recorded.' }));
        vi.stubGlobal('prompt', vi.fn(() => 'no longer staff'));
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        await wrapper.find('[data-testid="revoke-100"]').trigger('click');

        expect(wrapper.find('[data-testid="revoke-error"]').text()).toBe('The action "portal_role_grant.revoked" requires a reason before it can be recorded.');
        vi.unstubAllGlobals();
        deleteSpy = vi.fn();
    });
});
