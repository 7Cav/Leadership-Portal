import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import NoValue from '../NoValue.vue';

describe('NoValue', () => {
    it('shows a dash to the eye and says "Not available" to a screen reader', () => {
        const wrapper = mount(NoValue);

        expect(wrapper.find('[aria-hidden="true"]').text()).toBe('—');
        expect(wrapper.find('.sr-only').text()).toBe('Not available');
    });

    it('takes the words a screen reader hears', () => {
        expect(mount(NoValue, { props: { label: 'Unavailable' } }).find('.sr-only').text()).toBe('Unavailable');
    });
});
