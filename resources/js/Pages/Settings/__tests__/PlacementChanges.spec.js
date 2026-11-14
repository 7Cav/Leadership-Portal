import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn() },
}));

import { router } from '@inertiajs/vue3';
import PlacementChanges from '../PlacementChanges.vue';

const BACK = { at: '2026-11-14T19:00:00Z', trooperId: 756, trooper: 'PFC Gentry.C', from: '4/2/E/ACD', to: '1/2/C/ACD', source: 'sync', reversedAt: null, reverses: true };
const OUT = { ...BACK, at: '2026-11-14T18:00:00Z', from: '1/2/C/ACD', to: '4/2/E/ACD', reversedAt: '2026-11-14T19:00:00Z', reverses: false };
const ADMIN = { at: '2026-11-10T12:00:00Z', trooperId: 9, trooper: 'SPC Adams.P', from: null, to: '1/1/A/1-7', source: 'manual', reversedAt: null, reverses: false };

const factory = (changes = [BACK, OUT, ADMIN]) => mount(PlacementChanges, { props: { changes, days: 14, spans: [14, 7, 30] } });

describe('Settings/PlacementChanges', () => {
    it('lists each move with who made it, marking a trooper moved and put straight back', () => {
        const rows = factory().findAll('[data-testid="placement-change"]');

        expect(rows).toHaveLength(3);
        expect(rows[1].text()).toContain('PFC Gentry.C');
        expect(rows[1].text()).toContain('1/2/C/ACD to 4/2/E/ACD');
        expect(rows[1].get('[data-testid="change-note"]').text()).toBe('Moved back 14NOV26. Likely a wrong milpac edit');
        expect(rows[0].get('[data-testid="change-note"]').text()).toBe('Undoes the move before. Likely a milpac correction');
        expect(rows[0].text()).toContain('Roster sync');
        expect(rows[2].text()).toContain('Placed in 1/1/A/1-7');
        expect(rows[2].text()).toContain('Admin');
        expect(rows[2].find('[data-testid="change-note"]').exists()).toBe(false);
        expect(rows[0].get('a').attributes('href')).toBe('/troopers/756');
    });

    it('offers 7, 14 and 30 days, the one shown pressed', async () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="span-14"]').attributes('aria-pressed')).toBe('true');
        await wrapper.get('[data-testid="span-30"]').trigger('click');

        expect(router.get).toHaveBeenCalledWith('/settings/placement-changes', { days: 30 }, expect.anything());
    });

    it('says when nothing moved in the span', () => {
        expect(factory([]).text()).toContain('No placement changes in the last 14 days');
    });
});
