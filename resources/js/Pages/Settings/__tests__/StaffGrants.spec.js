import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import StaffGrants from '../StaffGrants.vue';

const grantSubmit = vi.fn();
const toastError = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: (...a) => toastError(...a) } }));
let deleteSpy = vi.fn();
let postSpy = vi.fn();
let grantFormState = { user_id: null, unit_id: null, reason: '', processing: false, errors: {} };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { delete: (...args) => deleteSpy(...args), post: (...args) => postSpy(...args) },
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
    it('keeps the column headers in view while the list scrolls', () => {
        const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, isAdmin: false } });

        expect(wrapper.find('[data-slot="table-container"]').attributes('data-sticky-head')).toBeDefined();
    });

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

        expect(wrapper.text()).toContain('No staff grants yet');
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

    describe('Regimental HQ staff', () => {
        const HQ = [
            { id: 7, name: 'COL Mercer.T', billet: 'Regimental Chief of Staff', admin: true },
            { id: 8, name: 'MAJ #4410', billet: null, admin: false },
        ];

        it('lists Regimental HQ staff as Admins by billet, with why, and nothing to revoke', () => {
            const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, regimentalHq: HQ, isAdmin: true } });
            const section = wrapper.find('[data-testid="regimental-hq"]');

            expect(section.find('h2').text()).toBe('Regimental HQ staff (Admin by billet)');
            expect(section.text()).toContain('Regimental HQ staff are Admins because of their billet, not a grant');
            expect(section.text()).toContain('They lose it when they leave the billet or stop serving');
            expect(section.find('[data-testid="regimental-hq-admin-7"]').text()).toBe('Admin');
            expect(section.find('[data-testid="regimental-hq-admin-8"]').exists()).toBe(false);
            expect(section.find('[data-testid="regimental-hq-8"]').text()).toContain('Read-only until the billet is approved');
            expect(section.find('[data-testid="regimental-hq-7"]').text()).toContain('COL Mercer.T');
            expect(section.find('[data-testid="regimental-hq-7"]').text()).toContain('Regimental Chief of Staff');
            expect(section.find('[data-testid="regimental-hq-8"]').text()).toContain('MAJ #4410');
            expect(section.find('[data-testid="regimental-hq-8"]').text()).toContain('—');
            expect(section.find('button').exists()).toBe(false);
        });

        it('says so when no one is placed on the regiment', () => {
            const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, regimentalHq: [], isAdmin: true } });

            expect(wrapper.find('[data-testid="regimental-hq-empty"]').text()).toBe('No serving trooper is placed on the regiment.');
        });

        it('is not shown to a non-Admin', () => {
            const wrapper = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, regimentalHq: HQ, isAdmin: false } });

            expect(wrapper.find('[data-testid="regimental-hq"]').exists()).toBe(false);
        });
    });

    describe('Admin billets', () => {
        const BILLETS = [
            {
                id: 21,
                title: 'Regimental Chief of Staff',
                group: 'Regimental HQ',
                confirmedTitle: null,
                confirmedGroup: null,
                status: 'pending',
                renamed: false,
                holders: ['COL Mercer.T'],
                own: false,
                decidedAt: null,
            },
            {
                id: 22,
                title: 'Regimental Adjutant General',
                group: 'Regimental HQ',
                confirmedTitle: 'Adjutant General',
                confirmedGroup: 'Regimental HQ',
                status: 'pending',
                renamed: true,
                holders: [],
                own: false,
                decidedAt: '2026-10-28T10:00:00Z',
            },
            {
                id: 23,
                title: 'Regimental Finance Officer',
                group: 'Regimental HQ',
                confirmedTitle: 'Regimental Finance Officer',
                confirmedGroup: 'Regimental HQ',
                status: 'approved',
                renamed: false,
                holders: ['CPT Hale.S'],
                own: true,
                decidedAt: '2026-10-28T10:00:00Z',
            },
        ];
        const mountAdmin = () => mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, adminBillets: BILLETS, isAdmin: true } });

        it('lists each billet with its names now, its holder, what was confirmed and its status', () => {
            const wrapper = mountAdmin();

            const first = wrapper.find('[data-testid="admin-billet-21"]');
            expect(first.text()).toContain('Regimental Chief of Staff in Regimental HQ');
            expect(first.text()).toContain('COL Mercer.T');
            expect(first.text()).toContain('Not confirmed yet');
            expect(wrapper.find('[data-testid="admin-billet-status-21"]').text()).toBe('Waiting for an Admin');

            const renamed = wrapper.find('[data-testid="admin-billet-22"]');
            expect(renamed.text()).toContain('Vacant');
            expect(renamed.text()).toContain('Adjutant General in Regimental HQ');
            expect(wrapper.find('[data-testid="admin-billet-renamed-22"]').text()).toBe('Renamed');
            expect(wrapper.find('[data-testid="admin-billet-renamed-21"]').exists()).toBe(false);
        });

        it("offers approve and reject by status, and neither on the viewer's own billet", () => {
            const wrapper = mountAdmin();

            expect(wrapper.find('[data-testid="admin-billet-approve-21"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="admin-billet-reject-21"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="admin-billet-approve-23"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-billet-reject-23"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-billet-own-23"]').text()).toBe('Your billet');
        });

        it('asks for a reason and posts the decision with it', async () => {
            postSpy = vi.fn();
            vi.stubGlobal('prompt', vi.fn(() => 'Confirmed with the S6'));
            const wrapper = mountAdmin();

            await wrapper.find('[data-testid="admin-billet-approve-21"]').trigger('click');
            await wrapper.find('[data-testid="admin-billet-reject-22"]').trigger('click');

            expect(postSpy).toHaveBeenCalledWith(
                '/settings/staff-grants/admin-billets/21/approve',
                { reason: 'Confirmed with the S6' },
                expect.objectContaining({ preserveScroll: true }),
            );
            expect(postSpy).toHaveBeenCalledWith(
                '/settings/staff-grants/admin-billets/22/reject',
                { reason: 'Confirmed with the S6' },
                expect.objectContaining({ preserveScroll: true }),
            );
            vi.unstubAllGlobals();
        });

        it("posts nothing on a blank reason, says why, and shows the server's refusal", async () => {
            postSpy = vi.fn();
            vi.stubGlobal('prompt', vi.fn(() => '  '));
            const wrapper = mountAdmin();

            await wrapper.find('[data-testid="admin-billet-approve-21"]').trigger('click');
            expect(postSpy).not.toHaveBeenCalled();
            expect(wrapper.find('[data-testid="billet-error"]').text()).toBe('A reason is required to approve a billet.');

            postSpy = vi.fn((url, data, { onError }) => onError({ adminBillet: 'You hold this billet, so another Admin has to decide on it.' }));
            vi.stubGlobal('prompt', vi.fn(() => 'reason'));
            await wrapper.find('[data-testid="admin-billet-approve-21"]').trigger('click');
            expect(wrapper.find('[data-testid="billet-error"]').text()).toBe('You hold this billet, so another Admin has to decide on it.');
            vi.unstubAllGlobals();
        });

        it('says so when no billet has been seen, and is not shown to a non-Admin', () => {
            const empty = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, adminBillets: [], isAdmin: true } });
            expect(empty.find('[data-testid="admin-billets-empty"]').exists()).toBe(true);

            const leader = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, adminBillets: BILLETS, isAdmin: false } });
            expect(leader.find('[data-testid="admin-billets"]').exists()).toBe(false);
        });
    });
});
