// Vitest helpers for a page's SearchSelect fields, found by their data-testid. They read and set the field the way
// the popover would, without opening it (the popover's own behaviour has its own spec).
import { nextTick } from 'vue';
import SearchSelect from '@/components/SearchSelect.vue';

export function searchSelect(wrapper, testid) {
    const found = wrapper.findAllComponents(SearchSelect).find((field) => field.props('testid') === testid);
    if (!found) throw new Error(`No SearchSelect with testid "${testid}"`);
    return found;
}

export function hasSearchSelect(wrapper, testid) {
    return wrapper.findAllComponents(SearchSelect).some((field) => field.props('testid') === testid);
}

/** The rows the list offers, the any row first when there is one: `[{ value, label, disabled }]`. */
export function searchOptions(wrapper, testid) {
    const field = searchSelect(wrapper, testid);
    const rows = field.props('options').map((o) => ({ value: o.value, label: o.label, disabled: !!o.disabled }));
    return field.props('anyLabel') === null ? rows : [{ value: field.props('anyValue'), label: field.props('anyLabel'), disabled: false }, ...rows];
}

export const searchLabels = (wrapper, testid) => searchOptions(wrapper, testid).map((o) => o.label);

/** The value the field holds, as the button shows it in data-value (text). */
export const searchValue = (wrapper, testid) => searchSelect(wrapper, testid).get('button').attributes('data-value');

/** Picks a value as a click on its row would. */
export async function chooseSearch(wrapper, testid, value) {
    const field = searchSelect(wrapper, testid);
    const option = field.props('options').find((o) => String(o.value) === String(value));
    const picked = option ? option.value : value;
    field.vm.$emit('update:modelValue', picked);
    field.vm.$emit('change', picked);
    await nextTick();
    await nextTick();
}
