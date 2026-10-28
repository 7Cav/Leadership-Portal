import { afterEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import SectionPicker from '../SectionPicker.vue';

enableAutoUnmount(afterEach);
const visit = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal()), router: { visit: (...args) => visit(...args) } }));

const progress = (extra = {}) => ({ strength: 4, onLoa: 0, unsigned: 0, unmarked: 0, started: true, posted: false, ...extra });
const SECTIONS = [
    { id: 12, designation: '2/1/A/1-7', name: '2nd Section', platoon: '1/A/1-7', progress: progress({ posted: true }) },
    { id: 13, designation: '3/1/A/1-7', name: '3rd Section', platoon: '1/A/1-7', progress: progress({ unsigned: 1, unmarked: 2 }) },
    { id: 14, designation: '1/2/A/1-7', name: '1st Section', platoon: '2/A/1-7', progress: progress({ started: false, unsigned: 4, unmarked: 4 }) },
];

const factory = (props = {}) => mount(SectionPicker, { props: { sections: SECTIONS, ...props }, attachTo: document.body });
const row = (wrapper, id) => wrapper.find(`[data-section="${id}"]`);

describe('SectionPicker', () => {
    it("shows each section's state for the week next to its name, as text", () => {
        const wrapper = factory();

        expect(row(wrapper, 12).find('[data-status]').text()).toBe('Posted');
        expect(row(wrapper, 12).find('[data-status]').attributes('data-status')).toBe('done');
        expect(row(wrapper, 13).find('[data-status]').text()).toBe('1 unsigned, 2 not marked');
        expect(row(wrapper, 14).find('[data-status]').text()).toBe('Not started');
        expect(row(wrapper, 13).text()).toContain('3/1/A/1-7');
    });

    it('still marks the current section, and draws no state without progress', () => {
        const wrapper = factory({ currentId: 13, sections: [...SECTIONS, { id: 15, designation: '2/2/A/1-7', name: '2nd Section', platoon: '2/A/1-7' }] });

        expect(row(wrapper, 13).find('[aria-label="Current section"]').exists()).toBe(true);
        expect(row(wrapper, 15).find('[data-status]').exists()).toBe(false);
    });
});
