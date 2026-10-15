import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import Templates from '../Templates.vue';
import { FILTERS, LISTS, ROW_FLAGS, ROW_STRINGS, TOP_STRINGS } from '@/lib/bbcode';

const post = vi.fn();
const toastError = vi.fn();
const routerGet = vi.fn();
const routerPost = vi.fn();
let formState;
const mockPage = { url: '/settings/templates', props: {} };

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { get: (...args) => routerGet(...args), post: (...args) => routerPost(...args) },
    usePage: () => mockPage,
    useForm: (initial) => {
        formState = reactive({ ...initial, processing: false, errors: {}, post, reset: vi.fn(), clearErrors: vi.fn() });

        return formState;
    },
}));

vi.mock('vue-sonner', () => ({ toast: { error: (...args) => toastError(...args) } }));

afterEach(() => {
    mockPage.props = {};
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    post.mockReset();
    toastError.mockReset();
    routerGet.mockReset();
    routerPost.mockReset();
});

const COMPANIES = [
    { id: 1, name: 'Echo Company', callsign: 'E/2-7' },
    { id: 2, name: 'Foxtrot Company', callsign: null },
];
const VERSION_2 = { id: 22, version: 2, body: '[B]second[/B]', createdAt: '2026-10-08T09:30:00+00:00', createdBy: 'Pike.S' };
const VERSION_1 = { id: 21, version: 1, body: '[B]first[/B]', createdAt: '2026-10-01T09:30:00+00:00', createdBy: 'Pike.S' };

const STARTER = '[B]{{unit}}[/B]{{#troopers}}{{name}}{{/troopers}}';

const props = (overrides = {}) => ({
    artifactType: { value: 'rollcall_bbcode', label: 'Rollcall BBCode' },
    companies: COMPANIES,
    company: 1,
    active: VERSION_2,
    history: [{ ...VERSION_2, isActive: true }, { ...VERSION_1, isActive: false }],
    maxBodyLength: 20000,
    starter: STARTER,
    isAdmin: true,
    ...overrides,
});

const page = (overrides = {}) => mount(Templates, { props: props(overrides), attachTo: document.body });
const dialog = () => document.body.querySelector('[data-testid="activate-dialog"]');
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

    it('lets a non-Admin read a version too, since reading changes nothing', async () => {
        const wrapper = page({ isAdmin: false });

        await wrapper.get('[data-testid="view-21"]').trigger('click');

        expect(wrapper.get('[data-testid="version-body-21"]').text()).toBe('[B]first[/B]');
    });

    it('keeps the controls, disabled with the reason, while an Admin is viewing as a leader', async () => {
        mockPage.props = { viewAs: { kind: 'unit', designation: '2/1/A/1-7', label: 'SGT Vasquez.R', admin: true } };
        const wrapper = page({ isAdmin: false });

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
        const wrapper = page({ isAdmin: false });

        expect(wrapper.find('[data-testid="template-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="activate-21"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="template-readonly"]').text()).toBe('[B]second[/B]');
    });

    it('says plainly when a company has no template yet, and offers an Admin the first one', () => {
        const wrapper = page({ active: null, history: [] });

        expect(wrapper.get('[data-testid="no-active"]').text()).toContain('no template yet');
        expect(wrapper.get('[data-testid="no-active"]').text()).toContain('Write the first one');
        expect(wrapper.get('[data-testid="no-versions"]').exists()).toBe(true);
        expect(formState.body).toBe('');
    });

    it('tells a non-Admin an Admin can write one', () => {
        const wrapper = page({ active: null, history: [], isAdmin: false });

        expect(wrapper.get('[data-testid="no-active"]').text()).toContain('An Admin can write one here');
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
        formState.errors = { body: 'A template needs some text.', reason: 'The reason field is required.', company_id: 'Unit 4 is not a company.' };

        return wrapper.vm.$nextTick().then(() => {
            expect(wrapper.get('[data-testid="error-body"]').text()).toBe('A template needs some text.');
            expect(wrapper.get('[data-testid="error-reason"]').text()).toBe('The reason field is required.');
            expect(wrapper.get('[data-testid="error-company_id"]').text()).toBe('Unit 4 is not a company.');
        });
    });

    it('caps the editor at the server\'s limit', () => {
        expect(page().get('[data-testid="template-body"]').attributes('maxlength')).toBe('20000');
    });

    it('starts an Admin with no template from a blank editor, and fills the example only on request', async () => {
        const wrapper = page({ active: null, history: [] });

        expect(wrapper.get('[data-testid="template-body"]').element.value).toBe('');
        await wrapper.get('[data-testid="use-starter"]').trigger('click');
        expect(wrapper.get('[data-testid="template-body"]').element.value).toBe(STARTER);
    });

    it('offers no starter once the company has a template', () => {
        expect(page().find('[data-testid="use-starter"]').exists()).toBe(false);
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
                expect(entry.get('dt').text()).toBe(code(name));
                const [meaning, example] = entry.get('dd').text().split('Example:');
                expect(meaning.trim()).toMatch(/^[A-Z].{5,}\.$/);
                expect(example.trim()).not.toBe('');
            }
        });

        it('explains the rank icon and shows how to print it only when Cav has one', () => {
            const entry = page().get('[data-testid="legend-entry-rank_icon"]');

            expect(entry.text()).toContain('rank insignia');
            expect(entry.text()).toContain('{{#rank_icon}}[IMG]{{rank_icon}}[/IMG]{{/rank_icon}}');
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
    });

    it('shows the placeholder reference to a non-Admin too, since they read the template', () => {
        expect(page({ isAdmin: false }).find('[data-testid="syntax-help"]').exists()).toBe(true);
    });
});
