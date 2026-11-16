import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import WarningBanner from '../WarningBanner.vue';

// 16NOV26: alerts are outlined in a bright colour by urgency, over the muted fill: red blocks the week or is late, orange breaks something until fixed,
// yellow is a heads-up.
describe('WarningBanner', () => {
    it.each([
        ['critical', 'border-alert-critical'],
        ['high', 'border-alert-high'],
        ['medium', 'border-alert-medium'],
    ])('outlines a %s alert in its colour over the muted fill', (level, fill) => {
        const banner = mount(WarningBanner, { props: { level }, slots: { default: 'Something to fix' } }).get('[role="alert"]');

        expect(banner.classes()).toContain(fill);
        expect(banner.classes()).toContain('bg-warning/10');
        expect(banner.attributes('data-level')).toBe(level);
        expect(banner.text()).toContain('Something to fix');
    });

    it('is orange when no level is given', () => {
        expect(mount(WarningBanner).get('[role="alert"]').classes()).toContain('border-alert-high');
    });
});
