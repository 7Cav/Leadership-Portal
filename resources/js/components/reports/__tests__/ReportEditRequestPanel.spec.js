import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import { nextTick } from 'vue';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...args) => post(...args) } }));

import ReportEditRequestPanel from '../ReportEditRequestPanel.vue';

enableAutoUnmount(afterEach);

const URL = '/reports/5/2026-10-03/edit-request';
const locked = (overrides = {}) => ({ locked: true, editableUntil: '2026-10-10T23:59:00+00:00', canRequest: false, canDecide: false, latest: null, ...overrides });
const factory = (editRequest) => mount(ReportEditRequestPanel, { props: { editRequest, requestUrl: URL } });
const pendingRequest = { id: 7, status: 'pending', reason: 'Sent into the wrong week', requestedBy: '1LT DAmico.J', requestedAt: '2026-11-11T20:00:00+00:00' };

describe('ReportEditRequestPanel', () => {
    beforeEach(() => post.mockReset());

    it('shows nothing on a report that can still be edited', () => {
        expect(factory(locked({ locked: false })).find('[data-testid="edit-request"]').exists()).toBe(false);
    });

    it('asks for a reason, then posts the request', async () => {
        const wrapper = factory(locked({ canRequest: true }));
        await wrapper.get('[data-testid="edit-request-open"]').trigger('click');

        expect(wrapper.get('[data-testid="edit-request-send"]').attributes('disabled')).toBeDefined();
        await wrapper.get('#edit-request-reason').setValue('  Sent into the wrong week ');
        await wrapper.get('[data-testid="edit-request-send"]').trigger('click');

        expect(post).toHaveBeenCalledWith(URL, { reason: 'Sent into the wrong week' }, expect.any(Object));
    });

    it('shows a pending request, and Approve and Deny only to a leader above', async () => {
        const reader = factory(locked({ latest: pendingRequest }));
        expect(reader.get('[data-testid="edit-request-status"]').text()).toContain('1LT DAmico.J asked to edit it');
        expect(reader.find('[data-testid="edit-request-decide"]').exists()).toBe(false);
        expect(reader.find('[data-testid="edit-request-open"]').exists()).toBe(false);

        const leader = factory(locked({ latest: pendingRequest, canDecide: true }));
        await leader.get('#edit-request-note').setValue('Go ahead');
        await leader.get('[data-testid="edit-request-approve"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/report-edit-requests/7/approve', { note: 'Go ahead' }, expect.any(Object));

        // The buttons wait on the answer, so Deny is tried on a fresh panel.
        expect(leader.get('[data-testid="edit-request-deny"]').attributes('disabled')).toBeDefined();
        const other = factory(locked({ latest: pendingRequest, canDecide: true }));
        await other.get('[data-testid="edit-request-deny"]').trigger('click');
        expect(post).toHaveBeenLastCalledWith('/report-edit-requests/7/deny', { note: null }, expect.any(Object));
    });

    it('names a denial with its note, and an approval with the time it is open until', async () => {
        const denied = factory(locked({ canRequest: true, latest: { id: 7, status: 'denied', decidedBy: 'CPT Hale.S', decidedAt: '2026-11-12T10:00:00+00:00', note: 'Send last week first.' } }));
        expect(denied.get('[data-testid="edit-request-status"]').text()).toContain('CPT Hale.S denied the edit request');
        expect(denied.get('[data-testid="edit-request-status"]').text()).toContain('Send last week first.');
        expect(denied.find('[data-testid="edit-request-open"]').exists()).toBe(true);

        const approved = factory(locked({ locked: false, editableUntil: '2026-11-14T23:59:00+00:00', latest: { id: 7, status: 'approved', decidedBy: 'CPT Hale.S' } }));
        await nextTick();
        expect(approved.get('[data-testid="edit-request-status"]').text()).toContain('Open until Sat 14NOV26');
    });
});
