import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import Templates from '../Templates.vue';
import { FILTERS, LISTS, ROW_FLAGS, ROW_STRINGS, TOP_STRINGS } from '@/lib/bbcode';

const post = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();
const routerGet = vi.fn();
const routerPost = vi.fn();
let formState;
let createState;
const createPost = vi.fn();
const mockPage = { url: '/settings/templates', props: {} };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { get: (...args) => routerGet(...args), post: (...args) => routerPost(...args) },
    usePage: () => mockPage,
    useForm: (initial) => {
        // The page has two forms: the editor's, and the new-template dialog's (the one with a name).
        if ('name' in initial) {
            createState = reactive({ ...initial, processing: false, errors: {}, post: createPost, reset: vi.fn(), clearErrors: vi.fn() });

            return createState;
        }
        formState = reactive({ ...initial, processing: false, errors: {}, post, reset: vi.fn(), clearErrors: vi.fn() });

        return formState;
    },
}));

vi.mock('vue-sonner', () => ({ toast: { error: (...args) => toastError(...args), success: (...args) => toastSuccess(...args) } }));

afterEach(() => {
    mockPage.props = {};
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    post.mockReset();
    toastError.mockReset();
    toastSuccess.mockReset();
    routerGet.mockReset();
    routerPost.mockReset();
    createPost.mockReset();
});

const COMPANIES = [
    { id: 1, name: 'Echo Company', callsign: 'E/2-7' },
    { id: 2, name: 'Foxtrot Company', callsign: null },
];
const VERSION_2 = { id: 22, version: 2, body: '[B]second[/B]', createdAt: '2026-10-08T09:30:00+00:00', createdBy: 'Pike.S' };
const VERSION_1 = { id: 21, version: 1, body: '[B]first[/B]', createdAt: '2026-10-01T09:30:00+00:00', createdBy: 'Pike.S' };

const STARTER = '[B]{{unit}}[/B]{{#troopers}}{{name}}{{/troopers}}';

const TEMPLATES = [
    { id: 5, name: 'Regiment default', isRegimentDefault: true, enabled: true, isCompanyActive: false, version: 1 },
    { id: 7, name: 'Standard', isRegimentDefault: false, enabled: true, isCompanyActive: true, version: 2 },
    { id: 8, name: 'Training week', isRegimentDefault: false, enabled: false, isCompanyActive: false, version: 1 },
];

const props = (overrides = {}) => ({
    artifactType: { value: 'rollcall_bbcode', label: 'Rollcall BBCode' },
    companies: COMPANIES,
    company: 1,
    templates: TEMPLATES,
    template: 7,
    canManage: true,
    canManageCompany: true,
    active: VERSION_2,
    history: [{ ...VERSION_2, isActive: true }, { ...VERSION_1, isActive: false }],
    maxBodyLength: 20000,
    starter: STARTER,
    isAdmin: true,
    ...overrides,
});

const page = (overrides = {}) => mount(Templates, { props: props(overrides), attachTo: document.body });
const dialog = () => document.body.querySelector('[data-testid="activate-dialog"]');
const starterDialog = () => document.body.querySelector('[data-testid="starter-dialog"]');
const field = (testid) => document.body.querySelector(`[data-testid="${testid}"]`);
const typeReason = async (text) => {
    const input = field('activate-reason');
    input.value = text;
    input.dispatchEvent(new Event('input'));
    await nextTick();
};

describe('Settings/Templates', () => {
    it('lists the companies, labelled with their callsign when they have one', () => {
        const options = page().findAll('[data-testid="company-select"] option').map((o) => o.text());

        expect(options).toEqual(['E/2-7 Echo Company', 'Foxtrot Company']);
    });

    it('asks the server for another company when one is chosen', async () => {
        const wrapper = page();
        const select = wrapper.get('[data-testid="company-select"]');

        select.element.value = '2';
        await select.trigger('change');

        expect(routerGet).toHaveBeenCalledWith('/settings/templates', { company: '2' }, { preserveScroll: true });
    });

    describe('switching company with unsaved changes', () => {
        const switchTo = async (wrapper, id) => {
            const select = wrapper.get('[data-testid="company-select"]');
            select.element.value = id;
            await select.trigger('change');
        };

        it('does not ask when nothing was changed', async () => {
            const confirm = vi.fn();
            vi.stubGlobal('confirm', confirm);

            await switchTo(page(), '2');

            expect(confirm).not.toHaveBeenCalled();
            expect(routerGet).toHaveBeenCalled();
        });

        it.each([
            ['the template', 'body', '[B]half-written[/B]'],
            ['the reason', 'reason', 'Adds the squad column'],
        ])('asks before dropping an edit to %s, and staying keeps the draft and the company', async (_label, field, value) => {
            const confirm = vi.fn(() => false);
            vi.stubGlobal('confirm', confirm);
            const wrapper = page();
            formState[field] = value;

            await switchTo(wrapper, '2');
            await nextTick();

            expect(confirm).toHaveBeenCalledOnce();
            expect(routerGet).not.toHaveBeenCalled();
            expect(wrapper.get('[data-testid="company-select"]').element.value).toBe('1');
            expect(formState[field]).toBe(value);
        });

        it('switches when the Admin agrees to lose the draft', async () => {
            vi.stubGlobal('confirm', vi.fn(() => true));
            const wrapper = page();
            formState.body = '[B]half-written[/B]';

            await switchTo(wrapper, '2');

            expect(routerGet).toHaveBeenCalledWith('/settings/templates', { company: '2' }, { preserveScroll: true });
        });
    });

    it('shows the active version number and starts an Admin\'s editor from its text', () => {
        const wrapper = page();

        expect(wrapper.get('[data-testid="active-version"]').text()).toBe('Version 2');
        expect(formState.body).toBe('[B]second[/B]');
        expect(wrapper.get('[data-testid="template-body"]').element.value).toBe('[B]second[/B]');
    });

    it('lists every version with its date in forum convention and its author, marking the active one', () => {
        const wrapper = page();

        expect(wrapper.get('[data-testid="version-row-22"]').text()).toContain('08Oct26 by Pike.S');
        expect(wrapper.get('[data-testid="version-row-21"]').text()).toContain('01Oct26');
        expect(wrapper.get('[data-testid="version-row-22"]').find('[data-testid="active-badge"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="version-row-21"]').find('[data-testid="active-badge"]').exists()).toBe(false);
    });

    it('offers an Admin a way to make an older version active, but not the one that already is', () => {
        const wrapper = page();

        expect(wrapper.find('[data-testid="activate-21"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="activate-22"]').exists()).toBe(false);
    });

    it('saves an edit as a new version through the form', async () => {
        const wrapper = page();

        await wrapper.get('[data-testid="template-form"]').trigger('submit');

        expect(post).toHaveBeenCalledWith('/settings/templates', expect.objectContaining({ preserveScroll: true }));
    });

    it('says why a save was refused or failed instead of showing the raw error page', async () => {
        const wrapper = page();

        await wrapper.get('[data-testid="template-form"]').trigger('submit');
        const { onHttpException } = post.mock.calls[0][1];

        expect(onHttpException({ status: 419 })).toBe(false);
        expect(toastError).toHaveBeenCalledWith('The template was not saved. Your session expired; reload the page and try again.');
    });

    it('asks for a reason in a labelled dialog before making an older version active, and cancelling does nothing', async () => {
        const wrapper = page();

        expect(dialog()).toBeNull();

        await wrapper.get('[data-testid="activate-21"]').trigger('click');
        await flushPromises();

        expect(dialog()).not.toBeNull();
        expect(dialog().textContent).toContain('Make version 1 the active template?');
        expect(dialog().querySelector('label[for="activate-reason"]').textContent).toContain('Reason for the record');

        [...dialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Cancel').click();
        await flushPromises();

        expect(routerPost).not.toHaveBeenCalled();
    });

    it('keeps the confirm button disabled until there is a reason, so a blank one is never sent', async () => {
        const wrapper = page();

        await wrapper.get('[data-testid="activate-21"]').trigger('click');
        await flushPromises();

        expect(field('activate-confirm').disabled).toBe(true);

        await typeReason('   ');
        expect(field('activate-confirm').disabled).toBe(true);
        field('activate-confirm').click();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('sends the reason when making an older version active, and shows a pending state that blocks a second click', async () => {
        const wrapper = page();

        await wrapper.get('[data-testid="activate-21"]').trigger('click');
        await flushPromises();
        await typeReason('The second edit broke the table');
        field('activate-confirm').click();
        await nextTick();

        expect(routerPost).toHaveBeenCalledWith('/settings/templates/21/activate', { reason: 'The second edit broke the table' }, expect.objectContaining({ preserveScroll: true }));

        const options = routerPost.mock.calls[0][2];
        options.onStart();
        await nextTick();

        expect(field('activate-confirm').disabled).toBe(true);
        expect(field('activate-confirm').textContent).toContain('Activating');

        field('activate-confirm').click();
        expect(routerPost).toHaveBeenCalledOnce();
    });

    it('shows the server\'s error for the reason inside the dialog and lets the Admin try again', async () => {
        const wrapper = page();

        await wrapper.get('[data-testid="activate-21"]').trigger('click');
        await flushPromises();
        await typeReason('because');
        field('activate-confirm').click();
        const options = routerPost.mock.calls[0][2];
        options.onStart();
        options.onError({ reason: 'The reason is too short.' });
        options.onFinish();
        await nextTick();

        expect(field('activate-error').textContent).toBe('The reason is too short.');
        expect(field('activate-confirm').disabled).toBe(false);
    });

    it('opens the text of a version to read, so a rollback is never blind', async () => {
        const wrapper = page();

        expect(wrapper.find('[data-testid="version-body-21"]').exists()).toBe(false);

        await wrapper.get('[data-testid="view-21"]').trigger('click');

        expect(wrapper.get('[data-testid="version-body-21"]').text()).toBe('[B]first[/B]');
        expect(wrapper.get('[data-testid="view-21"]').attributes('aria-expanded')).toBe('true');

        await wrapper.get('[data-testid="view-21"]').trigger('click');

        expect(wrapper.find('[data-testid="version-body-21"]').exists()).toBe(false);
    });

    describe('comparing a version with the active one', () => {
        const ACTIVE = { ...VERSION_2, body: '[B]{{unit}}[/B]\n[TABLE]\n{{#troopers}}[TR][TD]{{name}}[/TD][/TR]{{/troopers}}\n[/TABLE]' };
        const OLDER = { ...VERSION_1, body: '[B]{{unit_name}}[/B]\n[TABLE]\n{{#troopers}}[TR][TD]{{name}}[/TD][/TR]{{/troopers}}\n[/TABLE]\n[I]Signed {{signed}}[/I]' };
        const compared = (overrides = {}) => page({ active: ACTIVE, history: [{ ...ACTIVE, isActive: true }, { ...OLDER, isActive: false }], ...overrides });

        it('offers it on every version but the active one', () => {
            const wrapper = compared();

            expect(wrapper.get('[data-testid="compare-21"]').text()).toBe('Compare with active');
            expect(wrapper.find('[data-testid="compare-22"]').exists()).toBe(false);
        });

        it('shows what the version has that the active one does not, line by line', async () => {
            const wrapper = compared();

            await wrapper.get('[data-testid="compare-21"]').trigger('click');

            const diff = wrapper.get('[data-testid="version-compare-21"]');
            expect(wrapper.get('[data-testid="compare-21"]').attributes('aria-expanded')).toBe('true');
            expect(wrapper.get('[data-testid="compare-21"]').text()).toBe('Hide comparison');
            expect(diff.text()).toContain('From the active version 2 to version 1');
            expect(diff.get('[data-testid="diff-summary"]').text()).toBe('2 lines added, 1 line removed');
            expect(diff.findAll('[data-testid="diff-removed"]').map((row) => row.text())).toEqual(['1−Removed: [B]{{unit}}[/B]']);
            expect(diff.findAll('[data-testid="diff-added"]').map((row) => row.text())).toEqual(['1+Added: [B]{{unit_name}}[/B]', '5+Added: [I]Signed {{signed}}[/I]']);
            expect(diff.findAll('[data-testid="diff-same"]')).toHaveLength(3);

            await wrapper.get('[data-testid="compare-21"]').trigger('click');

            expect(wrapper.find('[data-testid="version-compare-21"]').exists()).toBe(false);
        });

        it('lets a non-Admin compare too, since comparing changes nothing', async () => {
            const wrapper = compared({ isAdmin: false, canManage: false, canManageCompany: false });

            await wrapper.get('[data-testid="compare-21"]').trigger('click');

            expect(wrapper.find('[data-testid="version-compare-21"]').exists()).toBe(true);
        });

        it('says so when the version has the same text as the active one', async () => {
            const wrapper = compared({ history: [{ ...ACTIVE, isActive: true }, { ...VERSION_1, body: ACTIVE.body, isActive: false }] });

            await wrapper.get('[data-testid="compare-21"]').trigger('click');

            expect(wrapper.get('[data-testid="diff-summary"]').text()).toBe('No differences.');
            expect(wrapper.find('[data-testid="diff-lines"]').exists()).toBe(false);
        });

        it('offers nothing to compare with while the company has no active version', () => {
            expect(compared({ active: null }).find('[data-testid="compare-21"]').exists()).toBe(false);
        });
    });

    it('lets a non-Admin read a version too, since reading changes nothing', async () => {
        const wrapper = page({ isAdmin: false, canManage: false, canManageCompany: false });

        await wrapper.get('[data-testid="view-21"]').trigger('click');

        expect(wrapper.get('[data-testid="version-body-21"]').text()).toBe('[B]first[/B]');
    });

    it('keeps the controls, disabled with the reason, while an Admin is viewing as a leader', async () => {
        mockPage.props = { viewAs: { kind: 'unit', designation: '2/1/A/1-7', label: 'SGT Vasquez.R', admin: true } };
        const wrapper = page({ isAdmin: false, canManage: false, canManageCompany: false });

        expect(wrapper.find('[data-testid="template-form"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="template-body"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="template-reason"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="save-template"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="save-template-readonly"]').attributes('aria-disabled')).toBe('true');
        expect(wrapper.get('[data-testid="activate-21"]').attributes('aria-disabled')).toBe('true');

        await wrapper.get('[data-testid="activate-21"]').trigger('click');
        await flushPromises();

        expect(dialog()).toBeNull();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('hides every editing control from a non-Admin and shows the active text read-only', () => {
        const wrapper = page({ isAdmin: false, canManage: false, canManageCompany: false });

        expect(wrapper.find('[data-testid="template-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="activate-21"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="template-readonly"]').text()).toBe('[B]second[/B]');
    });

    it('says plainly when a company has no template yet, and offers an Admin the first one', () => {
        const wrapper = page({ active: null, history: [] });

        expect(wrapper.get('[data-testid="no-active"]').text()).toContain('no text yet');
        expect(wrapper.get('[data-testid="no-active"]').text()).toContain('Write it below');
        expect(wrapper.get('[data-testid="no-versions"]').exists()).toBe(true);
        expect(formState.body).toBe('');
    });

    it("tells someone who may not edit that the company's command can write it", () => {
        const wrapper = page({ active: null, history: [], isAdmin: false, canManage: false, canManageCompany: false });

        expect(wrapper.get('[data-testid="no-active"]').text()).toContain("The company's command can write it here");
        expect(wrapper.find('[data-testid="template-readonly"]').exists()).toBe(false);
    });

    it('says there are no companies, with no controls, when the org has none', () => {
        const wrapper = page({ companies: [], company: null, active: null, history: [] });

        expect(wrapper.get('[data-testid="no-companies"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="company-select"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="template-form"]').exists()).toBe(false);
    });

    it('shows the server\'s validation errors beside the field they belong to', () => {
        const wrapper = page();
        formState.errors = { body: 'A template needs some text.', reason: 'The reason field is required.', template_id: 'That record no longer exists.' };

        return wrapper.vm.$nextTick().then(() => {
            expect(wrapper.get('[data-testid="error-body"]').text()).toBe('A template needs some text.');
            expect(wrapper.get('[data-testid="error-reason"]').text()).toBe('The reason field is required.');
            expect(wrapper.get('[data-testid="error-template_id"]').text()).toBe('That record no longer exists.');
        });
    });

    it('caps the editor at the server\'s limit', () => {
        expect(page().get('[data-testid="template-body"]').attributes('maxlength')).toBe('20000');
    });

    it('starts an Admin with no template from a blank editor, and fills the default only on request', async () => {
        const wrapper = page({ active: null, history: [] });

        expect(wrapper.get('[data-testid="template-body"]').element.value).toBe('');
        expect(wrapper.get('[data-testid="use-starter"]').text()).toBe('Start from the default');
        await wrapper.get('[data-testid="use-starter"]').trigger('click');
        await flushPromises();

        expect(starterDialog()).toBeNull();
        expect(wrapper.get('[data-testid="template-body"]').element.value).toBe(STARTER);
    });

    describe('starting from the default template', () => {
        it('fills the editor with the default straight away when it holds the active text unchanged', async () => {
            const wrapper = page();

            await wrapper.get('[data-testid="use-starter"]').trigger('click');
            await flushPromises();

            expect(starterDialog()).toBeNull();
            expect(wrapper.get('[data-testid="template-body"]').element.value).toBe(STARTER);
        });

        it('only fills the editor: nothing is saved until the Admin saves a new version', async () => {
            const wrapper = page();

            await wrapper.get('[data-testid="use-starter"]').trigger('click');
            await flushPromises();

            expect(post).not.toHaveBeenCalled();
            expect(routerPost).not.toHaveBeenCalled();
            expect(formState.reason).toBe('');
            expect(wrapper.get('[data-testid="save-template"]').text()).toBe('Save as a new version');
        });

        it('asks before replacing edits that are not saved, and keeping them changes nothing', async () => {
            const wrapper = page();
            await wrapper.get('[data-testid="template-body"]').setValue('[B]my edit[/B]');

            await wrapper.get('[data-testid="use-starter"]').trigger('click');
            await flushPromises();

            expect(starterDialog()).not.toBeNull();
            expect(starterDialog().textContent).toContain('Replace your edits with the default template?');
            expect(formState.body).toBe('[B]my edit[/B]');

            [...starterDialog().querySelectorAll('button')].find((b) => b.textContent.trim() === 'Keep my edits').click();
            await flushPromises();

            expect(starterDialog()).toBeNull();
            expect(formState.body).toBe('[B]my edit[/B]');
        });

        it('replaces the edits with the default once the Admin confirms', async () => {
            const wrapper = page();
            await wrapper.get('[data-testid="template-body"]').setValue('[B]my edit[/B]');

            await wrapper.get('[data-testid="use-starter"]').trigger('click');
            await flushPromises();
            starterDialog().querySelector('[data-testid="starter-confirm"]').click();
            await flushPromises();

            expect(starterDialog()).toBeNull();
            expect(wrapper.get('[data-testid="template-body"]').element.value).toBe(STARTER);
        });

        it('does not ask when the editor already holds the default', async () => {
            const wrapper = page();
            await wrapper.get('[data-testid="template-body"]').setValue(STARTER);

            await wrapper.get('[data-testid="use-starter"]').trigger('click');
            await flushPromises();

            expect(starterDialog()).toBeNull();
        });

        it('is not offered to a non-Admin, or while an Admin is viewing as a leader', () => {
            expect(page({ isAdmin: false, canManage: false, canManageCompany: false }).find('[data-testid="use-starter"]').exists()).toBe(false);

            mockPage.props = { viewAs: { kind: 'unit', designation: '2/1/A/1-7', label: 'SGT Vasquez.R', admin: true } };
            expect(page({ isAdmin: false, canManage: false, canManageCompany: false }).find('[data-testid="use-starter"]').exists()).toBe(false);
        });
    });

    describe('the legend of template codes', () => {
        const groups = [
            ['values', TOP_STRINGS, (name) => `{{${name}}}`],
            ['lists', LISTS, (name) => `{{#${name}}}...{{/${name}}}`],
            ['rowValues', ROW_STRINGS, (name) => `{{${name}}}`],
            ['rowFlags', ROW_FLAGS, (name) => `{{#${name}}}...{{/${name}}}`],
            ['filters', FILTERS, (name) => `{{value|${name}}}`],
        ];

        it.each(groups)('lists every code in the %s group the parser accepts, each with a meaning and an example', (key, names, code) => {
            const group = page().get(`[data-testid="legend-${key}"]`);
            const entries = group.findAll('[data-testid^="legend-entry-"]');

            expect(entries.map((entry) => entry.attributes('data-testid'))).toEqual(names.map((name) => `legend-entry-${name}`));
            for (const [index, name] of names.entries()) {
                const entry = entries[index];
                expect(entry.get('[data-testid="legend-code"]').text()).toBe(code(name));
                expect(entry.get('[data-testid="legend-meaning"]').text()).toMatch(/^[A-Z].{5,}\.$/);
                expect(entry.get('[data-testid="legend-example"]').text()).not.toBe('');
            }
        });

        it('heads each group with its name and where its codes go', () => {
            const wrapper = page();
            const heading = (key) => [wrapper.get(`[data-testid="legend-${key}"] h3`).text(), wrapper.get(`[data-testid="legend-${key}"] [data-testid="legend-where"]`).text()];

            expect(heading('values')).toEqual(['Values', 'Anywhere in the template']);
            expect(heading('topFlags')).toEqual(['Yes/no flags for the post', 'Anywhere in the template']);
            expect(heading('rowFlags')).toEqual(['Yes/no flags for a trooper', 'Inside {{#troopers}}']);
            expect(wrapper.find('[data-testid="legend-lists"] [data-testid="legend-where"]').exists()).toBe(false);
        });

        it('copies a code to the clipboard from its button', async () => {
            const writeText = vi.fn().mockResolvedValue();
            vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
            const wrapper = page();
            const button = wrapper.get('[data-testid="legend-copy-rank_icon"]');

            expect(button.attributes('aria-label')).toBe('Copy {{rank_icon}}');
            await button.trigger('click');
            await flushPromises();

            expect(writeText).toHaveBeenCalledWith('{{rank_icon}}');
            expect(button.attributes('aria-label')).toBe('Copied {{rank_icon}}');
            expect(toastSuccess).toHaveBeenCalledWith('Code copied', { description: '{{rank_icon}}' });
            expect(toastError).not.toHaveBeenCalled();
        });

        it('says so when the browser will not copy a code, and shows no tick', async () => {
            const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
            const writeText = vi.fn().mockRejectedValue(Object.assign(new Error('denied'), { name: 'NotAllowedError' }));
            vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
            const wrapper = page();
            const button = wrapper.get('[data-testid="legend-copy-rank_icon"]');

            await button.trigger('click');
            await flushPromises();

            expect(toastError).toHaveBeenCalledWith('Could not copy the code', { description: expect.stringContaining('blocked clipboard access') });
            expect(toastSuccess).not.toHaveBeenCalled();
            expect(button.attributes('aria-label')).toBe('Copy {{rank_icon}}');
            consoleError.mockRestore();
        });

        describe('inserting a code into the editor', () => {
            const editor = (wrapper) => wrapper.get('[data-testid="template-body"]').element;
            const select = (wrapper, start, end = start) => editor(wrapper).setSelectionRange(start, end);

            afterEach(() => {
                vi.useRealTimers();
            });

            it('puts a value code at the cursor and keeps the cursor after it, in the editor', async () => {
                const wrapper = page({ active: { ...VERSION_2, body: '[B][/B]' } });
                select(wrapper, 3);

                await wrapper.get('[data-testid="legend-insert-unit"]').trigger('click');
                await flushPromises();

                expect(formState.body).toBe('[B]{{unit}}[/B]');
                expect(editor(wrapper).value).toBe('[B]{{unit}}[/B]');
                expect(document.activeElement).toBe(editor(wrapper));
                expect([editor(wrapper).selectionStart, editor(wrapper).selectionEnd]).toEqual([11, 11]);
            });

            it('replaces the selection with a value code', async () => {
                const wrapper = page({ active: { ...VERSION_2, body: 'Week of WEEK here' } });
                select(wrapper, 8, 12);

                await wrapper.get('[data-testid="legend-insert-week"]').trigger('click');
                await flushPromises();

                expect(formState.body).toBe('Week of {{week}} here');
                expect(editor(wrapper).selectionStart).toBe(16);
            });

            it('wraps the selection in a section\'s open and close tags, the cursor after the close', async () => {
                const wrapper = page({ active: { ...VERSION_2, body: '[TR]row[/TR]' } });
                select(wrapper, 0, 12);

                await wrapper.get('[data-testid="legend-insert-troopers"]').trigger('click');
                await flushPromises();

                expect(formState.body).toBe('{{#troopers}}[TR]row[/TR]{{/troopers}}');
                expect(editor(wrapper).selectionStart).toBe(38);
                expect(editor(wrapper).selectionEnd).toBe(38);
            });

            it('puts the cursor between a section\'s tags when nothing is selected', async () => {
                const wrapper = page({ active: { ...VERSION_2, body: 'ab' } });
                select(wrapper, 1);

                await wrapper.get('[data-testid="legend-insert-sp_present"]').trigger('click');
                await flushPromises();

                expect(formState.body).toBe('a{{#sp_present}}{{/sp_present}}b');
                expect([editor(wrapper).selectionStart, editor(wrapper).selectionEnd]).toEqual([16, 16]);
            });

            it('inserts through the browser\'s own editing where it can, so Ctrl+Z undoes it, and does not insert twice', async () => {
                const wrapper = page({ active: { ...VERSION_2, body: 'a[TR]row[/TR]b' } });
                let selection = null;
                document.execCommand = vi.fn(() => {
                    selection = [editor(wrapper).selectionStart, editor(wrapper).selectionEnd, document.activeElement === editor(wrapper)];

                    return true;
                });
                select(wrapper, 1, 13);

                try {
                    await wrapper.get('[data-testid="legend-insert-troopers"]').trigger('click');
                    await flushPromises();

                    expect(document.execCommand).toHaveBeenCalledOnce();
                    expect(document.execCommand).toHaveBeenCalledWith('insertText', false, '{{#troopers}}[TR]row[/TR]{{/troopers}}');
                    expect(selection).toEqual([1, 13, true]);
                    // The browser's own `input` event updates the form; the page does not write the text a second time.
                    expect(formState.body).toBe('a[TR]row[/TR]b');
                } finally {
                    delete document.execCommand;
                }
            });

            it('redraws the preview after an insert, as typing does', async () => {
                vi.useFakeTimers();
                const wrapper = page({ active: { ...VERSION_2, body: '[I][/I]' } });
                select(wrapper, 3);

                await wrapper.get('[data-testid="legend-insert-unit_name"]').trigger('click');
                await nextTick();
                vi.advanceTimersByTime(300);
                await nextTick();

                expect(wrapper.get('[data-testid="template-preview-post"] em').text()).toBe('1st Section');
            });

            it('leaves the editor as it was when the code would take it past the length limit', async () => {
                const wrapper = page({ active: { ...VERSION_2, body: 'x'.repeat(19995) } });
                select(wrapper, 0);

                await wrapper.get('[data-testid="legend-insert-unit"]').trigger('click');
                await flushPromises();

                expect(formState.body).toBe('x'.repeat(19995));
            });

            it('tells an Admin a code can be clicked, and keeps the copy button beside it', () => {
                const wrapper = page();

                expect(wrapper.get('[data-testid="syntax-help"]').text()).toContain('Click a code to put it in the template at the cursor.');
                expect(wrapper.get('[data-testid="legend-insert-rank"]').attributes('aria-label')).toBe('Insert {{rank}} in the template');
                expect(wrapper.find('[data-testid="legend-copy-rank"]').exists()).toBe(true);
            });

            it('offers a non-Admin only the copy button', () => {
                const wrapper = page({ isAdmin: false, canManage: false, canManageCompany: false });

                expect(wrapper.find('[data-testid^="legend-insert-"]').exists()).toBe(false);
                expect(wrapper.get('[data-testid="legend-entry-rank"] [data-testid="legend-code"]').text()).toBe('{{rank}}');
                expect(wrapper.find('[data-testid="legend-copy-rank"]').exists()).toBe(true);
                expect(wrapper.get('[data-testid="syntax-help"]').text()).not.toContain('Click a code');
            });

            it('offers only the copy button while an Admin is viewing as a leader, and the editor stays as it was', async () => {
                mockPage.props = { viewAs: { kind: 'unit', designation: '2/1/A/1-7', label: 'SGT Vasquez.R', admin: true } };
                const wrapper = page({ isAdmin: false, canManage: false, canManageCompany: false });

                expect(wrapper.find('[data-testid^="legend-insert-"]').exists()).toBe(false);
                expect(wrapper.find('[data-testid="legend-copy-rank"]').exists()).toBe(true);
                await wrapper.get('[data-testid="legend-entry-rank"] [data-testid="legend-code"]').trigger('click');
                expect(formState.body).toBe('[B]second[/B]');
            });
        });

        it('explains the rank icon and shows how to print it only when Cav has one', () => {
            const entry = page().get('[data-testid="legend-entry-rank_icon"]');

            expect(entry.text()).toContain('rank insignia');
            expect(entry.text()).toContain('{{#rank_icon}}[IMG]{{rank_icon}}[/IMG]{{/rank_icon}}');
        });

        it('explains the rank icon width and shows how to size the [IMG] with it', () => {
            const entry = page().get('[data-testid="legend-entry-rank_icon_width"]');

            expect(entry.text()).toContain('same size');
            expect(entry.text()).toContain('[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG]');
        });
    });

    describe('the preview', () => {
        afterEach(() => {
            vi.useRealTimers();
        });

        it('draws the active template as the forum shows it, filled with the sample section', () => {
            const wrapper = page({ active: { ...VERSION_2, body: '[B]{{unit}}[/B][TABLE]{{#troopers}}[TR][TD]{{display_name}}[/TD][/TR]{{/troopers}}[/TABLE]' } });
            const post = wrapper.get('[data-testid="template-preview-post"]');

            expect(post.get('strong').text()).toBe('1/E/2-7');
            expect(post.findAll('td').map((cell) => cell.text())).toEqual(['SGT Jay.JH', 'CPL Adams.P', 'SPC Reyes.M', 'PFC Novak.T', 'PVT Okafor.B']);
            expect(wrapper.get('[data-testid="template-preview"]').text()).toContain('sample section of 5 troopers');
        });

        it('follows the editor a moment after the Admin stops typing', async () => {
            vi.useFakeTimers();
            const wrapper = page();
            const body = wrapper.get('[data-testid="template-body"]');

            await body.setValue('[I]{{unit_name}}[/I]');
            expect(wrapper.get('[data-testid="template-preview-post"]').find('em').exists()).toBe(false);

            vi.advanceTimersByTime(300);
            await nextTick();

            expect(wrapper.get('[data-testid="template-preview-post"] em').text()).toBe('1st Section');
        });

        it('says why there is no preview while the codes do not parse', async () => {
            vi.useFakeTimers();
            const wrapper = page();

            await wrapper.get('[data-testid="template-body"]').setValue('{{#troopers}}');
            vi.advanceTimersByTime(300);
            await nextTick();

            expect(wrapper.find('[data-testid="template-preview-post"]').exists()).toBe(false);
            expect(wrapper.get('[data-testid="template-preview-error"]').text()).toContain('is never closed');
        });

        it('shows HTML in a template as text, never as markup', () => {
            const wrapper = page({ active: { ...VERSION_2, body: '<img src=x onerror=alert(1)><script>alert(1)</script>[IMG]javascript:alert(1)[/IMG]' } });
            const post = wrapper.get('[data-testid="template-preview-post"]');

            expect(post.find('img, script').exists()).toBe(false);
            expect(post.text()).toContain('<script>alert(1)</script>');
        });

        it('shows nothing for an empty editor', () => {
            expect(page({ active: null, history: [] }).find('[data-testid="template-preview"]').exists()).toBe(false);
        });

        describe('with a real section', () => {
            const SECTIONS = [
                { id: 7, designation: '2/1/E/2-7', name: '2nd Section', platoon: '1/E/2-7' },
                { id: 8, designation: '3/1/E/2-7', name: '3rd Section', platoon: '1/E/2-7' },
            ];
            const TABLE = '[B]{{unit}}[/B] {{week_start}} [TABLE]{{#troopers}}[TR][TD]{{display_name}}[/TD][TD]{{sp}}[/TD][/TR]{{/troopers}}[/TABLE]';
            const ROLLCALL = {
                section: { id: 7, designation: '2/1/E/2-7', name: '2nd Section', platoon: '1/E/2-7' },
                week: { start: '2026-10-03T00:00:00+00:00', closes: '2026-10-09T23:59:00+00:00' },
                troopers: [
                    { id: 1, cavId: 11, rank: 'Sergeant', rankShort: 'SGT', rankIcon: null, name: 'Vasquez.R', displayName: 'SGT Vasquez.R', rollcall: 'will_attend', status: 'present' },
                    { id: 2, cavId: 12, rank: 'Private', rankShort: 'PVT', rankIcon: null, name: 'Lowe.K', displayName: 'PVT Lowe.K', rollcall: 'unsigned', status: 'loa' },
                ],
            };
            const respond = (status, body = {}) => vi.fn(async () => ({ ok: status < 300, status, json: async () => body }));
            const real = (overrides = {}) => page({ active: { ...VERSION_2, body: TABLE }, previewSections: SECTIONS, ...overrides });
            const pick = async (wrapper, id) => {
                await wrapper.get('[data-testid="template-preview-with"]').setValue(id);
                await flushPromises();
            };

            it("offers the company's sections next to the sample, and starts on the first of them", async () => {
                const fetch = respond(200, ROLLCALL);
                vi.stubGlobal('fetch', fetch);
                const wrapper = real();
                const options = wrapper.findAll('[data-testid="template-preview-with"] option');

                expect(options.map((option) => option.text())).toEqual(['Sample section', '2/1/E/2-7 2nd Section, this week', '3/1/E/2-7 3rd Section, this week']);
                expect(wrapper.get('[data-testid="template-preview-with"]').element.value).toBe('7');
                // The sample stands in while the section loads.
                expect(wrapper.get('[data-testid="template-preview-post"] strong').text()).toBe('1/E/2-7');

                await flushPromises();

                expect(fetch).toHaveBeenCalledTimes(1);
                expect(fetch).toHaveBeenCalledWith('/settings/templates/preview/7?company=1', expect.anything());
                expect(wrapper.get('[data-testid="template-preview-post"] strong').text()).toBe('2/1/E/2-7');
                expect(wrapper.get('[data-testid="template-preview-note"]').text()).toContain("2/1/E/2-7's 2 troopers");
            });

            it('keeps the sample, and asks for nothing, when the company has no section the viewer can open', async () => {
                const fetch = respond(200, ROLLCALL);
                vi.stubGlobal('fetch', fetch);
                const wrapper = page({ active: { ...VERSION_2, body: TABLE } });
                await flushPromises();

                expect(fetch).not.toHaveBeenCalled();
                expect(wrapper.get('[data-testid="template-preview-post"] strong').text()).toBe('1/E/2-7');
            });

            it('offers no picker when the company has no section the viewer can open', () => {
                vi.stubGlobal('fetch', respond(200, ROLLCALL));
                expect(page().find('[data-testid="template-preview-with"]').exists()).toBe(false);
            });

            it('loads the picked section for this week and fills the preview with its troopers and marks', async () => {
                const fetch = respond(200, ROLLCALL);
                vi.stubGlobal('fetch', fetch);
                const wrapper = real();

                await pick(wrapper, '7');

                expect(fetch).toHaveBeenCalledWith('/settings/templates/preview/7?company=1', expect.objectContaining({ headers: expect.objectContaining({ Accept: 'application/json' }) }));
                const post = wrapper.get('[data-testid="template-preview-post"]');
                expect(post.get('strong').text()).toBe('2/1/E/2-7');
                expect(post.text()).toContain('03Oct26');
                expect(post.findAll('td').map((cell) => cell.text())).toEqual(['SGT Vasquez.R', 'Yes', 'PVT Lowe.K', 'LOA']);
                expect(wrapper.get('[data-testid="template-preview-note"]').text()).toContain("2/1/E/2-7's 2 troopers and their marks this week");
            });

            it("lists and labels the picked section's fireteams as its rollcall post does", async () => {
                const ROLES = '[TABLE]{{#troopers}}[TR][TD]{{role}}[/TD][TD]{{display_name}}[/TD][/TR]{{/troopers}}[/TABLE]';
                const trooper = (id, name, extra = {}) => ({ id, cavId: 10 + id, rank: 'Private', rankShort: 'PVT', rankIcon: null, name, displayName: `PVT ${name}`, rollcall: 'unsigned', status: null, role: null, leadsFireteam: false, fireteamLeaderId: null, ...extra });
                vi.stubGlobal('fetch', respond(200, {
                    ...ROLLCALL,
                    troopers: [trooper(1, 'Adams.P'), trooper(2, 'Baker.Q', { fireteamLeaderId: 3 }), trooper(3, 'Cole.R', { role: 'ftl', leadsFireteam: true })],
                    fireteams: { show: true, order: 'by_fireteam', slAslLead: false, alphabet: 'nato', can: { edit: false, editPolicy: false } },
                }));
                const wrapper = real({ active: { ...VERSION_2, body: ROLES } });

                await pick(wrapper, '7');

                // Cole.R leads Fireteam Alpha with Baker.Q under him; Adams.P is in no fireteam, so he comes last.
                expect(wrapper.get('[data-testid="template-preview-post"]').findAll('td').map((cell) => cell.text())).toEqual([
                    'FTL Alpha', 'PVT Cole.R', '', 'PVT Baker.Q', '', 'PVT Adams.P',
                ]);
            });

            it('goes back to the sample when the sample is picked again', async () => {
                vi.stubGlobal('fetch', respond(200, ROLLCALL));
                const wrapper = real();

                await pick(wrapper, '7');
                await pick(wrapper, '');

                expect(wrapper.get('[data-testid="template-preview-post"] strong').text()).toBe('1/E/2-7');
                expect(wrapper.get('[data-testid="template-preview-note"]').text()).toContain('sample section of 5 troopers');
            });

            it('keeps the sample and says why when the section cannot be loaded', async () => {
                vi.stubGlobal('fetch', respond(403));
                const wrapper = real();

                await pick(wrapper, '7');

                expect(wrapper.get('[data-testid="template-preview-section-error"]').text()).toContain('no longer in your scope');
                expect(wrapper.get('[data-testid="template-preview-post"] strong').text()).toBe('1/E/2-7');
            });

            it('shows a section that was picked last, not one whose answer came back late', async () => {
                let answerFirst;
                const fetch = vi.fn((url) =>
                    url.includes('/7?')
                        ? new Promise((resolve) => (answerFirst = resolve))
                        : Promise.resolve({ ok: true, status: 200, json: async () => ({ ...ROLLCALL, section: { ...ROLLCALL.section, designation: '3/1/E/2-7' } }) }),
                );
                vi.stubGlobal('fetch', fetch);
                const wrapper = real();

                await wrapper.get('[data-testid="template-preview-with"]').setValue('7');
                await pick(wrapper, '8');
                answerFirst({ ok: true, status: 200, json: async () => ROLLCALL });
                await flushPromises();

                expect(wrapper.get('[data-testid="template-preview-post"] strong').text()).toBe('3/1/E/2-7');
            });

            it('goes back to the sample when another company with no section in view opens', async () => {
                vi.stubGlobal('fetch', respond(200, ROLLCALL));
                const wrapper = real();

                await pick(wrapper, '7');
                await wrapper.setProps({ company: 2, previewSections: [] });

                expect(wrapper.get('[data-testid="template-preview-post"] strong').text()).toBe('1/E/2-7');
                expect(wrapper.find('[data-testid="template-preview-with"]').exists()).toBe(false);
            });

            it("starts again from the other company's first section when another company opens", async () => {
                const fetch = respond(200, ROLLCALL);
                vi.stubGlobal('fetch', fetch);
                const wrapper = real();
                await flushPromises();

                await wrapper.setProps({ company: 2, previewSections: [{ id: 30, designation: '1/1/F/2-7', name: '1st Section', platoon: '1/F/2-7' }] });
                await flushPromises();

                expect(fetch).toHaveBeenLastCalledWith('/settings/templates/preview/30?company=2', expect.anything());
                expect(wrapper.get('[data-testid="template-preview-with"]').element.value).toBe('30');
            });
        });
    });

    it('shows the placeholder reference to a non-Admin too, since they read the template', () => {
        expect(page({ isAdmin: false, canManage: false, canManageCompany: false }).find('[data-testid="syntax-help"]').exists()).toBe(true);
    });
});

describe('Settings/Templates: the company list (#375)', () => {
    const row = (wrapper, id) => wrapper.get(`[data-testid="template-row-${id}"]`);

    it('lists the regiment default and the company templates, marking the default, the company default and the ones switched off', () => {
        const wrapper = page();

        expect(row(wrapper, 5).find('[data-testid="badge-regiment-default"]').exists()).toBe(true);
        expect(row(wrapper, 7).find('[data-testid="badge-company-default"]').exists()).toBe(true);
        expect(row(wrapper, 8).find('[data-testid="badge-off"]').text()).toBe('Off for sections');
        expect(wrapper.get('[data-testid="open-template-name"]').text()).toBe('Standard');
        expect(wrapper.get('[data-testid="open-template-7"]').attributes('aria-current')).toBe('true');
    });

    it('offers the company command a switch and a company default for its own templates only, never for the regiment default', () => {
        const wrapper = page();

        expect(row(wrapper, 5).find('[data-testid="switch-5"]').exists()).toBe(false);
        expect(row(wrapper, 7).get('[data-testid="switch-7"]').text()).toBe('Switch off');
        expect(row(wrapper, 8).get('[data-testid="switch-8"]').text()).toBe('Switch on');
        expect(row(wrapper, 7).find('[data-testid="make-default-7"]').exists()).toBe(false);
        expect(row(wrapper, 8).find('[data-testid="make-default-8"]').exists()).toBe(true);
    });

    it('switches a template and makes one the company default through their own requests', async () => {
        const wrapper = page();

        await wrapper.get('[data-testid="switch-8"]').trigger('click');
        await wrapper.get('[data-testid="make-default-8"]').trigger('click');

        expect(routerPost).toHaveBeenNthCalledWith(1, '/settings/templates/8/switch', { enabled: true }, expect.objectContaining({ preserveScroll: true }));
        expect(routerPost).toHaveBeenNthCalledWith(2, '/settings/templates/8/company-active', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('hides the company controls from someone who may not manage the company', () => {
        const wrapper = page({ canManage: false, canManageCompany: false });

        expect(wrapper.find('[data-testid="new-template"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="switch-7"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="template-form"]').exists()).toBe(false);
    });

    it('shows the regiment default read-only to a company command, saying only an Admin edits it', () => {
        const wrapper = page({ template: 5, canManage: false });

        expect(wrapper.find('[data-testid="template-form"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="default-note"]').text()).toContain('Only an Admin edits it');
        expect(wrapper.find('[data-testid="new-template"]').exists()).toBe(true);
    });

    it('opens another template through the server, asking first when the editor holds unsaved text', async () => {
        vi.stubGlobal('confirm', vi.fn(() => false));
        const wrapper = page();

        formState.body = '[B]half-written[/B]';
        await wrapper.get('[data-testid="open-template-8"]').trigger('click');
        expect(routerGet).not.toHaveBeenCalled();

        formState.body = '[B]second[/B]';
        await wrapper.get('[data-testid="open-template-8"]').trigger('click');
        expect(routerGet).toHaveBeenCalledWith('/settings/templates', { company: 1, template: 8 }, { preserveScroll: true });
    });

    it('says when nothing of the company is switched on, so its sections use the regiment default', () => {
        const off = TEMPLATES.map((item) => ({ ...item, enabled: item.isRegimentDefault }));

        expect(page({ templates: off }).find('[data-testid="nothing-switched-on"]').exists()).toBe(true);
        expect(page().find('[data-testid="nothing-switched-on"]').exists()).toBe(false);
    });

    it('adds a template under a name and reason, starting from the open template', async () => {
        const wrapper = page();

        await wrapper.get('[data-testid="new-template"]').trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[data-testid="create-dialog"]')).not.toBeNull();

        createState.name = 'Training week 2';
        createState.reason = 'Second training layout';
        await nextTick();
        document.body.querySelector('[data-testid="create-confirm"]').click();
        await flushPromises();

        expect(createPost).toHaveBeenCalledWith('/settings/templates/new', expect.objectContaining({ preserveScroll: true }));
        expect(createState.company_id).toBe(1);
        expect(createState.body).toBe('[B]second[/B]');
    });

    it('keeps the company controls, disabled, while previewing', () => {
        mockPage.props = { viewAs: { kind: 'unit', designation: '2/1/A/1-7', label: 'SGT Vasquez.R', admin: true } };
        const wrapper = page({ isAdmin: false });

        expect(wrapper.get('[data-testid="new-template"]').attributes('aria-disabled')).toBe('true');
        expect(wrapper.get('[data-testid="switch-7"]').attributes('disabled')).toBeDefined();
    });
});

describe('Settings/Templates: the battalion switch (#376)', () => {
    it('says the company sets its own templates and offers battalion command the switch', async () => {
        const wrapper = page({ policy: { companyEdits: true, canSet: true } });

        expect(wrapper.get('[data-testid="template-policy-note"]').text()).toBe("The company's command sets its own templates.");
        await wrapper.get('[data-testid="template-policy-switch"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith('/settings/templates/policy/1', { company_edits: false }, expect.objectContaining({ preserveScroll: true }));
    });

    it("tells the company's command why it has no controls while the battalion has editing off", () => {
        const wrapper = page({ policy: { companyEdits: false, canSet: false }, canManage: false, canManageCompany: false });

        expect(wrapper.get('[data-testid="template-policy-note"]').text()).toContain('Battalion command has turned off');
        expect(wrapper.find('[data-testid="template-policy-switch"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="new-template"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="template-form"]').exists()).toBe(false);
    });

    it('shows nothing about the switch to a company that sets its own and a viewer who cannot change it', () => {
        expect(page().find('[data-testid="template-policy"]').exists()).toBe(false);
    });

    it('offers battalion command the switch back on', async () => {
        const wrapper = page({ policy: { companyEdits: false, canSet: true } });

        expect(wrapper.get('[data-testid="template-policy-switch"]').text()).toBe('Turn on company editing');
        await wrapper.get('[data-testid="template-policy-switch"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith('/settings/templates/policy/1', { company_edits: true }, expect.anything());
    });
});
