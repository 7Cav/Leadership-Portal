import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import TrainingCard from '../TrainingCard.vue';
import { Input } from '@/components/ui/input';

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/lib/postJson', () => ({ postJson: vi.fn() }));

import { toast } from 'vue-sonner';
import { postJson } from '@/lib/postJson';

enableAutoUnmount(afterEach);

const TRAINING = { subject: null, enforced: false, platoon: null, canEdit: true };
const factory = (props = {}) =>
    mount(TrainingCard, { props: { sectionId: 3, week: '2026-11-14', training: TRAINING, start: '2026-11-18T23:00:00Z', editable: true, ...props } });

describe('TrainingCard (#703)', () => {
    beforeEach(() => {
        postJson.mockReset();
        toast.error.mockReset();
    });

    it('shows the week\'s practice start in Zulu, and says when there is none', () => {
        expect(factory().get('[data-testid="training-date"]').text()).toMatch(/^Wed 18NOV26 23\s00Z$/);
        expect(factory({ start: null }).get('[data-testid="training-date"]').text()).toBe('No practice this week');
    });

    it('offers the date change to a leader who may change it', async () => {
        const wrapper = factory({ canChangeDate: true });

        await wrapper.get('[data-testid="training-date-change"]').trigger('click');
        expect(wrapper.emitted('change-date')).toHaveLength(1);
        expect(factory().find('[data-testid="training-date-change"]').exists()).toBe(false);
    });

    it('locks a topic the platoon enforces, and names the platoon', () => {
        const wrapper = factory({ training: { subject: 'Land navigation', enforced: true, platoon: '1st Platoon', canEdit: false } });

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.get('[data-testid="training-topic"]').text()).toContain('Land navigation');
        expect(wrapper.get('[data-testid="training-topic-source"]').text()).toBe('Set by 1st Platoon');
    });

    it('saves an edited topic and takes the server\'s answer', async () => {
        const answer = { subject: 'Radio procedure', enforced: false, platoon: null, canEdit: true };
        postJson.mockResolvedValue({ ok: true, status: 200, data: { training: answer } });
        const wrapper = factory();

        wrapper.findComponent(Input).vm.$emit('update:modelValue', 'Radio procedure');
        await flushPromises();
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/rollcall/3/training-topic', { week: '2026-11-14', subject: 'Radio procedure' });
        expect(wrapper.emitted('updated')[0]).toEqual([answer]);
    });

    it('keeps the save closed while nothing changed, and says why a save failed', async () => {
        postJson.mockResolvedValue({ ok: false, status: 422, data: { message: 'Keep the training topic to 255 characters.' } });
        const wrapper = factory({ training: { ...TRAINING, subject: 'Same' } });

        expect(wrapper.get('[data-testid="training-topic-save"]').attributes('disabled')).toBeDefined();
        wrapper.findComponent(Input).vm.$emit('update:modelValue', 'Other');
        await flushPromises();
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(toast.error).toHaveBeenCalled();
        expect(wrapper.emitted('updated')).toBeUndefined();
    });

    it('shows the topic read-only on a week that cannot change', () => {
        const wrapper = factory({ training: { ...TRAINING, subject: 'Frozen' }, editable: false, disabledReason: 'Locked' });

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.get('[data-testid="training-topic"]').text()).toContain('Frozen');
    });
});
