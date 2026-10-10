import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import Templates from '../Templates.vue';

const post = vi.fn();
const routerGet = vi.fn();
const routerPost = vi.fn();
let formState;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    router: { get: (...args) => routerGet(...args), post: (...args) => routerPost(...args) },
    useForm: (initial) => {
        formState = reactive({ ...initial, processing: false, errors: {}, post, reset: vi.fn(), clearErrors: vi.fn() });

        return formState;
    },
}));

afterEach(() => {
    vi.unstubAllGlobals();
    post.mockReset();
    routerGet.mockReset();
    routerPost.mockReset();
});

const COMPANIES = [
    { id: 1, name: 'Echo Company', callsign: 'E/2-7' },
    { id: 2, name: 'Foxtrot Company', callsign: null },
];
const VERSION_2 = { id: 22, version: 2, body: '[B]second[/B]', createdAt: '2026-10-08T09:30:00+00:00', createdBy: 'Pike.S' };
const VERSION_1 = { id: 21, version: 1, body: '[B]first[/B]', createdAt: '2026-10-01T09:30:00+00:00', createdBy: 'Pike.S' };

const SYNTAX = {
    values: ['unit', 'week'],
    rowValues: ['n', 'name'],
    rowFlags: ['sp_present', 'rc_yes'],
    filters: ['upper', 'lower'],
};
const STARTER = '[B]{{unit}}[/B]{{#troopers}}{{name}}{{/troopers}}';

const props = (overrides = {}) => ({
    artifactType: { value: 'rollcall_bbcode', label: 'Rollcall BBCode' },
    companies: COMPANIES,
    company: 1,
    active: VERSION_2,
    history: [{ ...VERSION_2, isActive: true }, { ...VERSION_1, isActive: false }],
    maxBodyLength: 20000,
    syntax: SYNTAX,
    starter: STARTER,
    isAdmin: true,
    ...overrides,
});

const page = (overrides = {}) => mount(Templates, { props: props(overrides) });

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

        expect(post).toHaveBeenCalledWith('/settings/templates', { preserveScroll: true });
    });

    it('asks for a reason before making an older version active, and cancelling does nothing', () => {
        const prompt = vi.fn(() => null);
        vi.stubGlobal('prompt', prompt);
        const wrapper = page();

        wrapper.get('[data-testid="activate-21"]').trigger('click');

        expect(prompt).toHaveBeenCalledOnce();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('refuses a blank reason without sending anything', async () => {
        vi.stubGlobal('prompt', vi.fn(() => '   '));
        const wrapper = page();

        await wrapper.get('[data-testid="activate-21"]').trigger('click');

        expect(routerPost).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="activate-error"]').text()).toContain('A reason is required');
    });

    it('sends the reason when making an older version active', async () => {
        vi.stubGlobal('prompt', vi.fn(() => 'The second edit broke the table'));
        const wrapper = page();

        await wrapper.get('[data-testid="activate-21"]').trigger('click');

        expect(routerPost).toHaveBeenCalledWith('/settings/templates/21/activate', { reason: 'The second edit broke the table' }, expect.objectContaining({ preserveScroll: true }));
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
        expect(formState.body).toBe(STARTER);
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

    it('starts an Admin with no template from the starter template, not a blank box', () => {
        const wrapper = page({ active: null, history: [] });

        expect(wrapper.get('[data-testid="template-body"]').element.value).toBe(STARTER);
    });

    it('lists every placeholder the server accepts, so an Admin does not have to guess', () => {
        const wrapper = page();

        expect(wrapper.get('[data-testid="syntax-values"]').text()).toContain('{{unit}}');
        expect(wrapper.get('[data-testid="syntax-values"]').text()).toContain('{{week}}');
        expect(wrapper.get('[data-testid="syntax-row-values"]').text()).toContain('{{name}}');
        expect(wrapper.get('[data-testid="syntax-row-flags"]').text()).toContain('{{#sp_present}}...{{/sp_present}}');
        expect(wrapper.get('[data-testid="syntax-help"]').text()).toContain('{{name|upper}}');
    });

    it('shows the placeholder reference to a non-Admin too, since they read the template', () => {
        expect(page({ isAdmin: false }).find('[data-testid="syntax-help"]').exists()).toBe(true);
    });
});
