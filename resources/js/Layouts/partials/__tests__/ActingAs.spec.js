import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import ActingAsBanner from '../ActingAsBanner.vue';
import ViewAsBanner from '../ViewAsBanner.vue';

const page = reactive({ url: '/home', props: { actingAs: null, viewAs: null } });
const del = vi.fn();
const post = vi.fn();

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

    it('offers "Act as them" on a preview of any person, Admins included, to an Admin only', () => {
        const person = { kind: 'user', id: 4, label: 'SGT Vasquez.R', designation: null, unitName: null, admin: false };
        page.props = { auth: { user: { id: 1, is_admin: true } }, actingAs: null, viewAs: person };
        expect(mount(ViewAsBanner).get('[data-testid="act-as-open"]').text()).toBe('Act as them');

        page.props.viewAs = { ...person, admin: true };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(true);

        page.props = { auth: { user: { id: 2, is_admin: false } }, actingAs: null, viewAs: person };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(false);
    });

    it("offers 'Act as this billet' on an Admin's preview of a unit, never on a leader's", async () => {
        const unit = { kind: 'unit', id: 3, label: '2/1/A/1-7', designation: '2/1/A/1-7', unitName: '2nd Section', admin: false };
        page.props = { auth: { user: { id: 1, is_admin: true } }, actingAs: null, viewAs: unit };
        const banner = mount(ViewAsBanner, { attachTo: document.body });
        expect(banner.get('[data-testid="act-as-open"]').text()).toBe('Act as this billet');

        await banner.get('[data-testid="act-as-open"]').trigger('click');
        await document.querySelector('[data-testid="act-as-confirm"]').click();
        expect(post).toHaveBeenCalledWith('/act-as', { kind: 'unit', id: 3 }, expect.any(Object));
        banner.unmount();

        page.props = { auth: { user: { id: 2, is_admin: false } }, actingAs: null, viewAs: unit };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(false);
    });

    it("says a billet's changes are recorded as the Admin acting as the billet", () => {
        page.props = { actingAs: { kind: 'unit', label: 'Section Leader 2/1/A/1-7', admin: 'Hale.S' }, viewAs: null };

        expect(mount(ActingAsBanner).text()).toContain('Acting as Section Leader 2/1/A/1-7');
        expect(mount(ActingAsBanner).text()).toContain('recorded as Hale.S acting as this billet');
    });
});
