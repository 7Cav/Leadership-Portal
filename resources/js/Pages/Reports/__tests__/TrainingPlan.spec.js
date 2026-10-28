import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TrainingPlan from '../TrainingPlan.vue';

const put = vi.fn();
const post = vi.fn();
let pageProps = {};

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: pageProps }),
    router: {
        put: (...args) => put(...args),
        post: (...args) => post(...args),
    },
}));

vi.mock('vue-sonner', async (importOriginal) => ({
    ...(await importOriginal()),
    toast: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}));

const WEEKS = [
    { start: '2026-11-07', subject: null, locked: null, reportUrl: '/reports/2/2026-11-07' },
    { start: '2026-11-14', subject: null, locked: null, reportUrl: null },
    { start: '2026-11-21', subject: null, locked: null, reportUrl: null },
    { start: '2026-11-28', subject: null, locked: null, reportUrl: null },
];

function props(overrides = {}) {
    return {
        unit: { id: 2, name: '1st Platoon', callsign: '1/A/1-7', type: 'platoon' },
        month: { value: '2026-11', label: 'November 2026', previous: '2026-10', next: '2026-12' },
        weeks: WEEKS,
        plan: null,
        canEdit: true,
        canReview: false,
        company: { id: 1, name: 'Alpha Company', callsign: 'A/1-7', type: 'company' },
        platoons: null,
        ...overrides,
    };
}

const mounted = [];
const mountPage = (overrides) => {
    const wrapper = mount(TrainingPlan, { props: props(overrides), attachTo: document.body });
    mounted.push(wrapper);

    return wrapper;
};

const pending = {
    status: 'pending',
    enforced: false,
    revision: 3,
    writtenBy: 'SFC Hale.J',
    writtenAt: '2026-11-11T12:00:00+00:00',
    reviewedBy: null,
    reviewedAt: null,
    comment: null,
};

beforeEach(() => {
    pageProps = {};
    put.mockReset();
    post.mockReset();
});

afterEach(() => {
    for (const wrapper of mounted.splice(0)) wrapper.unmount();
    document.body.innerHTML = '';
});

describe('Reports/TrainingPlan', () => {
    it('lets the platoon leader fill in the weeks and send the plan for approval', async () => {
        const wrapper = mountPage();

        expect(wrapper.get('[data-testid="plan-status"]').text()).toBe('Not written');
        expect(wrapper.get('[data-testid="plan-send"]').attributes('disabled')).toBeDefined();

        await wrapper.get('#week-2026-11-07').setValue('Room clearing');
        await wrapper.get('#week-2026-11-21').setValue('Radio procedure');
        await wrapper.get('[data-testid="plan-send"]').trigger('click');

        expect(put).toHaveBeenCalledWith(
            '/reports/2/plans/2026-11',
            { subjects: { '2026-11-07': 'Room clearing', '2026-11-14': '', '2026-11-21': 'Radio procedure', '2026-11-28': '' }, enforced: false },
            expect.any(Object),
        );
    });

    it('keeps a locked week read-only and says why', () => {
        const wrapper = mountPage({ weeks: [{ ...WEEKS[0], subject: 'Sent subject', locked: 'sent' }, ...WEEKS.slice(1)] });

        expect(wrapper.get('#week-2026-11-07').attributes('disabled')).toBeDefined();
        expect(wrapper.get('#week-2026-11-07').element.value).toBe('Sent subject');
        expect(wrapper.text()).toContain('report is sent, so its subject stays as it was');
        expect(wrapper.get('#week-2026-11-14').attributes('disabled')).toBeUndefined();
    });

    it('shows a plan sent back with its comment, and lets the leader send it again unchanged', async () => {
        const wrapper = mountPage({
            weeks: [{ ...WEEKS[0], subject: 'Room clearing' }, ...WEEKS.slice(1)],
            plan: { ...pending, status: 'returned', reviewedBy: 'CPT Reyes.M', reviewedAt: '2026-11-12T09:30:00+00:00', comment: 'Add a week on radios' },
        });

        expect(wrapper.get('[data-testid="plan-status"]').text()).toBe('Sent back');
        expect(wrapper.get('[data-testid="plan-comment"]').text()).toBe('Add a week on radios');
        expect(wrapper.get('[data-testid="plan-history"]').text()).toContain('Sent back by CPT Reyes.M');

        await wrapper.get('[data-testid="plan-send"]').trigger('click');
        expect(put).toHaveBeenCalledOnce();
    });

    it('offers the company commander approval with the revision they read, and a send-back that needs a comment', async () => {
        const wrapper = mountPage({ plan: pending, canEdit: false, canReview: true, weeks: [{ ...WEEKS[0], subject: 'Room clearing' }, ...WEEKS.slice(1)] });

        expect(wrapper.find('[data-testid="plan-send"]').exists()).toBe(false);
        expect(wrapper.get('#week-2026-11-07').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="plan-review"]').text()).toContain('Review for A/1-7');

        await wrapper.get('[data-testid="plan-approve"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/reports/2/plans/2026-11/approve', { revision: 3 }, expect.any(Object));

        expect(wrapper.get('[data-testid="plan-return"]').attributes('disabled')).toBeDefined();
    });

    it('sends a plan back with the comment', async () => {
        post.mockImplementation((url, data, options) => options.onFinish?.());
        const wrapper = mountPage({ plan: pending, canEdit: false, canReview: true });

        await wrapper.get('#plan-return-comment').setValue('  Add a week on radios ');
        await wrapper.get('[data-testid="plan-return"]').trigger('click');

        expect(post).toHaveBeenCalledWith('/reports/2/plans/2026-11/return', { revision: 3, comment: 'Add a week on radios' }, expect.any(Object));
    });

    it('offers no review once the plan is approved', () => {
        const wrapper = mountPage({ plan: { ...pending, status: 'approved' }, canReview: true });

        expect(wrapper.find('[data-testid="plan-review"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="plan-next-step"]').text()).toContain('sends it for approval again');
    });

    it('shows a refusal from the server as an error on the plan', async () => {
        put.mockImplementation((url, data, options) => {
            options.onError({ plan: 'Give at least one week a training subject.' });
            options.onFinish();
        });
        const wrapper = mountPage();

        await wrapper.get('#week-2026-11-07').setValue('Room clearing');
        await wrapper.get('[data-testid="plan-send"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="plan-error"]').text()).toBe('Give at least one week a training subject.');
    });

    it('lists the platoons above a platoon, with where each plan stands', () => {
        const wrapper = mountPage({
            unit: { id: 1, name: 'Alpha Company', callsign: 'A/1-7', type: 'company' },
            weeks: null,
            platoons: [
                { id: 2, name: '1st Platoon', callsign: '1/A/1-7', type: 'platoon', status: 'pending', weeksPlanned: 2 },
                { id: 3, name: '2nd Platoon', callsign: '2/A/1-7', type: 'platoon', status: 'none', weeksPlanned: 0 },
            ],
        });

        expect(wrapper.get('[data-testid="platoon-2"]').text()).toContain('Waiting for approval');
        expect(wrapper.get('[data-testid="platoon-2"] a').attributes('href')).toBe('/reports/2/plans/2026-11');
        expect(wrapper.get('[data-testid="platoon-3"]').text()).toContain('Not written');
        expect(wrapper.find('[data-testid="plan-status"]').exists()).toBe(false);
    });
});
