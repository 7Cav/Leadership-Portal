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
            { id: 3, body: 'Newest change', major: true, detailsHtml: '<p>More <strong>here</strong></p>', details: 'More **here**' },
            { id: 4, body: 'Second on the day', major: false, detailsHtml: null, details: null },
        ],
    },
    { date: '2026-10-01', entries: [{ id: 1, body: 'Older change', major: false, detailsHtml: null, details: null }] },
];

let wrapper;
const mountPage = (props) => (wrapper = mount(Changelog, { props: { days: DAYS, isEditor: false, ...props }, attachTo: document.body }));
const body = (selector) => document.body.querySelector(selector);
const openFull = () => wrapper.get('[data-testid="changelog-full-toggle"]').trigger('click');

afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
    window.localStorage.clear();
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

describe('Changelog page', () => {
    it('shows major changes and keeps the full changelog collapsed until opened, then remembers it', async () => {
        mountPage();

        expect(wrapper.get('[data-testid="changelog-major-days"]').findAll('[data-testid="changelog-entry-body"]').map((e) => e.text())).toEqual(['Newest change']);
        const toggle = wrapper.get('[data-testid="changelog-full-toggle"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(toggle.text()).toContain('3 entries');
        expect(wrapper.find('[data-testid="changelog-days"]').exists()).toBe(false);

        await toggle.trigger('click');
        expect(wrapper.get('[data-testid="changelog-days"]').findAll('[data-testid="changelog-entry-body"]')).toHaveLength(3);
        expect(wrapper.find('[data-testid="changelog-major-3"]').exists()).toBe(true);
        expect(window.localStorage.getItem('changelog.fullOpen')).toBe('1');

        wrapper.unmount();
        mountPage();
        expect(wrapper.get('[data-testid="changelog-full-toggle"]').attributes('aria-expanded')).toBe('true');
    });

    it('renders collapsed when localStorage throws', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        mountPage();

        expect(wrapper.get('[data-testid="changelog-full-toggle"]').attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="changelog-major-days"]').exists()).toBe(true);
    });

    it('says so when there are no major changes', () => {
        mountPage({ days: [DAYS[1]] });

        expect(wrapper.find('[data-testid="changelog-major-empty"]').exists()).toBe(true);
    });

    it('lists dates newest first as forum dates, each with its entries as rows, and no editor tools for a reader', async () => {
        mountPage();
        await openFull();

        expect(wrapper.findAll('[data-testid^="changelog-day-"] h3').map((h) => h.text())).toEqual(['30OCT26', '01OCT26']);
        expect(wrapper.get('[data-testid="changelog-day-2026-10-30"]').findAll('[data-testid="changelog-entry-body"]').map((e) => e.text())).toEqual([
            'Newest change',
            'Second on the day',
        ]);
        expect(wrapper.find('[data-testid="changelog-add"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="changelog-edit-3"]').exists()).toBe(false);
    });

    it('starts additional information collapsed and opens it with the chevron', async () => {
        mountPage();
        await openFull();

        expect(wrapper.find('[data-testid="changelog-toggle-4"]').exists()).toBe(false);
        const toggle = wrapper.get('[data-testid="changelog-toggle-3"]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-testid="changelog-details-3"]').exists()).toBe(false);

        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('[data-testid="changelog-details-3"]').html()).toContain('<strong>here</strong>');
        // The same entry under Major changes opens on its own.
        expect(wrapper.find('[data-testid="major-changelog-details-3"]').exists()).toBe(false);
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
        body('[data-testid="changelog-row-major-2"]').click();
        await flushPromises();

        expect(body('[data-testid="changelog-add-save"]').textContent.trim()).toBe('Add 2 entries');
        body('[data-testid="changelog-add-save"]').click();
        await flushPromises();

        expect(formPost).toHaveBeenCalledWith(
            '/changelog',
            {
                date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
                entries: [
                    { body: 'Added: First', details: null, major: false, technical: false },
                    { body: 'Added: Third', details: 'Some **detail**', major: true, technical: false },
                ],
            },
            expect.any(Object),
        );
    });

    it('edits one entry with its details and major flag', async () => {
        mountPage({ isEditor: true });
        await openFull();
        await wrapper.get('[data-testid="changelog-edit-3"]').trigger('click');
        await flushPromises();

        expect(body('[data-testid="changelog-edit-body"]').value).toBe('Newest change');
        expect(body('[data-testid="changelog-edit-details"]').value).toBe('More **here**');

        body('[data-testid="changelog-edit-major"]').click();
        await flushPromises();
        body('[data-testid="changelog-edit-save"]').click();
        await flushPromises();
        expect(formPost).toHaveBeenCalledWith('/changelog/3', { date: '2026-10-30', body: 'Added: Newest change', details: 'More **here**', major: false, technical: false }, expect.any(Object));
    });

    it('picks a tag per add row: Added by default, Security shows the hint and ticks Technical, a typed tag is not doubled', async () => {
        mountPage({ isEditor: true });
        await wrapper.get('[data-testid="changelog-add"]').trigger('click');
        await flushPromises();

        const tag = body('[data-testid="changelog-row-tag-0"]');
        expect(tag.value).toBe('Added');
        expect(document.body.querySelector('[data-testid="changelog-row-technical-hint-0"]')).toBeNull();
        tag.value = 'Security';
        tag.dispatchEvent(new Event('change'));
        await flushPromises();
        expect(body('[data-testid="changelog-row-technical-hint-0"]').textContent).toContain('Say what was fixed, not how it could be abused.');
        // A Security entry is always technical: the tick is set and locked.
        expect(body('[data-testid="changelog-row-technical-0"]').getAttribute('data-state')).toBe('checked');
        expect(body('[data-testid="changelog-row-technical-0"]').hasAttribute('disabled')).toBe(true);

        const first = body('[data-testid="changelog-row-body-0"]');
        first.value = 'Sign-in checks tightened';
        first.dispatchEvent(new Event('input'));
        first.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        await flushPromises();
        const second = body('[data-testid="changelog-row-body-1"]');
        second.value = 'Fixed: roster sort';
        second.dispatchEvent(new Event('input'));
        await flushPromises();
        body('[data-testid="changelog-add-save"]').click();
        await flushPromises();

        expect(formPost).toHaveBeenCalledWith(
            '/changelog',
            {
                date: expect.any(String),
                entries: [
                    { body: 'Security: Sign-in checks tightened', details: null, major: false, technical: true },
                    { body: 'Fixed: roster sort', details: null, major: false, technical: false },
                ],
            },
            expect.any(Object),
        );
    });

    it('edit puts the entry tag in the picker and the rest in the text', async () => {
        mountPage({ isEditor: true, days: [{ date: '2026-10-30', entries: [{ id: 9, body: 'Security: Session timeout fixed', major: false, technical: true, detailsHtml: null, details: null }] }] });
        await openFull();
        await wrapper.get('[data-testid="changelog-edit-9"]').trigger('click');
        await flushPromises();

        expect(body('[data-testid="changelog-edit-tag"]').value).toBe('Security');
        expect(body('[data-testid="changelog-edit-body"]').value).toBe('Session timeout fixed');
        expect(body('[data-testid="changelog-edit-technical-hint"]')).toBeTruthy();

        const tag = body('[data-testid="changelog-edit-tag"]');
        tag.value = 'Fixed';
        tag.dispatchEvent(new Event('change'));
        await flushPromises();
        body('[data-testid="changelog-edit-save"]').click();
        await flushPromises();
        expect(formPost).toHaveBeenCalledWith('/changelog/9', { date: '2026-10-30', body: 'Fixed: Session timeout fixed', details: null, major: false, technical: true }, expect.any(Object));
    });

    it('previews an import grouped by date, marks the entries it would skip, and imports the rest', async () => {
        postJson.mockResolvedValue({
            ok: true,
            data: {
                entries: [
                    { line: 2, date: '2026-10-01', body: 'Older change', details: null, duplicate: true },
                    { line: 3, date: '2026-10-01', body: 'Fresh', details: 'More', major: true, duplicate: false },
                    { line: 4, date: '2026-10-01', body: 'Tests run faster', details: null, technical: true, duplicate: true },
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
        expect(wrapper.get('[data-testid="changelog-import-entries"] h3').text()).toBe('01OCT26');
        expect(wrapper.find('[data-testid="preview-skipped-2"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="preview-details-3"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="preview-major-3"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="preview-technical-4"]').text()).toBe('Technical');
        expect(wrapper.find('[data-testid="preview-technical-3"]').exists()).toBe(false);
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

    describe('technical items', () => {
        const TECH_DAYS = [
            {
                date: '2026-10-30',
                entries: [
                    { id: 7, body: 'Visible change', major: false, technical: false, detailsHtml: null, details: null },
                    { id: 8, body: 'Dependency updates', major: false, technical: true, detailsHtml: null, details: null },
                ],
            },
        ];

        it('hides technical entries by default and shows them, marked, after the toggle, remembering the choice', async () => {
            mountPage({ days: TECH_DAYS, technicalToggle: true });
            await openFull();

            expect(wrapper.find('[data-testid="changelog-entry-8"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="major-changelog-entry-8"]').exists()).toBe(false);
            expect(wrapper.get('[data-testid="changelog-technical-toggle"]').attributes('data-state')).toBe('unchecked');

            await wrapper.get('[data-testid="changelog-technical-toggle"]').trigger('click');
            await flushPromises();

            expect(wrapper.get('[data-testid="changelog-technical-8"]').text()).toBe('Technical');
            expect(wrapper.find('[data-testid="changelog-technical-7"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="major-changelog-entry-8"]').exists()).toBe(false);
            expect(window.localStorage.getItem('changelog.showTechnical')).toBe('1');

            wrapper.unmount();
            // The full changelog stays open too: both choices are remembered.
            mountPage({ days: TECH_DAYS, technicalToggle: true });
            expect(wrapper.find('[data-testid="changelog-entry-8"]').exists()).toBe(true);
        });

        it('shows no toggle and no technical entries when an Admin has not made them available', async () => {
            window.localStorage.setItem('changelog.showTechnical', '1');
            mountPage({ days: TECH_DAYS, technicalToggle: false });
            await openFull();

            expect(wrapper.find('[data-testid="changelog-technical-toggle"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="changelog-entry-8"]').exists()).toBe(false);
        });

        it('always shows technical entries to an editor, marked, without the toggle', async () => {
            mountPage({ days: TECH_DAYS, isEditor: true });
            await openFull();

            expect(wrapper.find('[data-testid="changelog-technical-toggle"]').exists()).toBe(false);
            expect(wrapper.get('[data-testid="changelog-technical-8"]').text()).toBe('Technical');
        });

        it('keeps Major and Technical exclusive on an add row and shows the security hint', async () => {
            mountPage({ isEditor: true });
            await wrapper.get('[data-testid="changelog-add"]').trigger('click');
            await flushPromises();

            body('[data-testid="changelog-row-major-0"]').click();
            await flushPromises();
            body('[data-testid="changelog-row-technical-0"]').click();
            await flushPromises();

            expect(body('[data-testid="changelog-row-major-0"]').getAttribute('data-state')).toBe('unchecked');
            expect(body('[data-testid="changelog-row-technical-0"]').getAttribute('data-state')).toBe('checked');
            expect(body('[data-testid="changelog-row-technical-hint-0"]').textContent).toContain('Say what was fixed, not how it could be abused.');

            const row = body('[data-testid="changelog-row-body-0"]');
            row.value = 'Security hardening';
            row.dispatchEvent(new Event('input'));
            await flushPromises();
            body('[data-testid="changelog-add-save"]').click();
            await flushPromises();
            expect(formPost).toHaveBeenCalledWith(
                '/changelog',
                { date: expect.any(String), entries: [{ body: 'Added: Security hardening', details: null, major: false, technical: true }] },
                expect.any(Object),
            );
        });

        it('lets an Admin change the setting, and shows it to nobody else', async () => {
            mountPage({ technicalSetting: { enabled: false } });
            await wrapper.get('[data-testid="changelog-settings"]').trigger('click');
            await flushPromises();
            body('[data-testid="changelog-technical-setting-toggle"]').click();
            await flushPromises();
            expect(routerPost).toHaveBeenCalledWith('/changelog/settings', { technical_enabled: true }, expect.any(Object));

            wrapper.unmount();
            mountPage();
            expect(wrapper.find('[data-testid="changelog-settings"]').exists()).toBe(false);
        });
    });

    describe('tags, filters and the count', () => {
        const TAG_DAYS = [
            {
                date: '2026-10-30',
                entries: [
                    { id: 11, body: 'Security: Tighter session checks', major: true, technical: false, detailsHtml: null, details: null },
                    { id: 12, body: 'Fixed: Roster sorting', major: false, technical: false, detailsHtml: null, details: null },
                    { id: 13, body: 'Dependency updates', major: false, technical: true, detailsHtml: null, details: null },
                ],
            },
            { date: '2026-10-24', entries: [{ id: 14, body: 'Added: Changelog page', major: true, technical: false, detailsHtml: null, details: null }] },
        ];

        it('marks Security entries in both columns and keeps their text', async () => {
            mountPage({ days: TAG_DAYS });
            await openFull();
            expect(wrapper.get('[data-testid="changelog-security-11"]').text()).toBe('Security');
            expect(wrapper.get('[data-testid="major-changelog-security-11"]').text()).toBe('Security');
            expect(wrapper.get('[data-testid="changelog-entry-11"]').text()).toContain('Security: Tighter session checks');
            expect(wrapper.find('[data-testid="changelog-security-12"]').exists()).toBe(false);
        });

        it('filters both columns by one chip and remembers it', async () => {
            mountPage({ days: TAG_DAYS });
            await openFull();
            expect(wrapper.find('[data-testid="changelog-filter-technical"]').exists()).toBe(false);

            await wrapper.get('[data-testid="changelog-filter-fixed"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="changelog-entry-12"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="changelog-entry-11"]').exists()).toBe(false);
            expect(wrapper.get('[data-testid="changelog-major-empty"]').text()).toBe('No major changes match this filter.');
            expect(window.localStorage.getItem('changelog.filter')).toBe('fixed');

            wrapper.unmount();
            mountPage({ days: TAG_DAYS });
            await wrapper.get('[data-testid="changelog-filter-added"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="major-changelog-entry-14"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="major-changelog-entry-11"]').exists()).toBe(false);
        });

        it('offers a Technical chip once technical items show', async () => {
            mountPage({ days: TAG_DAYS, isEditor: true });
            await openFull();
            await wrapper.get('[data-testid="changelog-filter-technical"]').trigger('click');
            await flushPromises();
            expect(wrapper.find('[data-testid="changelog-entry-13"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="changelog-entry-12"]').exists()).toBe(false);
        });

        it('starts on All when localStorage throws', () => {
            vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
                throw new Error('blocked');
            });
            mountPage({ days: TAG_DAYS });
            expect(wrapper.get('[data-testid="changelog-filter-all"]').attributes('data-state')).toBe('on');
        });

        it('counts the changes this viewer can see since the first date', () => {
            mountPage({ days: TAG_DAYS });
            expect(wrapper.get('[data-testid="changelog-stat"]').text()).toBe('3 changes recorded since 24OCT26');
            wrapper.unmount();
            mountPage({ days: TAG_DAYS, isEditor: true });
            expect(wrapper.get('[data-testid="changelog-stat"]').text()).toBe('4 changes recorded since 24OCT26');
        });
    });

    describe('delete all and restore', () => {
        it('confirms with the count before deleting every entry, for editors only', async () => {
            mountPage();
            expect(wrapper.find('[data-testid="changelog-delete-all"]').exists()).toBe(false);
            wrapper.unmount();

            mountPage({ isEditor: true });
            await wrapper.get('[data-testid="changelog-delete-all"]').trigger('click');
            await flushPromises();

            const dialog = body('[data-testid="changelog-delete-all-dialog"]');
            expect(dialog.textContent).toContain('Delete all 3 entries?');
            expect(dialog.textContent).toContain('You can restore them for 72 hours.');
            expect(routerPost).not.toHaveBeenCalled();

            body('[data-testid="changelog-delete-all-confirm"]').click();
            await flushPromises();
            expect(routerPost).toHaveBeenCalledWith('/changelog/delete-all', {}, expect.any(Object));
        });

        it('hides Delete all with no entries', () => {
            mountPage({ isEditor: true, days: [] });
            expect(wrapper.find('[data-testid="changelog-delete-all"]').exists()).toBe(false);
        });

        it('shows the restore bar with Zulu stamps and restores on click', async () => {
            mountPage({ isEditor: true, days: [], pendingRestore: { count: 265, deletedAt: '2026-10-31T18:20:00Z', restoreUntil: '2026-11-03T18:20:00Z' } });

            expect(wrapper.get('[data-testid="changelog-restore-text"]').text()).toBe('265 entries deleted on 31OCT26 18 20Z. Restore until 03NOV26 18 20Z.');
            await wrapper.get('[data-testid="changelog-restore"]').trigger('click');
            expect(routerPost).toHaveBeenCalledWith('/changelog/restore', {}, expect.any(Object));
        });

        it('shows no restore bar without a pending restore', () => {
            mountPage({ isEditor: true });
            expect(wrapper.find('[data-testid="changelog-restore-bar"]').exists()).toBe(false);
        });
    });

describe('Changelog add dialog bulk rows', () => {
    const q = (id) => body(`[data-testid="${id}"]`);
    const state = (id) => q(id).getAttribute('data-state');
    async function openWithRows(count) {
        mountPage({ isEditor: true });
        await wrapper.get('[data-testid="changelog-add"]').trigger('click');
        await flushPromises();
        const input = q('changelog-add-count');
        input.value = String(count - 1);
        input.dispatchEvent(new Event('input'));
        await flushPromises();
        q('changelog-add-rows').click();
        await flushPromises();
        expect(document.body.querySelectorAll('[data-testid^="changelog-row-body-"]')).toHaveLength(count);
    }
    const setTag = async (index, tag) => {
        const select = q(`changelog-row-tag-${index}`);
        select.value = tag;
        select.dispatchEvent(new Event('change'));
        await flushPromises();
    };

    it('select all ticks every row, shows the bar, and goes indeterminate when one is unticked', async () => {
        await openWithRows(3);
        expect(q('changelog-bulk-bar')).toBeNull();
        q('changelog-select-all').click();
        await flushPromises();
        expect(q('changelog-bulk-count').textContent).toContain('3 selected');
        expect(state('changelog-row-select-1')).toBe('checked');
        q('changelog-row-select-1').click();
        await flushPromises();
        expect(state('changelog-select-all')).toBe('indeterminate');
        expect(q('changelog-bulk-count').textContent).toContain('2 selected');
        q('changelog-bulk-clear').click();
        await flushPromises();
        expect(q('changelog-bulk-bar')).toBeNull();
        expect(state('changelog-select-all')).toBe('unchecked');
    });

    it('bulk set Security ticks and locks Technical on every selected row', async () => {
        await openWithRows(3);
        q('changelog-row-major-0').click();
        q('changelog-select-all').click();
        await flushPromises();
        const tag = q('changelog-bulk-tag');
        tag.value = 'Security';
        tag.dispatchEvent(new Event('change'));
        await flushPromises();
        for (const i of [0, 1, 2]) {
            expect(q(`changelog-row-tag-${i}`).value).toBe('Security');
            expect(state(`changelog-row-technical-${i}`)).toBe('checked');
            expect(q(`changelog-row-technical-${i}`).hasAttribute('disabled')).toBe(true);
            expect(state(`changelog-row-major-${i}`)).toBe('unchecked');
        }
        q('changelog-bulk-untechnical').click();
        await flushPromises();
        expect(state('changelog-row-technical-1')).toBe('checked');
    });

    it('bulk major skips Security rows and says how many', async () => {
        await openWithRows(3);
        await setTag(1, 'Security');
        q('changelog-select-all').click();
        await flushPromises();
        q('changelog-bulk-major').click();
        await flushPromises();
        expect(state('changelog-row-major-0')).toBe('checked');
        expect(state('changelog-row-major-1')).toBe('unchecked');
        expect(state('changelog-row-technical-1')).toBe('checked');
        expect(state('changelog-row-major-2')).toBe('checked');
        expect(q('changelog-bulk-note').textContent).toContain('1 Security row was skipped.');
        q('changelog-bulk-technical').click();
        await flushPromises();
        expect(state('changelog-row-major-0')).toBe('unchecked');
        expect(state('changelog-row-technical-0')).toBe('checked');
    });

    it('removes the selected rows', async () => {
        await openWithRows(3);
        const second = q('changelog-row-body-1');
        second.value = 'Keep me';
        second.dispatchEvent(new Event('input'));
        q('changelog-row-select-0').click();
        q('changelog-row-select-2').click();
        await flushPromises();
        q('changelog-bulk-remove').click();
        await flushPromises();
        expect(document.body.querySelectorAll('[data-testid^="changelog-row-body-"]')).toHaveLength(1);
        expect(q('changelog-row-body-0').value).toBe('Keep me');
        expect(q('changelog-bulk-bar')).toBeNull();
    });

    it('shift-click selects a range of rows', async () => {
        await openWithRows(5);
        q('changelog-row-select-1').click();
        await flushPromises();
        const last = q('changelog-row-select-3');
        last.dispatchEvent(new PointerEvent('pointerdown', { shiftKey: true, bubbles: true }));
        last.click();
        await flushPromises();
        expect([0, 1, 2, 3, 4].map((i) => state(`changelog-row-select-${i}`))).toEqual(['unchecked', 'checked', 'checked', 'checked', 'unchecked']);
        expect(q('changelog-bulk-count').textContent).toContain('3 selected');
    });
});
});
