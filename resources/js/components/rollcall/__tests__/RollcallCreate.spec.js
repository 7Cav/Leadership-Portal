import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import RollcallCreate from '../RollcallCreate.vue';

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/lib/postJson', () => ({ postJson: vi.fn() }));

import { toast } from 'vue-sonner';
import { postJson } from '@/lib/postJson';

enableAutoUnmount(afterEach);

// Wednesdays 2300Z; this week (14NOV26 to 20NOV26) on the slot.
const SLOT = { weekday: 3, startMinute: 1380, effectiveFrom: null, week: { start: '2026-11-18T23:00:00Z', end: '2026-11-19T00:00:00Z', cancelled: false, moved: false, captured: false } };
const TRAINING = { subject: null, enforced: false, platoon: null, canEdit: true };
const ANSWER = { flow: { version: 2, created: { at: '2026-11-14T18:00:00+00:00', by: 'Practice.L' } }, training: TRAINING, practiceSlot: SLOT };
const factory = (props = {}) => mount(RollcallCreate, { props: { sectionId: 7, week: '2026-11-14', slot: SLOT, training: TRAINING, editable: true, ...props } });

describe('RollcallCreate (#703)', () => {
    beforeEach(() => {
        postJson.mockReset().mockResolvedValue({ ok: true, status: 200, data: ANSWER });
        toast.error.mockReset();
    });

    it('prefills the date from the slot, offering the days of this Cav week only', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="create-day"]').element.value).toBe('3');
        expect(wrapper.get('[data-testid="create-time"]').element.value).toBe('23:00');
        expect(wrapper.findAll('[data-testid="create-day"] option').map((o) => o.text())).toEqual([
            'Sat 14NOV26', 'Sun 15NOV26', 'Mon 16NOV26', 'Tue 17NOV26', 'Wed 18NOV26', 'Thu 19NOV26', 'Fri 20NOV26',
        ]);
        expect(wrapper.text()).toContain('Changing it affects this week only.');
    });

    it('names the topic field in its placeholder, with no label above it', () => {
        const input = factory().get('[data-testid="create-topic"]');

        expect(input.attributes('placeholder')).toBe("Type this week's training topic");
        expect(input.attributes('aria-label')).toBe('Training topic');
        expect(factory().find('label[for="create-topic"]').exists()).toBe(false);
    });

    it('creates the roll call with the date as offered and the typed topic', async () => {
        const wrapper = factory();

        await wrapper.get('[data-testid="create-topic"]').setValue('  Land navigation ');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/rollcall/7/create', { week: '2026-11-14', moved_to: '2026-11-18T23:00:00.000Z', cancelled: false, subject: 'Land navigation' });
        expect(wrapper.emitted('created')[0]).toEqual([ANSWER]);
    });

    it('sends a changed date as this week\'s new start', async () => {
        const wrapper = factory();

        await wrapper.get('[data-testid="create-day"]').setValue('4');
        await wrapper.get('[data-testid="create-time"]').setValue('20:30');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postJson.mock.calls[0][1].moved_to).toBe('2026-11-19T20:30:00.000Z');
    });

    it('locks a topic the platoon enforces, names the platoon, and sends no topic for it', async () => {
        const wrapper = factory({ training: { subject: 'Land navigation: map and compass', enforced: true, platoon: '1st Platoon', canEdit: false } });

        expect(wrapper.get('[data-testid="create-topic"]').element.value).toBe('Land navigation: map and compass');
        expect(wrapper.get('[data-testid="create-topic"]').attributes('readonly')).toBeDefined();
        expect(wrapper.get('[data-testid="create-topic-source"]').text()).toBe('Set by 1st Platoon');
        expect(wrapper.find('[data-testid="create-no-topic"]').exists()).toBe(false);

        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(postJson.mock.calls[0][1]).not.toHaveProperty('subject');
    });

    it('leaves the topic empty and editable with no platoon topic, and says so', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="create-topic"]').element.value).toBe('');
        expect(wrapper.get('[data-testid="create-topic"]').attributes('readonly')).toBeUndefined();
        expect(wrapper.find('[data-testid="create-no-topic"]').exists()).toBe(true);
    });

    it('cancels this week\'s practice with "Practice will not take place"', async () => {
        const wrapper = factory();

        await wrapper.get('[data-testid="create-not-held"]').trigger('click');
        await flushPromises();

        expect(postJson.mock.calls[0][1]).toMatchObject({ moved_to: null, cancelled: true });
    });

    it('offers to set a slot when the section has none, and creates without a date', async () => {
        const wrapper = factory({ slot: null });

        expect(wrapper.find('[data-testid="create-day"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="create-not-held"]').exists()).toBe(false);
        await wrapper.get('[data-testid="create-no-slot"] button').trigger('click');
        expect(wrapper.emitted('set-slot')).toHaveLength(1);

        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(postJson.mock.calls[0][1].moved_to).toBeNull();
    });

    it('says why a create failed and stays on the form', async () => {
        postJson.mockResolvedValue({ ok: false, status: 422, data: { message: 'The practice was already captured.' } });
        const wrapper = factory();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(toast.error).toHaveBeenCalled();
        expect(wrapper.emitted('created')).toBeUndefined();
    });

    it('asks the page to reload when the week was sent meanwhile', async () => {
        postJson.mockResolvedValue({ ok: false, status: 409, data: { locked: true, message: 'Locked' } });
        const wrapper = factory();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('locked')).toHaveLength(1);
    });

    it('creates the week without a date when the slot only starts in a later week', async () => {
        const wrapper = factory({ slot: { ...SLOT, effectiveFrom: '2026-11-21', week: { ...SLOT.week, start: null, end: null } } });

        expect(wrapper.find('[data-testid="create-day"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="create-not-held"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="create-slot-later"]').exists()).toBe(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postJson.mock.calls[0][1]).toMatchObject({ moved_to: null, cancelled: false });
    });

    it('creates nothing on a read-only screen', () => {
        expect(factory({ editable: false }).get('[data-testid="create-rollcall"]').attributes('disabled')).toBeDefined();
    });
});
