import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Changelog from '../Changelog.vue';

const routerPost = vi.hoisted(() => vi.fn());
const postJson = vi.hoisted(() => vi.fn());
const formPost = vi.hoisted(() => vi.fn());

vi.mock('vue-sonner', () => ({ toast: { error: vi.fn() } }));
vi.mock('@/lib/postJson', () => ({ postJson }));
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const original = await importOriginal();
    const { reactive } = await import('vue');

    // A small stand-in for useForm: the fields, errors, and a post that records what `transform` made of them.
    const useForm = (initial) => {
        let transform = (data) => data;
        const form = reactive({
            ...structuredClone(initial),
            errors: {},
            processing: false,
            clearErrors: () => (form.errors = {}),
            setError: (key, message) => (form.errors = { ...form.errors, [key]: message }),
            transform: (fn) => {
                transform = fn;
                return form;
            },
            post: (url, options) => formPost(url, transform(form), options),
            patch: (url, options) => formPost(url, transform(form), options),
        });
        return form;
    };

    return { ...original, Head: { render: () => null }, useForm, router: { post: routerPost, delete: vi.fn() } };
});

const DAYS = [
    {
        date: '2026-10-30',
        entries: [
            { id: 3, body: 'Newest change', detailsHtml: '<p>More <strong>here</strong></p>', details: 'More **here**' },
            { id: 4, body: 'Second on the day', detailsHtml: null, details: null },
        ],
    },
    { date: '2026-10-01', entries: [{ id: 1, body: 'Older change', detailsHtml: null, details: null }] },
];

let wrapper;
const mountPage = (props) => (wrapper = mount(Changelog, { props: { days: DAYS, isEditor: false, ...props }, attachTo: document.body }));
const body = (selector) => document.body.querySelector(selector);

afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('Changelog page', () => {
    it('lists dates newest first as forum dates, each with its entries as rows, and no editor tools for a reader', () => {
        mountPage();

        expect(wrapper.findAll('[data-testid^="changelog-day-"] h2').map((h) => h.text())).toEqual(['30Oct26', '01Oct26']);
        expect(wrapper.get('[data-testid="changelog-day-2026-10-30"]').findAll('[data-testid="changelog-entry-body"]').map((e) => e.text())).toEqual([
            'Newest change',
            'Second on the day',
        ]);
        expect(wrapper.find('[data-testid="changelog-add"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="changelog-edit-3"]').exists()).toBe(false);
    });

    it('starts additional information collapsed and opens it with the chevron', async () => {
        mountPage();

        expect(wrapper.find('[data-testid="changelog-toggle-4"]').exists()).toBe(false);
        const toggle = wrapper.get('[data-testid="changelog-toggle-3"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="changelog-details-3"]').exists()).toBe(false);

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('[data-testid="changelog-details-3"]').html()).toContain('<strong>here</strong>');
    });

    it('shows the empty state with no entries', () => {
        mountPage({ days: [] });

        expect(wrapper.find('[data-testid="changelog-empty"]').exists()).toBe(true);
    });

    it('adds a date batch: Enter adds a row below, Add N appends rows, blank rows are left out', async () => {
        mountPage({ isEditor: true });
        await wrapper.get('[data-testid="changelog-add"]').trigger('click');
        await flushPromises();

        const first = body('[data-testid="changelog-row-body-0"]');
        first.value = 'First';
        first.dispatchEvent(new Event('input'));
        first.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        await flushPromises();
        expect(document.body.querySelectorAll('[data-testid^="changelog-row-body-"]')).toHaveLength(2);

        body('[data-testid="changelog-add-rows"]').click();
        await flushPromises();
        expect(document.body.querySelectorAll('[data-testid^="changelog-row-body-"]')).toHaveLength(5);

        body('[data-testid="changelog-row-remove-4"]').click();
        await flushPromises();
        expect(document.body.querySelectorAll('[data-testid^="changelog-row-body-"]')).toHaveLength(4);

        const third = body('[data-testid="changelog-row-body-2"]');
        third.value = 'Third';
        third.dispatchEvent(new Event('input'));
        body('[data-testid="changelog-row-has-details-2"]').click();
        await flushPromises();
        const details = body('[data-testid="changelog-row-details-2"]');
        details.value = 'Some **detail**';
        details.dispatchEvent(new Event('input'));
        await flushPromises();

        expect(body('[data-testid="changelog-add-save"]').textContent.trim()).toBe('Add 2 entries');
        body('[data-testid="changelog-add-save"]').click();
        await flushPromises();

        expect(formPost).toHaveBeenCalledWith(
            '/changelog',
            {
                date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
                entries: [
                    { body: 'First', details: null },
                    { body: 'Third', details: 'Some **detail**' },
                ],
            },
            expect.any(Object),
        );
    });

    it('edits one entry with its details', async () => {
        mountPage({ isEditor: true });
        await wrapper.get('[data-testid="changelog-edit-3"]').trigger('click');
        await flushPromises();

        expect(body('[data-testid="changelog-edit-body"]').value).toBe('Newest change');
        expect(body('[data-testid="changelog-edit-details"]').value).toBe('More **here**');

        body('[data-testid="changelog-edit-save"]').click();
        await flushPromises();
        expect(formPost).toHaveBeenCalledWith('/changelog/3', { date: '2026-10-30', body: 'Newest change', details: 'More **here**' }, expect.any(Object));
    });

    it('previews an import grouped by date, marks the entries it would skip, and imports the rest', async () => {
        postJson.mockResolvedValue({
            ok: true,
            data: {
                entries: [
                    { line: 2, date: '2026-10-01', body: 'Older change', details: null, duplicate: true },
                    { line: 3, date: '2026-10-01', body: 'Fresh', details: 'More', duplicate: false },
                ],
                errors: [],
            },
        });
        mountPage({ isEditor: true });

        await wrapper.get('[data-testid="changelog-import"]').trigger('click');
        await wrapper.get('[data-testid="changelog-import-markdown"]').setValue('## 2026-10-01\n- Older change\n- Fresh\n  More');
        await wrapper.get('[data-testid="changelog-import-preview"]').trigger('click');
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/changelog/import/preview', { markdown: expect.stringContaining('Fresh') });
        expect(wrapper.get('[data-testid="changelog-import-entries"] h3').text()).toBe('01Oct26');
        expect(wrapper.find('[data-testid="preview-skipped-2"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="preview-details-3"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="changelog-import-save"]').text()).toBe('Import 1 entry');

        await wrapper.get('[data-testid="changelog-import-save"]').trigger('click');
        expect(routerPost).toHaveBeenCalledWith('/changelog/import', { markdown: expect.any(String), skip_duplicates: true }, expect.any(Object));
    });

    it('lists the errors of a bad paste and offers no import', async () => {
        postJson.mockResolvedValue({ ok: true, data: { entries: [], errors: [{ line: 1, message: 'Line 1: a heading must be a date like "## 2026-10-29".' }] } });
        mountPage({ days: [], isEditor: true });

        await wrapper.get('[data-testid="changelog-import"]').trigger('click');
        await wrapper.get('[data-testid="changelog-import-markdown"]').setValue('## nope');
        await wrapper.get('[data-testid="changelog-import-preview"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="changelog-import-errors"]').text()).toContain('Line 1');
        expect(wrapper.get('[data-testid="changelog-import-save"]').attributes('disabled')).toBeDefined();
    });
});
