import { describe, it, expect, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
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
    // Reasons are asked for in the app's dialog (portalled to the body), not the browser's prompt().
    const dialog = () => document.body.querySelector('[data-testid="staff-grants-dialog"]');
    const field = (testid) => document.body.querySelector(`[data-testid="${testid}"]`);
    const typeReason = async (text) => {
        const input = field('staff-grants-dialog-reason');
        input.value = text;
        input.dispatchEvent(new Event('input'));
        await nextTick();
    };
    const confirmDialog = async () => {
        field('staff-grants-dialog-confirm').click();
        await flushPromises();
    };
    const open = async (wrapper, testid) => {
        await wrapper.find(`[data-testid="${testid}"]`).trigger('click');
        await flushPromises();
    };
    const attached = (props) => mount(StaffGrants, { props, attachTo: document.body });

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

    it('asks for a reason in a dialog and calls router.delete with it when revoking', async () => {
        deleteSpy = vi.fn();
        const wrapper = attached({ grants: GRANTS, units: UNITS, users: USERS, isAdmin: true });

        await open(wrapper, 'revoke-100');
        expect(dialog().textContent).toContain("Revoke jsmith's grant at 2nd Battalion?");
        expect(dialog().querySelector('label[for="staff-grants-dialog-reason"]').textContent).toContain('Reason for the record');

        await typeReason('no longer staff');
        await confirmDialog();

        expect(deleteSpy).toHaveBeenCalledWith(
            '/settings/staff-grants/100',
            expect.objectContaining({ data: { reason: 'no longer staff' }, preserveScroll: true }),
        );
        wrapper.unmount();
    });

    it('does not revoke when the dialog is cancelled, and shows no error (a deliberate no-op)', async () => {
        deleteSpy = vi.fn();
        const wrapper = attached({ grants: GRANTS, units: UNITS, users: USERS, isAdmin: true });

        await open(wrapper, 'revoke-100');
        [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cancel').click();
        await flushPromises();

        expect(deleteSpy).not.toHaveBeenCalled();
        expect(dialog()).toBeNull();
        wrapper.unmount();
    });

    // A blank reason cannot be sent: the confirm stays disabled until there is one, so it is never a silent no-op.
    it('keeps the revoke disabled until a reason is typed', async () => {
        deleteSpy = vi.fn();
        const wrapper = attached({ grants: GRANTS, units: UNITS, users: USERS, isAdmin: true });

        await open(wrapper, 'revoke-100');
        expect(field('staff-grants-dialog-confirm').disabled).toBe(true);
        await typeReason('   ');
        expect(field('staff-grants-dialog-confirm').disabled).toBe(true);
        await confirmDialog();

        expect(deleteSpy).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it("shows the server's reason inside the dialog when a revoke is refused", async () => {
        deleteSpy = vi.fn((url, { onError }) => onError({ reason: 'The action "portal_role_grant.revoked" requires a reason before it can be recorded.' }));
        const wrapper = attached({ grants: GRANTS, units: UNITS, users: USERS, isAdmin: true });

        await open(wrapper, 'revoke-100');
        await typeReason('no longer staff');
        await confirmDialog();

        expect(field('staff-grants-dialog-error').textContent).toBe('The action "portal_role_grant.revoked" requires a reason before it can be recorded.');
        wrapper.unmount();
        deleteSpy = vi.fn();
    });

    it('says in plain words why a revoke failed on an HTTP error, instead of the raw error page', async () => {
        let handled;
        deleteSpy = vi.fn((url, { onHttpException }) => {
            handled = onHttpException({ status: 403 });
        });
        const wrapper = attached({ grants: GRANTS, units: UNITS, users: USERS, isAdmin: true });

        await open(wrapper, 'revoke-100');
        await typeReason('no longer staff');
        await confirmDialog();

        expect(handled).toBe(false);
        expect(field('staff-grants-dialog-error').textContent).toBe('The grant was not revoked. You are not allowed to do that; reload the page, or exit view as first.');
        wrapper.unmount();
        deleteSpy = vi.fn();
    });

    describe('Admin via billet', () => {
        const APPROVED = [
            {
                id: 31,
                title: 'Regimental Chief of Staff',
                group: 'Regimental HQ',
                status: 'approved',
                approvedAs: null,
                adminGroup: true,
                holders: ['COL Mercer.T'],
                own: false,
                decidedAt: '2026-10-28T10:00:00Z',
                decidedBy: 'Hale.S',
            },
            {
                id: 32,
                title: 'Regimental Adjutant General',
                group: 'Regimental HQ',
                status: 'approved',
                approvedAs: null,
                adminGroup: true,
                holders: ['MAJ Abbot.F', 'CPT Doyle.K'],
                own: true,
                decidedAt: '2026-10-27T09:00:00Z',
                decidedBy: 'Hale.S',
            },
            {
                id: 33,
                title: 'S6 1IC',
                group: 'S6 HQ',
                status: 'approved',
                approvedAs: null,
                adminGroup: false,
                holders: [],
                own: false,
                decidedAt: null,
                decidedBy: null,
            },
        ];
        const HQ = [
            { id: 7, name: 'COL Mercer.T', billet: 'Regimental Chief of Staff' },
            { id: 8, name: 'MAJ #4410', billet: null },
        ];
        const mountAdmin = (props = {}) =>
            attached({ grants: GRANTS, units: UNITS, users: USERS, adminViaBillet: APPROVED, regimentalHq: HQ, isAdmin: true, ...props });

        it('lists each approved billet with its names, every holder, and who approved it and when', () => {
            const wrapper = mountAdmin();
            const section = wrapper.find('[data-testid="admin-via-billet"]');

            expect(section.find('h2').text()).toBe('Admin via billet');
            expect(section.text()).toContain('Whoever holds one of these billets is an Admin because of the billet, not a grant');

            const chief = wrapper.find('[data-testid="admin-via-billet-31"]');
            expect(chief.text()).toContain('Regimental Chief of Staff');
            expect(wrapper.find('[data-testid="admin-via-billet-group-31"]').text()).toBe('Regimental HQ');
            expect(chief.text()).toContain('COL Mercer.T');
            expect(wrapper.find('[data-testid="admin-via-billet-approved-31"]').text()).toBe('28Oct26 by Hale.S');

            expect(wrapper.find('[data-testid="admin-via-billet-32"]').text()).toContain('MAJ Abbot.F, CPT Doyle.K');
            expect(wrapper.find('[data-testid="admin-via-billet-33"]').text()).toContain('Vacant');
            expect(wrapper.find('[data-testid="admin-via-billet-approved-33"]').text()).toBe('Not known');
        });

        it('shows no "Approved as" line or Renamed tag when the names match, and flags a group that cannot make an Admin', () => {
            const wrapper = mountAdmin();

            expect(wrapper.find('[data-testid="admin-via-billet-approved-as-31"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-via-billet-renamed-31"]').exists()).toBe(false);
            expect(wrapper.text()).not.toContain('Approved as:');
            expect(wrapper.find('[data-testid="admin-via-billet-not-admin-31"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-via-billet-not-admin-33"]').text()).toContain('Not an Admin group');
        });

        it('shows the approved names under the billet, with the Renamed tag, when they differ from the names now', () => {
            const wrapper = mountAdmin({
                adminViaBillet: [{ ...APPROVED[0], approvedAs: { title: 'Chief of Staff', group: 'Regimental HQ' } }],
            });

            expect(wrapper.find('[data-testid="admin-via-billet-approved-as-31"]').text()).toBe('Approved as: Chief of Staff in Regimental HQ');
            expect(wrapper.find('[data-testid="admin-via-billet-renamed-31"]').text()).toBe('Renamed');
        });

        it("offers revoke on every approved billet but the viewer's own, and posts the reject with a reason", async () => {
            postSpy = vi.fn();
            const wrapper = mountAdmin();

            expect(wrapper.find('[data-testid="admin-via-billet-revoke-32"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-via-billet-own-32"]').text()).toBe('Your billet');

            await open(wrapper, 'admin-via-billet-revoke-31');
            expect(dialog().textContent).toContain('Revoke Regimental Chief of Staff as an Admin billet?');
            await typeReason('Left the S6 shop');
            await confirmDialog();

            expect(postSpy).toHaveBeenCalledWith(
                '/settings/staff-grants/admin-billets/31/reject',
                { reason: 'Left the S6 shop' },
                expect.objectContaining({ preserveScroll: true }),
            );
            wrapper.unmount();
        });

        it("posts no revoke without a reason, and shows the server's refusal in the dialog", async () => {
            postSpy = vi.fn((url, data, { onError }) => onError({ adminBillet: 'You hold this billet, so another Admin has to decide on it.' }));
            const wrapper = mountAdmin();

            await open(wrapper, 'admin-via-billet-revoke-31');
            await confirmDialog();
            expect(postSpy).not.toHaveBeenCalled();

            await typeReason('reason');
            await confirmDialog();
            expect(field('staff-grants-dialog-error').textContent).toBe('You hold this billet, so another Admin has to decide on it.');
            wrapper.unmount();
        });

        it('notes the Regimental HQ staff who read the whole regiment from their placement', () => {
            const note = mountAdmin().find('[data-testid="regimental-hq"]');

            expect(note.text()).toContain('Placed on the regiment, so they read all of it, read-only');
            expect(note.find('[data-testid="regimental-hq-7"]').text()).toBe('COL Mercer.T (Regimental Chief of Staff)');
            expect(note.find('[data-testid="regimental-hq-8"]').text()).toBe('MAJ #4410');
            expect(note.find('button').exists()).toBe(false);

            expect(mountAdmin({ regimentalHq: [] }).find('[data-testid="regimental-hq"]').exists()).toBe(false);
        });

        it('says so when nothing is approved, and is not shown to a non-Admin', () => {
            expect(mountAdmin({ adminViaBillet: [] }).find('[data-testid="admin-via-billet-empty"]').text()).toBe(
                'No billet is approved yet. Approve one below to make whoever holds it an Admin.',
            );
            expect(mountAdmin({ isAdmin: false }).find('[data-testid="admin-via-billet"]').exists()).toBe(false);
        });
    });

    describe('Admin billets', () => {
        const BILLETS = [
            {
                id: 21,
                title: 'Regimental Chief of Staff',
                group: 'Regimental HQ',
                status: 'pending',
                approvedAs: null,
                adminGroup: true,
                holders: ['COL Mercer.T'],
                own: false,
                decidedAt: null,
                decidedBy: null,
            },
            {
                id: 22,
                title: 'Regimental Adjutant General',
                group: 'Regimental HQ',
                status: 'pending',
                approvedAs: { title: 'Adjutant General', group: 'Regimental HQ' },
                adminGroup: true,
                holders: [],
                own: false,
                decidedAt: '2026-10-28T10:00:00Z',
                decidedBy: 'Hale.S',
            },
            {
                id: 23,
                title: 'Regimental Finance Officer',
                group: 'Regimental HQ',
                status: 'rejected',
                approvedAs: null,
                adminGroup: true,
                holders: ['CPT Hale.S'],
                own: true,
                decidedAt: '2026-10-28T10:00:00Z',
                decidedBy: 'Abbot.F',
            },
            {
                id: 24,
                title: 'S6 1IC',
                group: 'Support Attachment',
                status: 'pending',
                approvedAs: { title: 'S6 1IC', group: 'S6 HQ' },
                adminGroup: false,
                holders: ['SGT Wire.S'],
                own: false,
                decidedAt: '2026-10-20T10:00:00Z',
                decidedBy: 'Hale.S',
            },
            {
                id: 25,
                title: 'S6 Clerk',
                group: 'S6 HQ',
                status: 'rejected',
                approvedAs: null,
                adminGroup: true,
                holders: [],
                own: false,
                decidedAt: '2026-10-20T10:00:00Z',
                decidedBy: 'Hale.S',
            },
        ];
        const mountAdmin = () => attached({ grants: GRANTS, units: UNITS, users: USERS, adminBillets: BILLETS, isAdmin: true });

        it('lists each pending or rejected billet with its names now, its holder and its status, and no "Confirmed as" column', () => {
            const wrapper = mountAdmin();

            const first = wrapper.find('[data-testid="admin-billet-21"]');
            expect(first.text()).toContain('Regimental Chief of Staff');
            expect(first.text()).not.toContain(' in Regimental HQ');
            expect(first.text()).toContain('COL Mercer.T');
            expect(wrapper.find('[data-testid="admin-billet-status-21"]').text()).toBe('Waiting for an Admin');
            expect(wrapper.find('[data-testid="admin-billet-status-23"]').text()).toBe('Rejected');
            expect(wrapper.find('[data-testid="admin-billet-22"]').text()).toContain('Vacant');
            expect(wrapper.find('[data-testid="admin-billets"]').text()).not.toContain('Confirmed as');
            expect(wrapper.find('[data-testid="admin-billets"]').text()).not.toContain('Not confirmed yet');
        });

        it('shows "Approved as" with the Renamed tag only when the approved names differ from the names now', () => {
            const wrapper = mountAdmin();

            expect(wrapper.find('[data-testid="admin-billet-approved-as-22"]').text()).toBe('Approved as: Adjutant General in Regimental HQ');
            expect(wrapper.find('[data-testid="admin-billet-renamed-22"]').text()).toBe('Renamed');
            for (const id of [21, 23, 25]) {
                expect(wrapper.find(`[data-testid="admin-billet-approved-as-${id}"]`).exists()).toBe(false);
                expect(wrapper.find(`[data-testid="admin-billet-renamed-${id}"]`).exists()).toBe(false);
            }
        });

        it('shows a billet moved out of the Admin billet groups as such, with no approve', () => {
            const wrapper = mountAdmin();

            expect(wrapper.find('[data-testid="admin-billet-24"]').text()).toContain('S6 1IC');
            expect(wrapper.find('[data-testid="admin-billet-group-24"]').text()).toBe('Support Attachment');
            expect(wrapper.find('[data-testid="admin-billet-approved-as-24"]').text()).toBe('Approved as: S6 1IC in S6 HQ');
            expect(wrapper.find('[data-testid="admin-billet-not-admin-24"]').text()).toContain('Not an Admin group');
            expect(wrapper.find('[data-testid="admin-billet-approve-24"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-billet-reject-24"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="admin-billet-not-admin-21"]').exists()).toBe(false);
        });

        it("offers approve and reject by status, and neither on the viewer's own billet", () => {
            const wrapper = mountAdmin();

            expect(wrapper.find('[data-testid="admin-billet-approve-21"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="admin-billet-reject-21"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="admin-billet-approve-25"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="admin-billet-reject-25"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-billet-approve-23"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-billet-reject-23"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="admin-billet-own-23"]').text()).toBe('Your billet');
        });

        it('asks for a reason in a dialog and posts the decision with it', async () => {
            postSpy = vi.fn();
            const wrapper = mountAdmin();

            await open(wrapper, 'admin-billet-approve-21');
            expect(dialog().textContent).toContain('as an Admin billet?');
            await typeReason('Confirmed with the S6');
            await confirmDialog();

            expect(postSpy).toHaveBeenCalledWith(
                '/settings/staff-grants/admin-billets/21/approve',
                { reason: 'Confirmed with the S6' },
                expect.objectContaining({ preserveScroll: true }),
            );

            // The dialog closes on success; the next one starts with an empty reason.
            postSpy.mock.calls[0][2].onSuccess();
            await flushPromises();
            await open(wrapper, 'admin-billet-reject-22');
            expect(field('staff-grants-dialog-reason').value).toBe('');
            await typeReason('Not an S6 billet');
            await confirmDialog();

            expect(postSpy).toHaveBeenCalledWith(
                '/settings/staff-grants/admin-billets/22/reject',
                { reason: 'Not an S6 billet' },
                expect.objectContaining({ preserveScroll: true }),
            );
            wrapper.unmount();
        });

        it("posts nothing without a reason, shows the server's refusal, and puts an HTTP error in plain words", async () => {
            postSpy = vi.fn((url, data, { onError }) => onError({ adminBillet: 'You hold this billet, so another Admin has to decide on it.' }));
            const wrapper = mountAdmin();

            await open(wrapper, 'admin-billet-approve-21');
            expect(field('staff-grants-dialog-confirm').disabled).toBe(true);
            await confirmDialog();
            expect(postSpy).not.toHaveBeenCalled();

            await typeReason('reason');
            await confirmDialog();
            expect(field('staff-grants-dialog-error').textContent).toBe('You hold this billet, so another Admin has to decide on it.');

            postSpy = vi.fn((url, data, { onHttpException }) => onHttpException({ status: 500 }));
            await confirmDialog();
            expect(field('staff-grants-dialog-error').textContent).toBe('The decision was not saved (error 500). Try again.');
            wrapper.unmount();
        });

        it('says so when no billet waits, and is not shown to a non-Admin', () => {
            const empty = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, adminBillets: [], isAdmin: true } });
            expect(empty.find('[data-testid="admin-billets-empty"]').text()).toBe(
                'No billet is waiting for a decision. The org import lists every Regimental HQ and S6 HQ billet here.',
            );

            const leader = mount(StaffGrants, { props: { grants: GRANTS, units: UNITS, users: USERS, adminBillets: BILLETS, isAdmin: false } });
            expect(leader.find('[data-testid="admin-billets"]').exists()).toBe(false);
        });
    });
});
