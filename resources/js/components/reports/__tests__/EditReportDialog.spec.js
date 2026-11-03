import { afterEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import EditReportDialog from '../EditReportDialog.vue';

enableAutoUnmount(afterEach);

const DUE = '2026-10-10T23:59:00+00:00';
const factory = (props = {}) => mount(EditReportDialog, { props: { open: true, due: DUE, ...props }, attachTo: document.body });
const confirmButton = () => document.querySelector('[data-testid="edit-confirm"]');

describe('EditReportDialog', () => {
    it('names the deadline and asks for a reason before it reopens the report', async () => {
        const wrapper = factory();
        await nextTick();

        expect(document.querySelector('[data-testid="edit-dialog"]').textContent).toContain('10OCT26 23 59Z');
        expect(confirmButton().disabled).toBe(true);

        const reason = document.querySelector('#edit-reason');
        reason.value = '  Wrong marks  ';
        reason.dispatchEvent(new Event('input'));
        await nextTick();

        expect(confirmButton().disabled).toBe(false);
        confirmButton().click();
        expect(wrapper.emitted('confirm')).toEqual([['Wrong marks']]);
    });

    it('shows the server error', async () => {
        factory({ error: 'This report locked at its deadline.' });
        await nextTick();

        expect(document.querySelector('[role="alert"]').textContent).toContain('locked at its deadline');
    });
});
