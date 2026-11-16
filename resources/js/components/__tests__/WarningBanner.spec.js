import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import WarningBanner from '../WarningBanner.vue';

// 16NOV26: alerts are solid and bright by urgency: red blocks the week or is late, orange breaks something until fixed,
// yellow is a heads-up.
describe('WarningBanner', () => {
    it.each([
        ['critical', 'bg-alert-critical'],
        ['high', 'bg-alert-high'],
        ['medium', 'bg-alert-medium'],
    ])('draws a %s alert solid in its colour', (level, fill) => {
        const banner = mount(WarningBanner, { props: { level }, slots: { default: 'Something to fix' } }).get('[role="alert"]');

        expect(banner.classes()).toContain(fill);
        expect(banner.attributes('data-level')).toBe(level);
        expect(banner.text()).toContain('Something to fix');
    });

    it('is orange when no level is given', () => {
        expect(mount(WarningBanner).get('[role="alert"]').classes()).toContain('bg-alert-high');
    });
});
