import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import AccountabilityPanel from '../AccountabilityPanel.vue';

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/lib/postJson', () => ({ postJson: vi.fn() }));

import { toast } from 'vue-sonner';
import { postJson } from '@/lib/postJson';

enableAutoUnmount(afterEach);

const CHOICES = [
    { key: 'sp_own_unit', label: 'Section practice in own unit' },
    { key: 'ftx', label: 'FTX' },
    { key: 'server_hour', label: 'Public or training server hour with a leader present', pending: true },
];
const BASE = { kind: 'staff', options: ['sp_own_unit', 'ftx'], source: 'default', from: null, choices: CHOICES, canEdit: false };
const factory = (accountability = BASE) => mount(AccountabilityPanel, { props: { accountability, unitId: 7 }, attachTo: document.body });

describe('AccountabilityPanel', () => {
    beforeEach(() => {
        postJson.mockReset();
        toast.error.mockReset();
    });

    it('lists what counts for a reader, and says when it is the default', () => {
        const wrapper = factory();

        expect(wrapper.text()).toContain('What counts for staff accountability');
        expect(wrapper.get('[data-testid="accountability-source"]').text()).toBe('The default.');
        expect(wrapper.get('[data-testid="accountability-list"]').text()).toContain('FTX');
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('shows an option with no effect yet disabled, as coming later', () => {
        const wrapper = factory({ ...BASE, canEdit: true });

        expect(wrapper.get('[data-testid="accountability-server_hour"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="accountability-pending"]').text()).toBe('Coming later');
    });

    it('names makeups on a section', () => {
        expect(factory({ ...BASE, kind: 'makeup' }).text()).toContain('What counts as a makeup');
    });

    it('saves the ticked options and shows the answer', async () => {
        postJson.mockResolvedValue({ ok: true, data: { accountability: { ...BASE, options: ['ftx'], source: 'own', canEdit: true } } });
        const wrapper = factory({ ...BASE, canEdit: true });

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/roster/7/accountability', { options: ['sp_own_unit', 'ftx'] });
        expect(wrapper.find('[data-testid="accountability-source"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="accountability-reset"]').exists()).toBe(true);
    });

    it('goes back to the inherited options', async () => {
        postJson.mockResolvedValue({ ok: true, data: { accountability: { ...BASE, canEdit: true } } });
        const wrapper = factory({ ...BASE, source: 'own', canEdit: true });

        await wrapper.get('[data-testid="accountability-reset"]').trigger('click');
        await flushPromises();

        expect(postJson).toHaveBeenCalledWith('/roster/7/accountability', { options: null });
    });

    it('refuses to save with nothing ticked', async () => {
        const wrapper = factory({ ...BASE, options: [], canEdit: true });

        await wrapper.get('form').trigger('submit');

        expect(postJson).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalled();
    });
});
