import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import MissingPracticeSlots from '../MissingPracticeSlots.vue';
import PracticeSlotMissingIcon from '../PracticeSlotMissingIcon.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const sections = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 10, designation: `${i + 1}/1/B` }));

describe('MissingPracticeSlots', () => {
    it('shows nothing when every section has a slot', () => {
        expect(mount(MissingPracticeSlots, { props: { sections: [] } }).find('[data-testid="missing-slots-banner"]').exists()).toBe(false);
    });

    it('counts the sections, links the first few to their roll call and keeps the rest behind Show all', async () => {
        const wrapper = mount(MissingPracticeSlots, { props: { sections: sections(5) } });

        expect(wrapper.attributes('role')).toBe('alert');
        expect(wrapper.text()).toContain('5 of your sections have no practice slot set');
        expect(wrapper.text()).toContain("Without a slot, their practices aren't captured automatically and make-ups aren't given from the game server.");
        expect(wrapper.find('[data-testid="missing-slot-10"]').attributes('href')).toBe('/rollcall/10');
        expect(wrapper.find('[data-testid="missing-slot-13"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="missing-slots-link"]').attributes('href')).toBe('/rollcall/10');
        expect(wrapper.find('[data-testid="missing-slots-link"]').text()).toBe("Set it on the section's roll call");

        await wrapper.find('[data-testid="missing-slots-toggle"]').trigger('click');
        expect(wrapper.find('[data-testid="missing-slot-14"]').exists()).toBe(true);
    });

    it('speaks of one section in the singular, and of the unit on the roster', () => {
        expect(mount(MissingPracticeSlots, { props: { sections: sections(1) } }).text()).toContain('1 of your sections has no practice slot set');

        const roster = mount(MissingPracticeSlots, { props: { sections: sections(2), own: false } });
        expect(roster.text()).toContain('2 sections have no practice slot set');
        expect(roster.find('[data-testid="missing-slots-toggle"]').exists()).toBe(false);
    });
});

describe('PracticeSlotMissingIcon', () => {
    it('shows a labelled warning only when the slot is missing', () => {
        const icon = mount(PracticeSlotMissingIcon, { props: { missing: true } }).find('[data-testid="practice-slot-missing"]');
        expect(icon.attributes('title')).toBe('No practice slot set');
        expect(icon.attributes('aria-label')).toBe('No practice slot set');
        expect(mount(PracticeSlotMissingIcon, { props: { missing: false } }).find('[data-testid="practice-slot-missing"]').exists()).toBe(false);
    });
});
