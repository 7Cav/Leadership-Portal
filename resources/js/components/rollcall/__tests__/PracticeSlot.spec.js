import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import PracticeSlot from '../PracticeSlot.vue';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/lib/postJson', () => ({ postJson: vi.fn() }));

import { postJson } from '@/lib/postJson';

enableAutoUnmount(afterEach);

const SLOT = { weekday: 4, startMinute: 120, week: { start: '2026-10-29T02:00:00Z', end: '2026-10-29T03:00:00Z', cancelled: false, moved: false, captured: false } };
const factory = (props = {}) => mount(PracticeSlot, { props: { sectionId: 3, week: '2026-10-24', slot: SLOT, ...props }, attachTo: document.body });

describe('PracticeSlot', () => {
    beforeEach(() => postJson.mockReset());

    it('shows the slot in Zulu with a local hint, and no edit control for a reader', () => {
        const wrapper = factory();

        expect(wrapper.text()).toContain('Practice Thu 02 00Z');
        expect(wrapper.find('[data-testid="practice-slot-local"]').text()).toContain('your time');
        expect(wrapper.find('[data-testid="practice-slot-edit"]').exists()).toBe(false);
    });

    it('says when the section has no slot, and when this week is cancelled or moved', () => {
        expect(factory({ slot: null }).find('[data-testid="practice-slot-none"]').exists()).toBe(true);
        expect(factory({ slot: { ...SLOT, week: { ...SLOT.week, cancelled: true, start: null } } }).text()).toContain('Cancelled this week');
        expect(factory({ slot: { ...SLOT, week: { ...SLOT.week, moved: true, start: '2026-10-30T01:00:00Z' } } }).text()).toContain('This week Fri 01 00Z');
    });

    it('disables the edit control with the reason', () => {
        const edit = factory({ canEdit: true, disabledReason: 'Read-only while viewing as' }).get('[data-testid="practice-slot-edit"]');

        expect(edit.attributes('disabled')).toBeDefined();
        expect(edit.attributes('title')).toBe('Read-only while viewing as');
    });

    it('saves the slot and emits the server answer', async () => {
        const answer = { ...SLOT, weekday: 3, startMinute: 1439 };
        postJson.mockResolvedValue({ ok: true, status: 200, data: { practiceSlot: answer } });
        const wrapper = factory({ canEdit: true });

        await wrapper.get('[data-testid="practice-slot-edit"]').trigger('click');
        await flushPromises();
        wrapper.findAllComponents(NativeSelect)[0].vm.$emit('update:modelValue', '3');
        wrapper.findAllComponents(Input)[0].vm.$emit('update:modelValue', '23:59');
        await flushPromises();
        document.querySelector('[data-testid="practice-slot-form"]').dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/rollcall/3/slot', { weekday: 3, start_minute: 1439, week: '2026-10-24' });
        expect(wrapper.emitted('updated')).toEqual([[answer]]);
    });
});
