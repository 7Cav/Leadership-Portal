import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import WeekHeading from '../WeekHeading.vue';

describe('WeekHeading', () => {
    it('reads as the long week form, with the range in smaller muted text', () => {
        const wrapper = mount(WeekHeading, { props: { week: '2026-10-24' } });

        expect(wrapper.text()).toBe('Week Ending 30OCT26 (24OCT26 to 30OCT26)');
        expect(wrapper.get('.text-muted-foreground').text()).toBe('(24OCT26 to 30OCT26)');
    });

    it('starts in lower case mid-heading', () => {
        expect(mount(WeekHeading, { props: { week: '2026-10-24', phrase: true } }).text()).toBe('week ending 30OCT26 (24OCT26 to 30OCT26)');
    });
});
