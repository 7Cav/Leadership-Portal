import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Changelog from '../Changelog.vue';

const routerPost = vi.hoisted(() => vi.fn());
const postJson = vi.hoisted(() => vi.fn());

vi.mock('vue-sonner', () => ({ toast: { error: vi.fn() } }));
vi.mock('@/lib/postJson', () => ({ postJson }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { render: () => null },
    router: { post: routerPost, delete: vi.fn() },
}));

const ENTRIES = [
    { id: 2, date: '2026-10-20', title: 'Newer', html: '<p>Two</p>', body: 'Two' },
    { id: 1, date: '2026-10-01', title: 'Older', html: '<p>One</p>', body: 'One' },
];

afterEach(() => vi.clearAllMocks());

describe('Changelog page', () => {
    it('lists the entries in the order given, with no editor tools for a reader', () => {
        const wrapper = mount(Changelog, { props: { entries: ENTRIES.map((entry) => ({ ...entry, body: null })), isEditor: false } });

        expect(wrapper.findAll('[data-testid^="changelog-entry-"]').map((li) => li.find('h2').text())).toEqual(['Newer', 'Older']);
        expect(wrapper.find('[data-testid="changelog-body-html"]').html()).toContain('<p>Two</p>');
        expect(wrapper.find('[data-testid="changelog-add"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="changelog-import"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="changelog-edit-2"]').exists()).toBe(false);
    });

    it('gives an editor Add, Edit, Delete and Bulk import', async () => {
        const wrapper = mount(Changelog, { props: { entries: ENTRIES, isEditor: true } });

        expect(wrapper.find('[data-testid="changelog-delete-2"]').exists()).toBe(true);
        await wrapper.get('[data-testid="changelog-edit-2"]').trigger('click');
        expect(wrapper.get('[data-testid="changelog-title"]').element.value).toBe('Newer');
        expect(wrapper.get('[data-testid="changelog-save"]').text()).toBe('Save changes');
    });

    it('shows the empty state with no entries', () => {
        const wrapper = mount(Changelog, { props: { entries: [], isEditor: false } });

        expect(wrapper.find('[data-testid="changelog-empty"]').exists()).toBe(true);
    });

    it('previews an import, marks the entries it would skip, and imports the rest', async () => {
        postJson.mockResolvedValue({
            ok: true,
            data: {
                entries: [
                    { line: 1, date: '2026-10-01', title: 'Older', body: 'x', duplicate: true },
                    { line: 4, date: '2026-10-02', title: 'Fresh', body: 'y', duplicate: false },
                ],
                errors: [],
            },
        });
        const wrapper = mount(Changelog, { props: { entries: ENTRIES, isEditor: true } });

        await wrapper.get('[data-testid="changelog-import"]').trigger('click');
        await wrapper.get('[data-testid="changelog-import-markdown"]').setValue('## 2026-10-01 - Older\nx\n\n## 2026-10-02 - Fresh\ny');
        await wrapper.get('[data-testid="changelog-import-preview"]').trigger('click');
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/changelog/import/preview', { markdown: expect.stringContaining('Fresh') });
        expect(wrapper.find('[data-testid="preview-skipped-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="preview-skipped-4"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="changelog-import-save"]').text()).toBe('Import 1 entry');

        await wrapper.get('[data-testid="changelog-import-save"]').trigger('click');
        expect(routerPost).toHaveBeenCalledWith('/changelog/import', { markdown: expect.any(String), skip_duplicates: true }, expect.any(Object));
    });

    it('lists the errors of a bad paste and offers no import', async () => {
        postJson.mockResolvedValue({ ok: true, data: { entries: [], errors: [{ line: 1, message: 'Line 1: a heading must look like "## 2026-10-29 - Title".' }] } });
        const wrapper = mount(Changelog, { props: { entries: [], isEditor: true } });

        await wrapper.get('[data-testid="changelog-import"]').trigger('click');
        await wrapper.get('[data-testid="changelog-import-markdown"]').setValue('## nope');
        await wrapper.get('[data-testid="changelog-import-preview"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="changelog-import-errors"]').text()).toContain('Line 1');
        expect(wrapper.get('[data-testid="changelog-import-save"]').attributes('disabled')).toBeDefined();
    });
});
