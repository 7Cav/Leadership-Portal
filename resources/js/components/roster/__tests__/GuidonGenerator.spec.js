import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import GuidonGenerator from '../GuidonGenerator.vue';
import Guidon from '../../Guidon.vue';
import { guidonDataUrl, guidonSvg } from '@/lib/guidon';

const put = vi.fn();
const toastSuccess = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { success: (...a) => toastSuccess(...a), error: vi.fn() } }));
// The form's submit is the only thing that would reach the server: the fields stay reactive, `put` is recorded.
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        useForm: (data) => {
            const form = reactive({ ...data, errors: {}, processing: false });
            form.put = (url, options) => put(url, { ...form }, options);

            return form;
        },
    };
});

enableAutoUnmount(afterEach);

const company = { id: 5, type: 'company', name: 'Alpha Company', callsign: 'A/2-7', guidon: null };
const saved = { primary: '#000000', secondary: '#b22234', topLeft: '2/7', bottomRight: 'B' };

const factory = (unit = company) => mount(GuidonGenerator, { props: { unit } });
const preview = (wrapper) => decodeURIComponent(wrapper.find('[data-testid="guidon"]').attributes('src').split(',')[1]);

describe('GuidonGenerator', () => {
    beforeEach(() => {
        put.mockReset();
        toastSuccess.mockReset();
    });

    it('starts a unit with no guidon from its designation, in navy and white', () => {
        const wrapper = factory();

        expect(wrapper.find('[data-testid="guidon-topLeft"]').element.value).toBe('2/7');
        expect(wrapper.find('[data-testid="guidon-bottomRight"]').element.value).toBe('A');
        expect(preview(wrapper)).toBe(guidonSvg({ primary: '#1f2a44', secondary: '#ffffff', topLeft: '2/7', bottomRight: 'A' }));
    });

    it('starts from the saved guidon, and offers Save only once something changed', async () => {
        const wrapper = factory({ ...company, guidon: saved });

        expect(preview(wrapper)).toBe(guidonSvg(saved));
        expect(wrapper.find('[data-testid="guidon-save"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="guidon-bottomRight"]').setValue('C');

        expect(wrapper.find('[data-testid="guidon-save"]').attributes('disabled')).toBeUndefined();
    });

    it('updates the preview live from the palette, a custom color and the text, saving nothing', async () => {
        const wrapper = factory();

        await wrapper.find('[data-testid="guidon-primary-b22234"]').trigger('click');
        await wrapper.find('[data-testid="guidon-secondary-custom"]').setValue('#123abc');
        await wrapper.find('[data-testid="guidon-topLeft"]').setValue('1/7');

        expect(preview(wrapper)).toBe(guidonSvg({ primary: '#b22234', secondary: '#123abc', topLeft: '1/7', bottomRight: 'A' }));
        expect(wrapper.find('[data-testid="guidon-primary-b22234"]').attributes('aria-pressed')).toBe('true');
        expect(put).not.toHaveBeenCalled();
    });

    it('keeps typed text to what a guidon may carry', async () => {
        const wrapper = factory();

        await wrapper.find('[data-testid="guidon-topLeft"]').setValue('<b>"x"</b>&more');

        expect(wrapper.find('[data-testid="guidon-topLeft"]').element.value).toBe('bx/bmo');
        expect(preview(wrapper)).not.toContain('<b>');
    });

    it('warns when the two colors are too close', async () => {
        const wrapper = factory();
        expect(wrapper.find('[data-testid="guidon-too-close"]').exists()).toBe(false);

        await wrapper.find('[data-testid="guidon-secondary-000000"]').trigger('click');

        expect(wrapper.find('[data-testid="guidon-too-close"]').text()).toContain('too close to tell apart');
    });

    it('saves the design to the unit and says so', async () => {
        const wrapper = factory();
        await wrapper.find('[data-testid="guidon-secondary-ffc72c"]').trigger('click');

        await wrapper.find('form').trigger('submit');

        expect(put).toHaveBeenCalledWith('/units/5/guidon', expect.objectContaining({ primary: '#1f2a44', secondary: '#ffc72c', topLeft: '2/7', bottomRight: 'A' }), expect.anything());
        put.mock.calls[0][2].onSuccess();
        expect(toastSuccess).toHaveBeenCalledWith('Guidon saved for A/2-7');
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('downloads the SVG on screen, named after the unit', async () => {
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        const wrapper = factory();

        await wrapper.find('[data-testid="guidon-download-svg"]').trigger('click');

        const link = click.mock.contexts[0];
        expect(link.download).toBe('guidon-A-2-7.svg');
        expect(link.href).toBe(guidonDataUrl({ primary: '#1f2a44', secondary: '#ffffff', topLeft: '2/7', bottomRight: 'A' }));
        click.mockRestore();
    });
});

describe('Guidon', () => {
    it('shows the flag as an image, and nothing for a unit with no guidon or a bad design', () => {
        expect(mount(Guidon, { props: { design: saved } }).find('img').attributes('src')).toBe(guidonDataUrl(saved));
        expect(mount(Guidon, { props: { design: null } }).find('img').exists()).toBe(false);
        expect(mount(Guidon, { props: { design: { ...saved, primary: 'javascript:x' } } }).find('img').exists()).toBe(false);
    });
});
