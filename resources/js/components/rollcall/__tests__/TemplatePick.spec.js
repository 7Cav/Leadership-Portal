import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import TemplatePick from '../TemplatePick.vue';

enableAutoUnmount(afterEach);

const OPTIONS = [
    { id: 5, name: 'Regiment default', isRegimentDefault: true, isCarried: false, version: 1 },
    { id: 7, name: 'Bravo standard', isRegimentDefault: false, isCarried: false, version: 3 },
    { id: 9, name: 'Training', isRegimentDefault: false, isCarried: true, version: 2 },
];
const factory = (picker = {}, props = {}) =>
    mount(TemplatePick, { props: { picker: { options: OPTIONS, picked: null, moved: null, ...picker }, template: { name: 'Bravo standard', source: 'company' }, ...props } });

describe('TemplatePick', () => {
    it('marks the regiment default and the templates carried from another company', () => {
        const labels = factory().findAll('option').map((option) => option.text());

        expect(labels).toEqual(['Company default', 'Regiment default (regiment)', 'Bravo standard', 'Training (carried)']);
    });

    it('emits the pick, or null for the company default, and not a pick that changes nothing', async () => {
        const wrapper = factory({ picked: 9 });
        const select = wrapper.get('[data-testid="template-pick-select"]');

        select.element.value = '7';
        await select.trigger('change');
        select.element.value = '';
        await select.trigger('change');
        select.element.value = '9';
        await select.trigger('change');

        expect(wrapper.emitted('pick')).toEqual([[7], [null]]);
    });

    it('says where carried templates came from when they may be used', () => {
        const text = factory({ moved: { from: ['A/1-7'], usable: true, using: 'Training' } }).get('[data-testid="template-moved"]').text();

        expect(text).toContain('brought its templates from A/1-7');
    });

    it('tells a moved platoon which template it now uses when its new company does not use them', () => {
        const wrapper = factory({ moved: { from: ['A/1-7'], usable: false, using: 'Bravo standard' } });
        const notice = wrapper.get('[data-testid="template-moved"]');

        expect(notice.attributes('role')).toBe('status');
        expect(notice.text()).toContain('moved here from A/1-7');
        expect(notice.text()).toContain('The post now uses Bravo standard.');
    });

    it('says nothing about a move when the platoon never moved', () => {
        expect(factory().find('[data-testid="template-moved"]').exists()).toBe(false);
    });

    it('is disabled with the reason when the viewer may not pick', () => {
        const select = factory({}, { disabledReason: 'Read-only while viewing as 1/1/A/1-7' }).get('[data-testid="template-pick-select"]');

        expect(select.attributes('disabled')).toBeDefined();
        expect(select.attributes('title')).toBe('Read-only while viewing as 1/1/A/1-7');
    });
});
