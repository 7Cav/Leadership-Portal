import { describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { mount } from '@vue/test-utils';
import ActingAsBanner from '../ActingAsBanner.vue';
import ViewAsBanner from '../ViewAsBanner.vue';

const page = reactive({ url: '/home', props: { actingAs: null, viewAs: null } });
const del = vi.fn();
const post = vi.fn();
const toastError = vi.hoisted(() => vi.fn());

vi.mock('vue-sonner', () => ({ toast: { error: toastError } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => page,
    router: { delete: (...args) => del(...args), post: (...args) => post(...args) },
}));

describe('act as (#379)', () => {
    it('shows a red bar naming who the Admin acts as, and stops on request', async () => {
        page.props = { actingAs: { label: 'SGT Vasquez.R', admin: 'Hale.S' }, viewAs: null };
        const bar = mount(ActingAsBanner);

        expect(bar.get('[data-testid="acting-as-banner"]').classes()).toContain('bg-destructive');
        expect(bar.text()).toContain('Acting as SGT Vasquez.R');
        expect(bar.text()).toContain('recorded as Hale.S acting for them');

        await bar.get('[data-testid="acting-as-stop"]').trigger('click');
        expect(del).toHaveBeenCalledWith('/act-as', expect.any(Object));
    });

    it('shows no red bar when nobody is acting', () => {
        page.props = { actingAs: null, viewAs: null };

        expect(mount(ActingAsBanner).find('[data-testid="acting-as-banner"]').exists()).toBe(false);
    });

    it('says on the view-as bar that the preview is read only', () => {
        page.props = { auth: { user: { id: 1, is_admin: false } }, actingAs: null, viewAs: { kind: 'unit', id: 3, label: '2/1/A/1-7', designation: '2/1/A/1-7', unitName: '2nd Section', admin: false, actAs: false } };
        const banner = mount(ViewAsBanner);

        expect(banner.get('[data-testid="view-as-read-only"]').text()).toBe('. Read only: nothing can be changed while viewing as.');
        expect(banner.get('[data-testid="view-as-read-only-short"]').text()).toBe('. Read only.');
    });

    // `viewAs.actAs` is the real user's Admin; `auth.user.is_admin` follows the preview, so a previewed leader's
    // false never hides the offer from the Admin previewing them.
    it('offers "Act as them" on a preview of any person, Admins included, to an Admin only', () => {
        const person = { kind: 'user', id: 4, label: 'SGT Vasquez.R', designation: null, unitName: null, admin: false, actAs: true };
        page.props = { auth: { user: { id: 1, is_admin: false } }, actingAs: null, viewAs: person };
        expect(mount(ViewAsBanner).get('[data-testid="act-as-open"]').text()).toBe('Act as them');

        page.props.viewAs = { ...person, admin: true };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(true);

        page.props = { auth: { user: { id: 2, is_admin: false } }, actingAs: null, viewAs: { ...person, actAs: false } };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(false);
    });

    it("offers 'Act as this billet' on an Admin's preview of a unit, never on a leader's", async () => {
        const unit = { kind: 'unit', id: 3, label: '2/1/A/1-7', designation: '2/1/A/1-7', unitName: '2nd Section', admin: false, actAs: true };
        page.props = { auth: { user: { id: 1, is_admin: false } }, actingAs: null, viewAs: unit };
        const banner = mount(ViewAsBanner, { attachTo: document.body });
        expect(banner.get('[data-testid="act-as-open"]').text()).toBe('Act as this billet');

        await banner.get('[data-testid="act-as-open"]').trigger('click');
        await document.querySelector('[data-testid="act-as-confirm"]').click();
        expect(post).toHaveBeenCalledWith('/act-as', { kind: 'unit', id: 3 }, expect.any(Object));
        banner.unmount();

        page.props = { auth: { user: { id: 2, is_admin: false } }, actingAs: null, viewAs: { ...unit, actAs: false } };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(false);
    });

    it("says a billet's changes are recorded as the Admin acting as the billet", () => {
        page.props = { actingAs: { kind: 'unit', label: 'Section Leader 2/1/A/1-7', admin: 'Hale.S' }, viewAs: null };

        expect(mount(ActingAsBanner).text()).toContain('Acting as Section Leader 2/1/A/1-7');
        expect(mount(ActingAsBanner).text()).toContain('recorded as Hale.S acting as this billet');
    });

    // A refusal (403), the write limit (429) or a server error is said in plain words, never in Inertia's raw error dialog.
    it('says in a toast when stopping fails, and keeps the raw error dialog away', async () => {
        page.props = { actingAs: { label: 'SGT Vasquez.R', admin: 'Hale.S' }, viewAs: null };
        const bar = mount(ActingAsBanner);

        await bar.get('[data-testid="acting-as-stop"]').trigger('click');
        const options = del.mock.calls.at(-1)[1];

        expect(options.onHttpException({ status: 429 })).toBe(false);
        expect(toastError).toHaveBeenLastCalledWith('Acting did not stop. Too many requests; wait a minute and try again.');
    });

    it('says in a toast when leaving view as fails', async () => {
        const person = { kind: 'user', id: 4, label: 'SGT Vasquez.R', designation: null, unitName: null, admin: false };
        page.props = { auth: { user: { id: 1, is_admin: true } }, actingAs: null, viewAs: person };
        const banner = mount(ViewAsBanner);

        await banner.get('[data-testid="view-as-exit"]').trigger('click');
        const options = del.mock.calls.at(-1)[1];

        expect(del.mock.calls.at(-1)[0]).toBe('/view-as');
        expect(options.onHttpException({ status: 500 })).toBe(false);
        expect(toastError).toHaveBeenLastCalledWith('View as did not end (error 500). Try again.');
    });

    it('shows a refused act as from a preview in the dialog, in plain words', async () => {
        const person = { kind: 'user', id: 4, label: 'SGT Vasquez.R', designation: null, unitName: null, admin: false, actAs: true };
        page.props = { auth: { user: { id: 1, is_admin: true } }, actingAs: null, viewAs: person };
        post.mockImplementationOnce((url, data, options) => options.onHttpException({ status: 403 }));
        const banner = mount(ViewAsBanner, { attachTo: document.body });

        await banner.get('[data-testid="act-as-open"]').trigger('click');
        await document.querySelector('[data-testid="act-as-confirm"]').click();
        await nextTick();

        expect(document.querySelector('[data-testid="act-as-error"]').textContent).toBe(
            'Acting did not start. You are not allowed to do that; reload the page, or exit view as first.',
        );
        banner.unmount();
    });
});
