import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import AddStaffGroup from '../AddStaffGroup.vue';

const post = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal()), router: { post: (...args) => post(...args) } }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

describe('AddStaffGroup', () => {
    let wrapper;
    beforeEach(() => post.mockReset());
    afterEach(() => wrapper?.unmount());

    it('adds a named staff group under the selected unit, through the same add as a line unit', async () => {
        wrapper = mount(AddStaffGroup, { props: { parent: { id: 7, name: 'Executive Officer' } }, attachTo: document.body });
        expect(wrapper.find('[data-testid="add-staff-group-open"]').text()).toBe('Add a staff group under Executive Officer');

        await wrapper.find('[data-testid="add-staff-group-open"]').trigger('click');
        await flushPromises();
        const name = document.body.querySelector('[data-testid="add-staff-group-name"]');
        name.value = '  Member Advocate ';
        name.dispatchEvent(new Event('input'));
        await flushPromises();
        document.body.querySelector('[data-testid="add-staff-group-form"]').dispatchEvent(new Event('submit'));

        expect(post).toHaveBeenCalledWith('/settings/org-structure', { type: 'group', parent_id: 7, name: 'Member Advocate' }, expect.any(Object));
    });

    it('shows the server refusal in the dialog', async () => {
        wrapper = mount(AddStaffGroup, { props: { parent: { id: 7, name: 'Executive Officer' } }, attachTo: document.body });
        await wrapper.find('[data-testid="add-staff-group-open"]').trigger('click');
        await flushPromises();
        const name = document.body.querySelector('[data-testid="add-staff-group-name"]');
        name.value = 'Discord';
        name.dispatchEvent(new Event('input'));
        await flushPromises();
        document.body.querySelector('[data-testid="add-staff-group-form"]').dispatchEvent(new Event('submit'));

        post.mock.calls[0][2].onError({ type: 'A group cannot sit under a holding group.' });
        post.mock.calls[0][2].onFinish();
        await flushPromises();

        expect(document.body.querySelector('[data-testid="add-staff-group-error"]').textContent).toBe('A group cannot sit under a holding group.');
    });
});
