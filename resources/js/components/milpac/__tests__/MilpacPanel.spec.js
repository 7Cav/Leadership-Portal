import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MilpacPanel from '../MilpacPanel.vue';

describe('MilpacPanel', () => {
    it('draws its title as an h2 above the body', () => {
        const wrapper = mount(MilpacPanel, { props: { title: 'Awards' }, slots: { default: '<p data-testid="body">Body</p>' } });

        expect(wrapper.get('h2').text()).toBe('Awards');
        expect(wrapper.get('[data-testid="body"]').text()).toBe('Body');
    });

    it('shows the count slot in the header only when given one', () => {
        expect(mount(MilpacPanel, { props: { title: 'Awards' } }).find('[data-slot="card-action"]').exists()).toBe(false);

        const wrapper = mount(MilpacPanel, { props: { title: 'Awards' }, slots: { count: '<span data-testid="n">3</span>' } });
        expect(wrapper.get('[data-slot="card-action"] [data-testid="n"]').text()).toBe('3');
    });
});
