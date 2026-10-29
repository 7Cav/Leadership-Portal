import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import ChangelogOutdatedBanner from '../ChangelogOutdatedBanner.vue';

const page = reactive({ url: '/home', props: { changelogOutdated: false } });
const post = vi.fn();

vi.mock('vue-sonner', () => ({ toast: { error: vi.fn() } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => page,
    router: { post: (...args) => post(...args) },
}));

describe('changelog outdated bar', () => {
    it('shows a blue bar with Add entry and Dismiss while the changelog is outdated', async () => {
        page.props = { changelogOutdated: true };
        const bar = mount(ChangelogOutdatedBanner);

        expect(bar.get('[data-testid="changelog-outdated-banner"]').classes()).toContain('bg-info');
        expect(bar.text()).toContain('Changelog outdated');
        expect(bar.get('[data-testid="changelog-outdated-add"]').attributes('href')).toBe('/changelog?add=1');

        await bar.get('[data-testid="changelog-outdated-dismiss"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/changelog/outdated/dismiss', {}, expect.any(Object));
    });

    it('shows nothing otherwise', () => {
        page.props = { changelogOutdated: false };

        expect(mount(ChangelogOutdatedBanner).find('[data-testid="changelog-outdated-banner"]').exists()).toBe(false);
    });
});
