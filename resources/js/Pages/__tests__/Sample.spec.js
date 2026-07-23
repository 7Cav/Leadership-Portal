import { describe, it, expect } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import Sample from '../Sample.vue';

// Vitest smoke test (Story 1.3): proves the Vue unit-test seam works end-to-end —
// a component mounts with props and renders them. shallowMount stubs child
// components (Inertia's <Head>, which needs the Inertia app plugin) while keeping
// the page's own native markup, so we assert on real rendered output.
describe('Sample page', () => {
    const factory = () =>
        shallowMount(Sample, {
            props: {
                appName: '7Cav Portal',
                stack: ['Laravel 13', 'Vue 3.5'],
            },
        });

    it('renders the app name passed as a prop', () => {
        expect(factory().text()).toContain('7Cav Portal');
    });

    it('renders each stack item', () => {
        const items = factory().findAll('li');
        expect(items).toHaveLength(2);
        expect(items.map((i) => i.text())).toEqual(['Laravel 13', 'Vue 3.5']);
    });
});
