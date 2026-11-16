import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ZuluTimeInput from '../ZuluTimeInput.vue';

const factory = (modelValue = '23:00') =>
    mount(ZuluTimeInput, { props: { modelValue, 'onUpdate:modelValue': (value) => wrapperRef.wrapper.setProps({ modelValue: value }) }, attrs: { 'data-testid': 'time' } });
const wrapperRef = {};

describe('ZuluTimeInput', () => {
    it('shows the time as 24-hour digits with no AM or PM, and a Z beside it', () => {
        wrapperRef.wrapper = factory('23:00');
        const input = wrapperRef.wrapper.get('[data-testid="time"]');

        expect(input.element.value).toBe('2300');
        expect(input.attributes('type')).toBe('text');
        expect(wrapperRef.wrapper.text()).toContain('Z');
    });

    it.each([
        ['2030', '20:30'],
        ['20:30', '20:30'],
        ['0700', '07:00'],
        ['9', '09:00'],
        ['2359z', '23:59'],
    ])('reads %s as %s', async (typed, value) => {
        wrapperRef.wrapper = factory('');
        await wrapperRef.wrapper.get('[data-testid="time"]').setValue(typed);

        expect(wrapperRef.wrapper.props('modelValue')).toBe(value);
    });

    it('passes anything else on as typed, so the form refuses it', async () => {
        wrapperRef.wrapper = factory('');
        await wrapperRef.wrapper.get('[data-testid="time"]').setValue('noon');

        expect(wrapperRef.wrapper.props('modelValue')).toBe('noon');
    });
});
