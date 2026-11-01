import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import PresenceMinimumPanel from '../PresenceMinimumPanel.vue';

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/lib/postJson', () => ({ postJson: vi.fn() }));

import { toast } from 'vue-sonner';
import { postJson } from '@/lib/postJson';

enableAutoUnmount(afterEach);

const DEFAULT = { minutes: 40, isDefault: true, default: 40, canEdit: false };
const factory = (minimum = DEFAULT) => mount(PresenceMinimumPanel, { props: { minimum, unitId: 7 }, attachTo: document.body });

describe('PresenceMinimumPanel', () => {
    beforeEach(() => {
        postJson.mockReset();
        toast.error.mockReset();
    });

    it('shows the minimum and that it is the default, with no form for a reader', () => {
        const wrapper = factory();

        expect(wrapper.get('[data-testid="presence-minimum-text"]').text()).toContain('after 40 minutes on its server during the hour, the default');
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('saves a new minimum for the HQ staff and shows the answer', async () => {
        postJson.mockResolvedValue({ ok: true, data: { presenceMinimum: { minutes: 45, isDefault: false, default: 40, canEdit: true } } });
        const wrapper = factory({ ...DEFAULT, canEdit: true });

        await wrapper.get('input').setValue('45');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/roster/7/presence-minimum', { minutes: 45 });
        expect(wrapper.get('[data-testid="presence-minimum-text"]').text()).not.toContain('the default');
        expect(wrapper.find('[data-testid="presence-minimum-reset"]').text()).toContain('Use the default (40)');
    });

    it('refuses a minimum outside one to sixty without sending it', async () => {
        const wrapper = factory({ ...DEFAULT, canEdit: true });

        await wrapper.get('input').setValue('90');
        await wrapper.get('form').trigger('submit');

        expect(postJson).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalled();
    });

    it('goes back to the default', async () => {
        postJson.mockResolvedValue({ ok: true, data: { presenceMinimum: DEFAULT } });
        const wrapper = factory({ minutes: 50, isDefault: false, default: 40, canEdit: true });

        await wrapper.get('[data-testid="presence-minimum-reset"]').trigger('click');
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/roster/7/presence-minimum', { minutes: null });
    });
});
