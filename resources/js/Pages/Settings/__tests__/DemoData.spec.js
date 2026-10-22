import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, reactive } from 'vue';
import { TooltipProvider } from 'reka-ui';
import DemoData from '../DemoData.vue';

const mockPage = reactive({ props: { viewAs: null } });
const mockForm = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));
const mockToast = vi.hoisted(() => ({ success: vi.fn(), info: vi.fn(), error: vi.fn() }));

vi.mock('vue-sonner', () => ({ toast: mockToast }));
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive: makeReactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        usePage: () => mockPage,
        useForm: (data) => {
            const form = makeReactive({ ...data, processing: false, errors: {} });
            form.post = (...args) => mockForm.post({ ...form }, ...args);
            form.delete = (...args) => mockForm.delete({ ...form }, ...args);
            form.transform = (callback) => {
                const transformed = { post: (...args) => mockForm.post(callback({ ...form }), ...args), delete: (...args) => mockForm.delete(callback({ ...form }), ...args) };

                return transformed;
            };

            return form;
        },
    };
});

const UNITS = [
    { id: 1, type: 'battalion', name: '1st Battalion', callsign: '1-7', troopers: 12 },
    { id: 2, type: 'company', name: 'Alpha Company', callsign: 'A/1-7', troopers: 12 },
    { id: 3, type: 'section', name: '1st Squad', callsign: '1/1/A/1-7', troopers: 12 },
    { id: 4, type: 'section', name: '2nd Squad', callsign: '2/1/A/1-7', troopers: 0 },
];
const RECENT = Array.from({ length: 12 }, (_, i) => `2026-09-${String(i + 1).padStart(2, '0')}`);
const NONE = { practices: 0, attendance: 0, reports: 0, weeks: 0 };

function render(props = {}) {
    const Host = defineComponent({
        setup: () => () =>
            h(TooltipProvider, null, () => h(DemoData, { units: UNITS, existing: NONE, weeks: { default: 8, max: 12 }, recentWeeks: RECENT, result: null, ...props })),
    });

    return mount(Host, { attachTo: document.body });
}

const inBody = (selector) => document.body.querySelector(selector);

async function pick(wrapper, id) {
    await wrapper.find(`[data-testid="unit-option-${id}"]`).trigger('click');
    await flushPromises();
}

beforeEach(() => {
    mockPage.props.viewAs = null;
    vi.clearAllMocks();
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Settings/DemoData', () => {
    it('lists the units by echelon with their placed troopers', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Battalions');
        expect(wrapper.text()).toContain('Sections');
        expect(wrapper.find('[data-testid="unit-option-2"]').text()).toContain('A/1-7');
        expect(wrapper.find('[data-testid="unit-option-2"]').text()).toContain('12 troopers');
    });

    it('keeps Generate and Purge disabled until a unit is picked', async () => {
        const wrapper = render();

        expect(wrapper.find('[data-testid="generate"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="purge"]').attributes('disabled')).toBeDefined();

        await pick(wrapper, 2);

        expect(wrapper.find('[data-testid="selected-unit"]').text()).toContain('A/1-7');
        expect(wrapper.find('[data-testid="generate"]').attributes('disabled')).toBeUndefined();
    });

    it('names the completed weeks a generation covers', async () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Completed weeks 2026-09-05 to 2026-09-12');

        await wrapper.find('[data-testid="weeks"]').setValue(13);
        expect(wrapper.text()).toContain('Choose 1 to 12 completed weeks.');
    });

    it('confirms, then posts the unit and weeks', async () => {
        const wrapper = render();
        await pick(wrapper, 2);

        await wrapper.find('[data-testid="generate"]').trigger('click');
        await flushPromises();

        expect(inBody('[data-testid="confirm-dialog"]').textContent).toContain('Generate demo data for A/1-7?');
        expect(inBody('[data-testid="confirm-dialog"]').textContent).toContain('2026-09-05 to 2026-09-12');

        inBody('[data-testid="confirm"]').click();

        expect(mockForm.post).toHaveBeenCalledWith(expect.objectContaining({ unit_id: 2, weeks: 8 }), '/settings/demo-data', expect.objectContaining({ preserveScroll: true }));
    });

    it('closes the dialog and toasts an error when the server refuses or cannot be reached', async () => {
        const wrapper = render();
        await pick(wrapper, 2);
        await wrapper.find('[data-testid="generate"]').trigger('click');
        await flushPromises();
        inBody('[data-testid="confirm"]').click();

        const options = mockForm.post.mock.calls[0][2];
        expect(options.onHttpException({ status: 500 })).toBe(false);
        expect(mockToast.error).toHaveBeenCalledWith(expect.stringContaining('failed (HTTP 500)'));
        expect(options.onNetworkError(new Error('offline'))).toBe(false);
        expect(mockToast.error).toHaveBeenLastCalledWith('The portal could not be reached. Check your connection and try again.');

        await flushPromises();
        expect(inBody('[data-testid="confirm-dialog"]')).toBeNull();
    });

    it('posts a changed week count', async () => {
        const wrapper = render();
        await pick(wrapper, 2);
        await wrapper.find('[data-testid="weeks"]').setValue(3);
        await wrapper.find('[data-testid="generate"]').trigger('click');
        await flushPromises();
        inBody('[data-testid="confirm"]').click();

        expect(mockForm.post).toHaveBeenCalledWith(expect.objectContaining({ unit_id: 2, weeks: 3 }), '/settings/demo-data', expect.any(Object));
    });

    it('toasts the result the run redirects back with', async () => {
        const wrapper = render();
        await pick(wrapper, 2);
        await wrapper.find('[data-testid="generate"]').trigger('click');
        await flushPromises();
        inBody('[data-testid="confirm"]').click();

        mockForm.post.mock.calls[0][2].onSuccess({ props: { result: { action: 'generated', empty: false, summary: 'Generated 8 weeks of demo history for A/1-7.' } } });

        expect(mockToast.success).toHaveBeenCalledWith('Generated 8 weeks of demo history for A/1-7.');
    });

    // Going back to the page restores its props, result included; that is not a new run.
    it('does not toast a result the page is merely shown with', () => {
        render({ result: { action: 'purged', empty: false, summary: 'Purged demo history for A/1-7.' } });

        expect(mockToast.success).not.toHaveBeenCalled();
        expect(mockToast.info).not.toHaveBeenCalled();
    });

    it('confirms a purge, then deletes with only the unit', async () => {
        const wrapper = render();
        await pick(wrapper, 3);

        await wrapper.find('[data-testid="purge"]').trigger('click');
        await flushPromises();
        expect(inBody('[data-testid="confirm-dialog"]').textContent).toContain('Purge demo data for 1/1/A/1-7?');

        inBody('[data-testid="confirm"]').click();

        expect(mockForm.delete).toHaveBeenCalledWith({ unit_id: 3 }, '/settings/demo-data', expect.any(Object));
    });

    it('warns when the picked unit has nobody placed', async () => {
        const wrapper = render();
        await pick(wrapper, 4);

        expect(wrapper.find('[data-testid="no-troopers-hint"]').exists()).toBe(true);
    });

    it('is read-only while viewing as someone else', async () => {
        mockPage.props.viewAs = { label: 'A/1-7', admin: false };
        const wrapper = render();
        await pick(wrapper, 2);

        expect(wrapper.find('[data-testid="generate"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="purge"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="read-only"]').text()).toBe('Read-only while viewing as A/1-7.');
    });

    it('shows the demo rows held, or an empty state', () => {
        expect(render().text()).toContain('No demo data yet.');

        document.body.innerHTML = '';
        const wrapper = render({ existing: { practices: 9, attendance: 42, reports: 18, weeks: 3 } });
        expect(wrapper.find('[data-testid="existing-attendance"]').text()).toBe('42');
        expect(wrapper.find('[data-testid="existing-weeks"]').text()).toBe('3');
    });

    it('shows the last result, including skipped weeks', () => {
        const result = {
            action: 'generated',
            empty: false,
            summary: 'Generated 2 weeks of demo history for A/1-7.',
            generated_weeks: ['2026-09-05', '2026-09-12'],
            skipped_weeks: ['2026-08-29'],
        };
        const wrapper = render({ result });

        expect(wrapper.find('[data-testid="result"]').text()).toContain('2026-09-12');
        expect(wrapper.find('[data-testid="skipped"]').text()).toContain('2026-08-29');
    });

    it('toasts an empty outcome as information, not success', async () => {
        const wrapper = render();
        await pick(wrapper, 2);
        await wrapper.find('[data-testid="purge"]').trigger('click');
        await flushPromises();
        inBody('[data-testid="confirm"]').click();

        mockForm.delete.mock.calls[0][2].onSuccess({ props: { result: { action: 'purged', empty: true, summary: 'There is no demo history for A/1-7 to purge.' } } });

        expect(mockToast.info).toHaveBeenCalledWith('There is no demo history for A/1-7 to purge.');
        expect(mockToast.success).not.toHaveBeenCalled();
    });

    it('points to the org import when there are no units', () => {
        const wrapper = render({ units: [] });

        expect(wrapper.find('[data-testid="no-units"]').text()).toContain('Org structure');
        expect(wrapper.find('[data-testid="generate"]').exists()).toBe(false);
    });
});
