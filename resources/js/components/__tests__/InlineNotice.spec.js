import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '../InlineNotice.vue';

const notice = (props = {}, slot = 'Message') => mount(InlineNotice, { props: { icon: PhWarning, ...props }, slots: { default: slot } });

describe('InlineNotice', () => {
    it('draws its message beside a hidden icon', () => {
        const wrapper = notice();

        expect(wrapper.text()).toBe('Message');
        expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true');
    });

    it('is muted by default and tinted for a warning', () => {
        expect(notice().classes()).toContain('bg-muted/50');
        expect(notice({ tone: 'warning' }).classes()).toContain('bg-warning/8');
    });

    it('carries a role only when given one, and passes other attributes through', () => {
        expect(notice().attributes('role')).toBeUndefined();

        const wrapper = mount(InlineNotice, { props: { icon: PhWarning, role: 'status' }, attrs: { 'data-testid': 'n' } });
        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.attributes('data-testid')).toBe('n');
    });
});
