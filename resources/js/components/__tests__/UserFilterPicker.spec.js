import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import UserFilterPicker from '../UserFilterPicker.vue';

const USERS = [
    { id: 3, username: 'Vasquez.R' },
    { id: 1, username: 'pike.S' },
    { id: 2, username: 'Hale.S' },
];

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
});

async function openPicker(props = {}) {
    wrapper = mount(UserFilterPicker, { props: { users: USERS, modelValue: null, testid: 'picker', ...props }, attachTo: document.body });
    await wrapper.get('[data-testid="picker"]').trigger('click');
    await nextTick();
    return document.body.querySelector('[data-testid="user-filter-popover"]');
}

const shownNames = (popover) =>
    [...popover.querySelectorAll('[data-option]')].filter((item) => item.style.display !== 'none').map((item) => item.querySelector('[data-name]').textContent.trim());

async function search(popover, text) {
    const input = popover.querySelector('input');
    input.value = text;
    input.dispatchEvent(new Event('input'));
    await nextTick();
    await nextTick();
}

describe('UserFilterPicker', () => {
    it('reads "Any user" until someone is chosen, then their name', () => {
        wrapper = mount(UserFilterPicker, { props: { users: USERS, modelValue: null, testid: 'picker' } });
        expect(wrapper.get('[data-testid="picker"]').text()).toBe('Any user');

        wrapper = mount(UserFilterPicker, { props: { users: USERS, modelValue: 2, testid: 'picker' } });
        expect(wrapper.get('[data-testid="picker"]').text()).toBe('Hale.S');
    });

    it('lists everyone alphabetically, whatever the case, under a search box', async () => {
        const popover = await openPicker();

        expect(popover.querySelector('input').getAttribute('placeholder')).toBe('Search users');
        expect(shownNames(popover)).toEqual(['Any user', 'Hale.S', 'pike.S', 'Vasquez.R']);
    });

    it.each(['PIKE', 'Pike.S', 'pike s'])('finds a user by "%s"', async (text) => {
        const popover = await openPicker();
        await search(popover, text);

        expect(shownNames(popover)).toEqual(['pike.S']);
    });

    it('picks a user, and Any user clears the choice', async () => {
        let popover = await openPicker();
        popover.querySelector('[data-option="3"]').click();
        await nextTick();
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([3]);

        await wrapper.setProps({ modelValue: 3 });
        popover = await (async () => {
            await wrapper.get('[data-testid="picker"]').trigger('click');
            await nextTick();
            return document.body.querySelector('[data-testid="user-filter-popover"]');
        })();
        popover.querySelector('[data-option=""]').click();
        await nextTick();
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([null]);
    });
});
