import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import Rollcall from '../Rollcall.vue';

const page = reactive({ url: '/rollcall/12', props: { auth: { user: { username: 'Vasquez.R', is_admin: false } }, viewAs: null } });
const push = vi.fn((visit) => (page.url = visit.url));
const visit = vi.fn();

enableAutoUnmount(afterEach);
const post = vi.fn();
const toastSuccess = vi.fn();

vi.mock('@/lib/postJson', () => ({ postJson: (...args) => post(...args) }));
const toastError = vi.fn();
const toastWarning = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args) => toastSuccess(...args),
        error: (...args) => toastError(...args),
        warning: (...args) => toastWarning(...args),
        dismiss: () => {},
    },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { push: (...args) => push(...args), visit: (...args) => visit(...args), on: () => () => {} },
    usePage: () => page,
}));

const WEEK = {
    start: '2026-10-03T00:00:00+00:00',
    date: '2026-10-03',
    closes: '2026-10-09T23:59:00+00:00',
    isCurrent: true,
    previous: '2026-09-26',
    next: null,
    current: '2026-10-03',
};
const SECTION = { id: 12, designation: '2/1/A/1-7', name: '2nd Section', platoon: '1/A/1-7' };
const SECTIONS = [SECTION, { id: 13, designation: '3/1/A/1-7', name: '3rd Section', platoon: '1/A/1-7' }];

const trooper = (id, rollcall, status, extra = {}) => ({
    id, cavId: 1000 + id, rank: 'PFC', name: `Trooper${id}.A`, rollcall, status, source: status ? 'manual' : null, priorStatus: null, ...extra,
});

function factory(props = {}) {
    return mount(Rollcall, {
        props: {
            section: SECTION,
            sections: SECTIONS,
            week: WEEK,
            troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'cannot_attend', null, { priorStatus: 'missed' }), trooper(3, 'unsigned', null)],
            priorWeekMarked: true,
            atRisk: [],
            roster: { source: 'milpac', syncedAt: '2026-10-08T09:14:00+00:00' },
            practice: { lastMarkedAt: null },
            ...props,
        },
        attachTo: document.body,
        global: { stubs: { Dialog: true, DialogContent: true, SectionPicker: { props: ['sections'], template: '<div data-picker>{{ sections.length }}</div>' } } },
    });
}

describe('Rollcall page', () => {
    beforeEach(() => {
        page.url = '/rollcall/12';
        page.props.viewAs = null;
        push.mockClear();
        post.mockReset().mockResolvedValue({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } });
        toastSuccess.mockClear();
        document.body.innerHTML = '';
    });

    it('titles the week in forum date convention and shows where the roster came from (AD-20)', () => {
        const wrapper = factory();

        expect(wrapper.find('h1').text()).toBe('Rollcall, week of 03Oct26');
        expect(wrapper.find('[data-testid="roster-provenance"]').text()).toBe('Roster from milpac, synced 08Oct26 09:14Z');
        expect(wrapper.text()).toContain('Signatures close Fri 09Oct26 23:59Z');
    });

    it('says the roster has never synced rather than inventing a time', () => {
        expect(factory({ roster: { source: 'milpac', syncedAt: null } }).find('[data-testid="roster-provenance"]').text()).toBe('Roster from milpac, not synced yet');
    });

    it('opens on step 1 with the unsigned listed first', () => {
        const wrapper = factory();
        const groups = wrapper.findAll('[role="group"][aria-label]').filter((g) => !g.attributes('aria-label').includes(' for '));

        expect(wrapper.find('h2').text()).toBe('Signatures');
        expect(groups.map((g) => g.attributes('aria-label'))).toEqual(['Unsigned', 'Cannot attend', 'Will attend']);
    });

    it('deep-links a step through the URL', async () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory();

        expect(wrapper.find('h2').text()).toBe('Section practice');
        expect(wrapper.find('[data-step="2"]').attributes('aria-current')).toBe('step');

        await wrapper.find('[data-step="3"]').trigger('click');
        expect(push).toHaveBeenCalledWith(expect.objectContaining({ url: '/rollcall/12?step=3', preserveState: true }));
    });

    it('lists "said they cannot attend" first on the practice step, and shows last week\'s mark as the pre-fill', () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory();

        expect(wrapper.find('[data-testid="prefill-banner"]').text()).toContain('Pre-filled from last week');
        const first = wrapper.find('[role="group"][aria-label="Said they cannot attend"]');
        expect(first.text()).toContain('Trooper2.A');
        expect(first.find('[data-testid="prior-mark"]').text()).toBe('Last week: Missed');
    });

    it('says nothing was pre-filled when last week has no marks', () => {
        page.url = '/rollcall/12?step=2';

        expect(factory({ priorWeekMarked: false, troopers: [trooper(3, 'unsigned', null)] }).find('[data-testid="prefill-banner"] p').text()).toBe(
            'Nothing pre-filled: no practice marks were recorded last week.',
        );
    });

    it('locks an LOA row: no status buttons, an LOA lock instead', () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory({ troopers: [trooper(4, 'unsigned', 'loa')] });

        const card = wrapper.find('[data-trooper="4"]');
        expect(card.text()).toContain('LOA, nothing to mark');
        expect(card.findAll('button[data-value]')).toHaveLength(0);
    });

    it('shows each practice mark\'s source, and the shared legend (3.9)', () => {
        page.url = '/rollcall/12?step=2';
        const wrapper = factory({
            troopers: [trooper(1, 'will_attend', 'present'), trooper(2, 'will_attend', 'present', { source: 'auto' }), trooper(3, 'will_attend', 'makeup'), trooper(5, 'will_attend', null)],
        });
        const provenance = (id) => wrapper.find(`[data-trooper="${id}"] [data-provenance]`).attributes('data-provenance');

        expect([1, 2, 3, 5].map(provenance)).toEqual(['manual', 'auto', 'makeup', 'unmarked']);
        expect(wrapper.find('[aria-label="Attendance source legend"]').text()).toContain('Auto');
    });

    it('shows the signature, not a practice source, on the signature step', () => {
        const wrapper = factory();

        expect(wrapper.find('[data-trooper="3"] [data-testid="signature-line"]').text()).toBe('Not signed yet');
        expect(wrapper.find('[data-trooper="1"] [data-testid="signature-line"]').text()).toBe('Signed: Will attend');
    });

    it('renders an empty roster as an empty state, not an error', () => {
        expect(factory({ troopers: [] }).text()).toContain('No troopers placed in this section');
    });

    it('flags a past week', () => {
        const wrapper = factory({ week: { ...WEEK, isCurrent: false, date: '2026-09-26', start: '2026-09-26T00:00:00+00:00', next: '2026-10-03' } });

        expect(wrapper.find('[data-testid="past-week-notice"]').exists()).toBe(true);
    });

    it('reviews totals and at-risk troopers on step 3', () => {
        page.url = '/rollcall/12?step=3';
        const wrapper = factory({ atRisk: [{ trooperId: 2, missed: 2, windowWeeks: 4 }] });

        expect(wrapper.find('[data-testid="review-blockers"]').text()).toContain('2 not marked for section practice: Trooper2.A, Trooper3.A');
        expect(wrapper.find('[data-testid="at-risk"]').text()).toContain('PFC Trooper2.A has missed 2 of the last 4 weeks');
    });

    it('shows the section picker when no section is chosen', () => {
        const wrapper = factory({ section: null, troopers: [] });

        expect(wrapper.find('h1').text()).toBe('Choose a section');
        expect(wrapper.find('[data-picker]').text()).toBe('2');
    });

    it('explains an empty scope instead of an empty picker', () => {
        expect(factory({ section: null, sections: [], troopers: [] }).text()).toContain('No sections in your scope');
    });

    describe('marking (3.8)', () => {
        beforeEach(() => vi.useFakeTimers());
        afterEach(() => vi.useRealTimers());

        const button = (wrapper, id, value) => wrapper.find(`[data-trooper="${id}"] button[data-value="${value}"]`);

        it('sets a signature and saves it in the background through rollcall.store', async () => {
            const wrapper = factory();

            await button(wrapper, 3, 'cannot_attend').trigger('click');
            expect(button(wrapper, 3, 'cannot_attend').attributes('aria-pressed')).toBe('true');

            await vi.advanceTimersByTimeAsync(1000);
            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 3, rollcall: 'cannot_attend' }] });
            expect(wrapper.find('[data-testid="save-state"]').text()).toBe('Saved 16:20Z');
        });

        it('offers present, missed, makeup and attached on the practice step, and records a manual source', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();

            expect(wrapper.find('[data-trooper="3"]').findAll('button[data-value]').map((b) => b.attributes('data-value'))).toEqual([
                'present', 'missed', 'makeup', 'attached',
            ]);
            await button(wrapper, 3, 'attached').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);

            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 3, status: 'attached' }] });
        });

        it('marks every unmarked, non-LOA trooper present in one action, and Undo takes it back unsent', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(1, 'will_attend', 'missed'), trooper(2, 'unsigned', null), trooper(3, 'unsigned', null), trooper(4, 'unsigned', 'loa')] });

            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');

            expect(button(wrapper, 2, 'present').attributes('aria-pressed')).toBe('true');
            expect(button(wrapper, 3, 'present').attributes('aria-pressed')).toBe('true');
            expect(button(wrapper, 1, 'missed').attributes('aria-pressed')).toBe('true');
            expect(wrapper.find('[data-trooper="4"]').text()).toContain('LOA, nothing to mark');

            const [title, options] = toastSuccess.mock.calls[0];
            expect(title).toBe('Marked 2 as present');
            await vi.advanceTimersByTimeAsync(1000);
            expect(post).not.toHaveBeenCalled();

            options.action.onClick();
            await wrapper.vm.$nextTick();
            await vi.advanceTimersByTimeAsync(7000);

            expect(button(wrapper, 2, 'present').attributes('aria-pressed')).toBe('false');
            expect(post).not.toHaveBeenCalled();
        });

        it('saves "Mark all present" once the undo window has passed', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null)] });

            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');
            await vi.advanceTimersByTimeAsync(7000);

            expect(post).toHaveBeenCalledWith('/rollcall/12', { week: '2026-10-03', marks: [{ trooper_id: 2, status: 'present' }] });
        });

        it('applies last week\'s marks to the troopers still to mark', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();

            await wrapper.find('[data-testid="use-last-week"]').trigger('click');

            expect(button(wrapper, 2, 'missed').attributes('aria-pressed')).toBe('true');
            expect(toastSuccess.mock.calls[0][0]).toBe("Used last week's marks for 1");
        });

        it('moves between cards with J and K and sets the focused one with 1-4', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();
            const faces = () => wrapper.findAll('[data-card-face]');

            faces()[0].element.focus();
            await faces()[0].trigger('keydown', { key: 'j' });
            expect(document.activeElement).toBe(faces()[1].element);

            await faces()[1].trigger('keydown', { key: 'k' });
            expect(document.activeElement).toBe(faces()[0].element);

            const id = faces()[0].element.closest('[data-trooper]').dataset.trooper;
            await faces()[0].trigger('keydown', { key: '3' });
            expect(button(wrapper, id, 'makeup').attributes('aria-pressed')).toBe('true');
        });

        it('keeps the input and offers Retry when a save fails', async () => {
            post.mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'Trooper 3 is not placed in section 12.' } });
            const wrapper = factory();

            await button(wrapper, 3, 'will_attend').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);

            expect(wrapper.find('[data-testid="save-error"]').text()).toContain('Trooper 3 is not placed in section 12.');
            expect(button(wrapper, 3, 'will_attend').attributes('aria-pressed')).toBe('true');

            await wrapper.find('[data-testid="save-error"] button').trigger('click');
            await vi.advanceTimersByTimeAsync(0);
            expect(post).toHaveBeenCalledTimes(2);
            expect(wrapper.find('[data-testid="save-error"]').exists()).toBe(false);
        });

        it('is read-only during a view-as preview', async () => {
            page.props.viewAs = { label: 'CPL Lindqvist.E', admin: false };
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();

            expect(wrapper.find('[data-testid="read-only-notice"]').text()).toContain('Read-only while viewing as CPL Lindqvist.E');
            expect(button(wrapper, 3, 'present').attributes('disabled')).toBeDefined();
            expect(button(wrapper, 3, 'present').attributes('title')).toBe('Read-only while viewing as CPL Lindqvist.E');
            expect(wrapper.find('[data-testid="mark-all-present"]').exists()).toBe(false);
        });

        it('lists this visit\'s changes on the review step', async () => {
            const wrapper = factory();
            await button(wrapper, 3, 'will_attend').trigger('click');
            await wrapper.find('[data-step="3"]').trigger('click');

            const change = wrapper.find('[data-testid="changes"] li');
            expect(change.findAll('span').map((span) => span.text())).toEqual(expect.arrayContaining(['Trooper3.A', 'Signature', 'Unsigned', 'Will attend']));
        });
    });

    describe('forum post (3.10)', () => {
        beforeEach(() => {
            page.url = '/rollcall/12?step=3';
            toastError.mockClear();
        });

        it('previews the post and keeps the BBCode in step with the marks', async () => {
            const wrapper = factory();

            expect(wrapper.find('[data-testid="forum-preview"]').text()).toContain('Trooper3.A');
            expect(wrapper.find('[data-testid="forum-post"]').text()).toContain('2/1/A/1-7 Rollcall, week of 03Oct26');

            page.url = '/rollcall/12?step=2';
            await wrapper.vm.$nextTick();
            await wrapper.find('[data-trooper="3"] button[data-value="missed"]').trigger('click');
            page.url = '/rollcall/12?step=3';
            await wrapper.vm.$nextTick();

            const row = wrapper.findAll('[data-testid="forum-preview"] tbody tr').find((tr) => tr.text().includes('Trooper3.A'));
            expect(row.text()).toContain('Missed');
        });

        it('copies the generated BBCode to the clipboard, with no request to the server (AD-20)', async () => {
            const writeText = vi.fn().mockResolvedValue();
            Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
            const wrapper = factory();

            await wrapper.find('[data-testid="copy-bbcode"]').trigger('click');
            await vi.waitFor(() => expect(writeText).toHaveBeenCalled());

            expect(writeText.mock.calls[0][0]).toMatch(/^\[b\]2\/1\/A\/1-7 Rollcall, week of 03Oct26\[\/b\]/);
            expect(writeText.mock.calls[0][0]).toContain('[tr][td]PFC[/td][td]Trooper2.A[/td][td]Cannot attend[/td][td]Not marked[/td][/tr]');
            expect(post).not.toHaveBeenCalled();
            await vi.waitFor(() => expect(wrapper.find('[data-testid="copy-bbcode"]').text()).toBe('Copy again'));
        });

        it('says so when the clipboard is unavailable', async () => {
            Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockRejectedValue(new Error('denied')) }, configurable: true });
            const wrapper = factory();

            await wrapper.find('[data-testid="copy-bbcode"]').trigger('click');
            await vi.waitFor(() => expect(toastError).toHaveBeenCalledWith('Could not copy the BBCode', expect.anything()));
        });

        it('marks the rollcall posted and shows when and by whom', async () => {
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } } });
            const wrapper = factory();

            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(true));

            expect(post).toHaveBeenCalledWith('/rollcall/12/posted', { week: '2026-10-03' });
            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Rollcall posted 08Oct26 16:20Z by Vasquez.R');
            expect(wrapper.find('[data-step="3"]').attributes('aria-label')).toBe('Step 3, Review and post, Posted');
            expect(wrapper.find('[data-testid="mark-posted"]').text()).toBe('Mark posted again');
        });

        it('saves pending marks before marking posted', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();
            await wrapper.find('[data-trooper="3"] button[data-value="present"]').trigger('click');
            page.url = '/rollcall/12?step=3';
            await wrapper.vm.$nextTick();
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } })
                .mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:21:00+00:00', by: 'Vasquez.R' } } });

            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(2));

            expect(post.mock.calls[0][0]).toBe('/rollcall/12');
            expect(post.mock.calls[1][0]).toBe('/rollcall/12/posted');
        });

        it('flags a posted rollcall whose marks changed since, so it gets reposted', async () => {
            const wrapper = factory({ practice: { lastMarkedAt: '2026-10-08T17:00:00+00:00', posted: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } } });

            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Repost the updated BBCode');
            expect(wrapper.find('[data-step="3"]').attributes('aria-label')).toBe('Step 3, Review and post, Not posted yet');
        });

        it('cannot be marked posted during a view-as preview', () => {
            page.props.viewAs = { label: 'CPL Lindqvist.E', admin: false };

            expect(factory().find('[data-testid="mark-posted"]').attributes('disabled')).toBeDefined();
        });
    });

    describe('failure paths (review)', () => {
        beforeEach(() => {
            toastError.mockClear();
            toastWarning.mockClear();
        });

        it('does not mark posted when the pending marks fail to save', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory();
            await wrapper.find('[data-trooper="3"] button[data-value="present"]').trigger('click');
            await wrapper.find('[data-step="3"]').trigger('click');
            post.mockResolvedValueOnce({ ok: false, status: 500, data: null });

            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(toastError).toHaveBeenCalledWith('Changes are not saved yet', expect.anything()));

            expect(post).toHaveBeenCalledTimes(1);
            expect(post.mock.calls[0][0]).toBe('/rollcall/12');
            expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(false);
        });

        it('shows the server\'s reason when marking posted is refused', async () => {
            page.url = '/rollcall/12?step=3';
            post.mockResolvedValueOnce({ ok: false, status: 403, data: { message: 'Outside your scope.' } });
            const wrapper = factory();

            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(toastError).toHaveBeenCalledWith('Could not mark the rollcall posted', { description: 'Outside your scope.' }));
        });

        it('flags the post as stale once a mark changes after marking it posted', async () => {
            page.url = '/rollcall/12?step=3';
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:20:00+00:00', by: 'Vasquez.R' } } });
            const wrapper = factory();
            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(wrapper.find('[data-testid="posted-state"]').exists()).toBe(true));

            await wrapper.find('[data-step="2"]').trigger('click');
            await wrapper.find('[data-trooper="3"] button[data-value="missed"]').trigger('click');
            await wrapper.find('[data-step="3"]').trigger('click');

            expect(wrapper.find('[data-testid="posted-state"]').text()).toContain('Repost the updated BBCode');
            expect(wrapper.find('[data-step="3"]').attributes('aria-label')).toBe('Step 3, Review and post, Not posted yet');
        });

        it('warns when Undo comes too late because marking posted already saved the marks', async () => {
            page.url = '/rollcall/12?step=2';
            const wrapper = factory({ troopers: [trooper(2, 'unsigned', null)] });
            await wrapper.find('[data-testid="mark-all-present"]').trigger('click');
            const undo = toastSuccess.mock.calls[0][1].action.onClick;
            await wrapper.find('[data-step="3"]').trigger('click');
            post.mockResolvedValueOnce({ ok: true, status: 200, data: { savedAt: '2026-10-08T16:20:00+00:00', atRisk: [] } })
                .mockResolvedValueOnce({ ok: true, status: 200, data: { posted: { at: '2026-10-08T16:21:00+00:00', by: 'Vasquez.R' } } });
            await wrapper.find('[data-testid="mark-posted"]').trigger('click');
            await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(2));

            undo();
            expect(toastWarning).toHaveBeenCalledWith('Some marks were already saved', expect.anything());
            await wrapper.find('[data-step="2"]').trigger('click');
            expect(wrapper.find('[data-trooper="2"] button[data-value="present"]').attributes('aria-pressed')).toBe('true');
        });

        it('names troopers whose marks were refused because they left the section', async () => {
            vi.useFakeTimers();
            post.mockResolvedValueOnce({ ok: false, status: 422, data: { message: 'not placed', rejected: [3] } });
            const wrapper = factory();

            await wrapper.find('[data-trooper="3"] button[data-value="will_attend"]').trigger('click');
            await vi.advanceTimersByTimeAsync(1000);

            expect(wrapper.find('[data-testid="save-rejected"]').text()).toContain('Trooper3.A is no longer placed in this section');
            vi.useRealTimers();
        });
    });
});
