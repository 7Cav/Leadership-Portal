import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import SearchSelect from '../SearchSelect.vue';

const OPTIONS = [
    { value: 1, label: '1st Platoon' },
    { value: 2, label: 'Pike.S', keywords: ['sniper'] },
    { value: 3, label: 'Retired game', disabled: true },
    { value: 4, label: 'Second', group: 'Others' },
];

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
});

const flush = async () => {
    for (let i = 0; i < 4; i++) await nextTick();
};

async function openSelect(props = {}) {
    wrapper = mount(
        {
            components: { SearchSelect },
            props: ['extra'],
            template: '<div><SearchSelect v-bind="extra" /><input data-testid="after" /></div>',
        },
        { props: { extra: { options: OPTIONS, modelValue: null, testid: 'pick', contentTestid: 'pop', ...props } }, attachTo: document.body },
    );
    await wrapper.get('[data-testid="pick"]').trigger('click');
    await flush();
    return document.body.querySelector('[data-testid="pop"]');
}

const shown = (pop) => [...pop.querySelectorAll('[data-option]')].map((row) => row.querySelector('[data-name]').textContent.trim());
const picked = () => wrapper.findComponent(SearchSelect).emitted('update:modelValue')?.at(-1);

async function type(pop, text) {
    const input = pop.querySelector('input');
    input.focus();
    input.value = text;
    input.dispatchEvent(new Event('input'));
    await flush();
    return input;
}

async function press(input, key, init = {}) {
    input.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init }));
    await flush();
}

describe('SearchSelect', () => {
    it('shows the placeholder, the any label, or the chosen label', () => {
        wrapper = mount(SearchSelect, { props: { options: OPTIONS, modelValue: null, testid: 'pick' } });
        expect(wrapper.get('[data-testid="pick"]').text()).toBe('Choose one');

        wrapper = mount(SearchSelect, { props: { options: OPTIONS, modelValue: null, anyLabel: 'Any unit', testid: 'pick' } });
        expect(wrapper.get('[data-testid="pick"]').text()).toBe('Any unit');

        // A value as text still finds its option.
        wrapper = mount(SearchSelect, { props: { options: OPTIONS, modelValue: '2', testid: 'pick' } });
        expect(wrapper.get('[data-testid="pick"]').text()).toBe('Pike.S');
    });

    it('lists the any row first, then the options in order, under their groups', async () => {
        const pop = await openSelect({ anyLabel: 'None' });

        expect(shown(pop)).toEqual(['None', '1st Platoon', 'Pike.S', 'Retired game', 'Second']);
        expect(pop.textContent).toContain('Others');
    });

    it.each([
        ['pike s', ['Pike.S']],
        ['SNIPER', ['Pike.S']],
        ['plat', ['1st Platoon']],
    ])('filters by "%s"', async (text, names) => {
        const pop = await openSelect();
        await type(pop, text);

        expect(shown(pop)).toEqual(names);
    });

    it('says so when nothing matches', async () => {
        const pop = await openSelect({ emptyText: 'No unit matches.' });
        await type(pop, 'zzz');

        expect(shown(pop)).toEqual([]);
        expect(pop.textContent).toContain('No unit matches.');
    });

    it('picks an option and emits change, and the any row sets the any value', async () => {
        let pop = await openSelect({ anyLabel: 'None', anyValue: '' });
        pop.querySelector('[data-option="4"]').click();
        await flush();
        expect(picked()).toEqual([4]);
        expect(wrapper.findComponent(SearchSelect).emitted('change')).toEqual([[4]]);

        await wrapper.get('[data-testid="pick"]').trigger('click');
        await flush();
        pop = document.body.querySelector('[data-testid="pop"]');
        pop.querySelector('[data-any]').click();
        await flush();
        expect(picked()).toEqual(['']);
    });

    it('Tab in the search box picks the top match and moves focus on', async () => {
        const pop = await openSelect();
        const input = await type(pop, 'pike');
        await press(input, 'Tab');

        expect(picked()).toEqual([2]);
        expect(document.body.querySelector('[data-testid="pop"]')).toBeNull();
        expect(document.activeElement).toBe(wrapper.get('[data-testid="after"]').element);
    });

    it('Tab with no match picks nothing and still moves focus on', async () => {
        const pop = await openSelect();
        const input = await type(pop, 'zzz');
        await press(input, 'Tab');

        expect(picked()).toBeUndefined();
        expect(document.activeElement).toBe(wrapper.get('[data-testid="after"]').element);
    });

    it('Shift+Tab picks nothing', async () => {
        const pop = await openSelect();
        const input = await type(pop, 'pike');
        await press(input, 'Tab', { shiftKey: true });

        expect(picked()).toBeUndefined();
    });

    it('carries the value in a hidden input when named', () => {
        wrapper = mount(SearchSelect, { props: { options: OPTIONS, modelValue: 2, name: 'unit_id' } });
        expect(wrapper.find('input[type="hidden"][name="unit_id"]').element.value).toBe('2');
    });

    it('is disabled when asked', () => {
        wrapper = mount(SearchSelect, { props: { options: OPTIONS, modelValue: null, disabled: true, testid: 'pick' } });
        expect(wrapper.get('[data-testid="pick"]').attributes('disabled')).toBeDefined();
    });
});
