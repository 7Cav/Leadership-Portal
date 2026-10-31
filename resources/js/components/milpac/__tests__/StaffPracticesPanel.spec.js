import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import StaffPracticesPanel from '../StaffPracticesPanel.vue';

// #448: the staff practices panel draws what the server sent and posts the forms; it decides nothing about who may.

const sent = vi.fn();
const posted = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { reactive } = await import('vue');

    return {
        ...(await importOriginal()),
        router: { post: (url, data, options) => posted(url, data, options) },
        useForm: (data) => {
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                clearErrors() {
                    this.errors = {};
                },
                reset() {
                    Object.assign(this, data);
                },
            });
            form.post = (url, options) => sent(url, { section_id: form.section_id, week: form.week }, options);

            return form;
        },
    };
});

const entry = (overrides = {}) => ({
    week: '2026-10-03',
    sectionId: 4,
    section: '1/1/A/1-7',
    source: 'entered',
    enteredBy: 'Mercer.J',
    selfReported: false,
    removable: false,
    ...overrides,
});

const panel = (overrides = {}) =>
    mount(StaffPracticesPanel, {
        props: {
            trooperId: 7,
            staffPractices: {
                entries: [],
                canRecord: false,
                sections: [],
                weeks: [],
                ...overrides,
            },
        },
    });

beforeEach(() => {
    sent.mockReset();
    posted.mockReset();
});

describe('Staff practices panel', () => {
    it('lists each practice with where it came from, a self-report named as one', () => {
        const wrapper = panel({
            entries: [
                entry({ enteredBy: 'Brandt.L', selfReported: true }),
                entry({ week: '2026-09-26', sectionId: 5, section: '2/1/A/1-7', enteredBy: 'Mercer.J' }),
                entry({ week: '2026-09-19', source: 'capture', enteredBy: null }),
            ],
        });

        const rows = wrapper.findAll('[data-testid="staff-practice"]');
        expect(rows).toHaveLength(3);
        expect(rows[0].text()).toContain('03OCT26 to 09OCT26, 1/1/A/1-7');
        expect(rows[0].text()).toContain('Self-reported by Brandt.L');
        expect(rows[1].text()).toContain('Entered by Mercer.J');
        expect(rows[2].text()).toContain('Seen on the game server');
        expect(wrapper.find('[data-testid="staff-practices-count"]').text()).toBe('3');
    });

    it('says when there is nothing on record, and offers no form to a viewer who may not add one', () => {
        const wrapper = panel();

        expect(wrapper.find('[data-testid="staff-practices-empty"]').text()).toBe('No section practices on record.');
        expect(wrapper.find('[data-testid="staff-practice-open"]').exists()).toBe(false);
    });

    it('posts the picked section and week, defaulting to this week', async () => {
        const wrapper = panel({
            canRecord: true,
            sections: [{ id: 4, label: '1/1/A/1-7' }, { id: 5, label: '2/1/A/1-7' }],
            weeks: ['2026-10-03', '2026-09-26'],
        });

        await wrapper.find('[data-testid="staff-practice-open"]').trigger('click');
        expect(wrapper.find('[data-testid="staff-practice-week"]').element.value).toBe('2026-10-03');
        expect(wrapper.find('[data-testid="staff-practice-week"]').text()).toContain('03OCT26 to 09OCT26 (this week)');
        expect(wrapper.find('[data-testid="staff-practice-submit"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="staff-practice-section"]').setValue('5');
        await wrapper.find('[data-testid="staff-practice-week"]').setValue('2026-09-26');
        await wrapper.find('[data-testid="staff-practice-form"]').trigger('submit');

        expect(sent).toHaveBeenCalledWith('/troopers/7/staff-practices', { section_id: 5, week: '2026-09-26' }, expect.anything());
    });

    it('shows a refusal on its field', async () => {
        const wrapper = panel({ canRecord: true, sections: [{ id: 4, label: '1/1/A/1-7' }], weeks: ['2026-10-03'] });
        await wrapper.find('[data-testid="staff-practice-open"]').trigger('click');

        wrapper.vm.$.setupState.form.errors = { section_id: 'Brandt.L is already recorded at that practice.' };
        await nextTick();

        expect(wrapper.find('[data-testid="staff-practice-error-section"]').text()).toBe('Brandt.L is already recorded at that practice.');
    });

    it('removes only an entry the server marked removable, by its section and week', async () => {
        const wrapper = panel({
            entries: [entry({ removable: true }), entry({ week: '2026-09-19', source: 'capture', enteredBy: null })],
        });

        const buttons = wrapper.findAll('[data-testid="staff-practice-remove"]');
        expect(buttons).toHaveLength(1);

        await buttons[0].trigger('click');
        expect(posted).toHaveBeenCalledWith('/troopers/7/staff-practices/remove', { section_id: 4, week: '2026-10-03' }, expect.anything());
    });
});
