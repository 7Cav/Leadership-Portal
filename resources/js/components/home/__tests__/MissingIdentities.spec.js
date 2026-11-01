import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MissingIdentities from '../MissingIdentities.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const troopers = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1, name: `PFC Trooper${i + 1}.A` }));

describe('MissingIdentities', () => {
    it('shows nothing when nobody is missing an identity', () => {
        expect(mount(MissingIdentities, { props: { groups: [] } }).find('[data-testid="missing-identities-banner"]').exists()).toBe(false);
    });

    it('names the game and the first few troopers, with the rest behind Show all', async () => {
        const wrapper = mount(MissingIdentities, { props: { groups: [{ game: 'Hell Let Loose: Vietnam', count: 5, troopers: troopers(5) }] } });
        const line = wrapper.find('[data-testid="missing-identity-group"]');

        expect(line.text()).toContain('5 of your troopers have no Hell Let Loose: Vietnam identity linked');
        expect(wrapper.attributes('role')).toBe('alert');
        expect(wrapper.text()).toContain("so their practice attendance and make-ups won't be recorded automatically");
        expect(line.text()).toContain('PFC Trooper3.A');
        expect(line.text()).not.toContain('PFC Trooper4.A');
        expect(wrapper.find('[data-testid="missing-identities-link"]').attributes('href')).toBe('/game-identities');
        expect(wrapper.find('[data-testid="missing-identities-link"]').text()).toBe('Link them on Game identities');

        await wrapper.find('[data-testid="missing-identity-toggle"]').trigger('click');
        expect(wrapper.find('[data-testid="missing-identity-group"]').text()).toContain('PFC Trooper5.A');
    });

    it('speaks of one trooper in the singular, with no toggle', () => {
        const wrapper = mount(MissingIdentities, { props: { groups: [{ game: 'Arma Reforger', count: 1, troopers: troopers(1) }] } });

        expect(wrapper.text()).toContain('1 of your troopers has no Arma Reforger identity linked');
        expect(wrapper.text()).toContain("The game server can't see this trooper");
        expect(wrapper.find('[data-testid="missing-identity-toggle"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="missing-identities-link"]').text()).toBe('Link it on Game identities');
    });
});
