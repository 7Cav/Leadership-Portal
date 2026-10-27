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

    it('offers "Act as them" only on a preview of a person who is not an Admin', () => {
        page.props = { actingAs: null, viewAs: { kind: 'user', id: 4, label: 'SGT Vasquez.R', designation: null, unitName: null, admin: false } };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(true);

        page.props.viewAs = { ...page.props.viewAs, admin: true };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(false);

        page.props.viewAs = { kind: 'unit', id: 3, label: '1/1/A/1-7', designation: '1/1/A/1-7', unitName: '1st Section', admin: false };
        expect(mount(ViewAsBanner).find('[data-testid="act-as-open"]').exists()).toBe(false);
    });
});
