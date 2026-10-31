import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Appeals from '../Appeals.vue';

// #565: the appeals queue draws the LORs appealed to the viewer and posts a decision; the decide route judges it again.

const sent = vi.fn();
const downloaded = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));
vi.mock('@/lib/counselingPdf', () => ({ downloadCounselingDocument: (...args) => downloaded(...args) }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        Head: { template: '<head></head>' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (data) => {
            let transform = (payload) => payload;
            const form = reactive({
                ...JSON.parse(JSON.stringify(data)),
                errors: {},
                processing: false,
                clearErrors() {
                    this.errors = {};
                },
                setError(field, message) {
                    this.errors[field] = message;
                },
                transform(callback) {
                    transform = callback;

                    return this;
                },
            });
            form.post = (url, options) => {
                sent('post', url, transform(JSON.parse(JSON.stringify({ outcome: form.outcome, reason: form.reason, form: form.form }))));
                options?.onSuccess?.();
            };

            return form;
        },
    };
});

const appeal = (overrides = {}) => ({
    id: 42,
    type: 'lor',
    typeLabel: 'Letter of Reprimand',
    state: 'appealed',
    issuer: 'Varga.T',
    form: { infraction: 'Missed section practice on 10, 17 and 24 Oct.', consequences: ['nfa'], removal: null, special_punishments: '' },
    appeal: { at: '2026-10-30T09:12:00Z', reason: 'My shifts changed in October.', dueAt: '2026-11-02T09:12:00Z', pending: true, decided: null },
    trooper: { id: 7, name: 'Okafor.D', rank: 'PFC', billet: 'Rifleman' },
    canDecide: true,
    ...overrides,
});

const decidedOne = appeal({
    id: 43,
    state: 'decided',
    trooper: { id: 8, name: 'Haddad.S', rank: 'CPL', billet: 'Rifleman' },
    appeal: {
        at: '2026-10-21T11:02:00Z',
        reason: 'x',
        dueAt: '2026-10-24T11:02:00Z',
        pending: false,
        decided: { at: '2026-10-22T10:00:00Z', outcome: 'not_amended', outcomeLabel: 'Have not amended', reason: 'Stands.', by: 'Halvorsen.R' },
    },
    canDecide: false,
});

const page = (overrides = {}) =>
    mount(Appeals, { props: { appeals: [appeal(), decidedOne], pm: { allowed: true, consentUrl: '/auth/forum-pm' }, ...overrides }, attachTo: document.body });

describe('Counseling/Appeals', () => {
    beforeEach(() => {
        sent.mockReset();
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-31T00:00:00Z'));
    });
    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('splits the queue into waiting and decided, with the decide-by clock', () => {
        const wrapper = page();

        expect(wrapper.find('[data-testid="tab-waiting"]').text()).toContain('1');
        expect(wrapper.find('[data-testid="tab-decided"]').text()).toContain('1');
        const row = wrapper.find('[data-testid="appeal-row"]');
        expect(row.text()).toContain('PFC Okafor.D');
        expect(row.text()).toContain('30 days no favorable action');
        expect(row.text()).toContain('2 Nov 0912Z · 57 h');
    });

    it('says when nothing waits', () => {
        expect(page({ appeals: [decidedOne] }).find('[data-testid="appeals-waiting"]').text()).toContain('No appeals waiting');
    });

    it('decides "Have not amended" with the required reason', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="appeal-decide"]').trigger('click');
        await flushPromises();

        const sheet = document.querySelector('[data-testid="decide-sheet"]');
        expect(sheet.querySelector('[data-testid="decide-reason-quote"]').textContent).toBe('My shifts changed in October.');
        expect(sheet.textContent).toContain('Missed section practice on 10, 17 and 24 Oct.');

        document.querySelector('#decide-form').dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(sent).not.toHaveBeenCalled();
        expect(document.querySelector('[data-testid="decide-reason-error"]').textContent).toBe('Give the reason for your decision.');

        const reason = document.querySelector('[data-testid="decide-reason"]');
        reason.value = 'The letter stands.';
        reason.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector('#decide-form').dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(sent).toHaveBeenCalledWith('post', '/counseling/42/decide', {
            outcome: 'not_amended',
            reason: 'The letter stands.',
            form: { consequences: ['nfa'], removal: null, special_punishments: '' },
        });
    });

    it('amends the consequence when the authority has amended', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="appeal-decide"]').trigger('click');
        await flushPromises();

        document.querySelector('[data-testid="decide-amended"]').click();
        await flushPromises();
        document.querySelector('[data-testid="decide-consequence-nfa"]').click();
        await flushPromises();
        document.querySelector('[data-testid="decide-consequence-removal"]').click();
        await flushPromises();
        document.querySelector('[data-testid="decide-removal-assistant_section_leader"]').click();
        const reason = document.querySelector('[data-testid="decide-reason"]');
        reason.value = 'Removal fits better.';
        reason.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector('#decide-form').dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(sent).toHaveBeenCalledWith('post', '/counseling/42/decide', {
            outcome: 'amended',
            reason: 'Removal fits better.',
            form: { consequences: ['removal'], removal: 'assistant_section_leader', special_punishments: '' },
        });
    });

    it('asks for PM permission before a decision can be recorded', async () => {
        const wrapper = page({ pm: { allowed: false, consentUrl: '/auth/forum-pm?return=%2Fcounseling%2Fappeals' } });

        expect(wrapper.find('[data-testid="pm-consent-link"]').attributes('href')).toBe('/auth/forum-pm?return=%2Fcounseling%2Fappeals');
        await wrapper.find('[data-testid="appeal-decide"]').trigger('click');
        await flushPromises();
        expect(document.querySelector('[data-testid="decide-submit"]').disabled).toBe(true);
    });

    it('offers Decide only where the server allows it', () => {
        expect(page({ appeals: [appeal({ canDecide: false })] }).find('[data-testid="appeal-decide"]').exists()).toBe(false);
    });

    it('opens the full letter as a PDF from the sheet', async () => {
        const wrapper = page();
        await wrapper.find('[data-testid="appeal-decide"]').trigger('click');
        await flushPromises();
        document.querySelector('[data-testid="decide-pdf"]').click();

        expect(downloaded).toHaveBeenCalledWith(42);
    });
});
