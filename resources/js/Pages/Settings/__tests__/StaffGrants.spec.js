import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StaffGrants from '../StaffGrants.vue';

const grantSubmit = vi.fn();
const toastError = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: (...a) => toastError(...a) } }));
let deleteSpy = vi.fn();
let grantFormState = { user_id: null, unit_id: null, reason: '', processing: false, errors: {} };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: (...args) => deleteSpy(...args) },
    useForm: () => ({ ...grantFormState, post: grantSubmit, reset: vi.fn() }),
}));

const UNITS = [
    { id: 1, name: '7th Cavalry Regiment', label: '7th Cavalry Regiment' },
    { id: 2, name: '2nd Battalion', label: '2nd Battalion' },
];

const USERS = [{ id: 10, username: 'jsmith' }];

const GRANTS = [
    { id: 100, user_id: 10, username: 'jsmith', holder_serving: true, unit_id: 2, unit_name: '2nd Battalion', unit_label: '2nd Battalion', created_at: '2026-08-11T00:00:00Z' },
];

describe('Settings/StaffGrants', () => {
    it('renders every current grant', () => {
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: false } });

        expect(wrapper.text()).toContain('jsmith');
        expect(wrapper.text()).toContain('2nd Battalion');
        expect(wrapper.find('[data-testid="lapsed-100"]').exists()).toBe(false);
    });

    it('marks a grant whose holder is not serving as lapsed, still listed and revocable', () => {
        const lapsed = { ...GRANTS[0], id: 101, username: 'gone.k', holder_serving: false };
        const wrapper = mount(StaffGrants, { props: { grants: [...GRANTS, lapsed], units: UNITS, users: USERS, isAdmin: true } });

        expect(wrapper.find('[data-testid="grant-row-101"]').text()).toContain('gone.k');
        expect(wrapper.find('[data-testid="lapsed-101"]').text()).toContain('Lapsed: not serving');
        expect(wrapper.find('[data-testid="lapsed-101"] .sr-only').text()).toContain('gives no scope while its holder is not on a serving Cav roster');
        expect(wrapper.find('[data-testid="lapsed-100"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="revoke-101"]').exists()).toBe(true);
    });

    it('tells two same-named units apart by their chain in the picker and the grants list', () => {
        const units = [
            { id: 3, name: '1st Platoon', label: 'Alpha Company › 1st Platoon' },
            { id: 4, name: '1st Platoon', label: 'Bravo Company › 1st Platoon' },
        ];
        const grants = [{ ...GRANTS[0], unit_id: 4, unit_name: '1st Platoon', unit_label: 'Bravo Company › 1st Platoon' }];
        const wrapper = mount(StaffGrants, { props: { grants, units, users: USERS, isAdmin: true } });

        const options = wrapper.findAll('[data-testid="grant-unit_id"] option').map((o) => o.text());
        expect(options).toEqual(['Choose a unit', 'Alpha Company › 1st Platoon', 'Bravo Company › 1st Platoon']);
        expect(wrapper.find('[data-testid="grant-row-100"]').text()).toContain('Bravo Company › 1st Platoon');
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

    it('says why a grant was refused instead of showing the raw error page', async () => {
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        await wrapper.find('[data-testid="grant-form"]').trigger('submit');

        expect(grantSubmit.mock.lastCall[1].onHttpException({ status: 419 })).toBe(false);
        expect(toastError).toHaveBeenCalledWith('The grant was not added. Your session expired; reload the page and try again.');
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

    it('does not revoke when the prompt is cancelled, and shows no error (a deliberate no-op)', async () => {
        deleteSpy.mockClear();
        vi.stubGlobal('prompt', vi.fn(() => null));
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        await wrapper.find('[data-testid="revoke-100"]').trigger('click');

        expect(deleteSpy).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="revoke-error"]').exists()).toBe(false);
        vi.unstubAllGlobals();
    });

    // An OK-with-empty-field submission is falsy just like Cancel, but it is not the same
    // gesture — silently doing nothing here would look exactly like a broken button.
    it('shows feedback, not a silent no-op, when the prompt is submitted blank', async () => {
        deleteSpy.mockClear();
        vi.stubGlobal('prompt', vi.fn(() => '   '));
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: true } });

        await wrapper.find('[data-testid="revoke-100"]').trigger('click');

        expect(deleteSpy).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="revoke-error"]').text()).toBe('A reason is required to revoke a grant.');
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
