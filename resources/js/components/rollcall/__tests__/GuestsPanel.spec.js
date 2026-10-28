import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import GuestsPanel from '../GuestsPanel.vue';

enableAutoUnmount(afterEach);
const post = vi.fn();
vi.mock('@/lib/postJson', () => ({ postJson: (...args) => post(...args), reloadPage: vi.fn(), sessionEnded: (status) => status === 401 || status === 419 }));
const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock('vue-sonner', () => ({ toast: { success: (...a) => toastSuccess(...a), error: (...a) => toastError(...a) } }));

const SEEN = { trooperId: 7, name: 'Seen.S', displayName: 'SPC Seen.S', rank: 'SPC', section: '2/1/A/1-7', source: 'capture', recordedBy: null };
const ADDED = { trooperId: 8, name: 'Added.A', displayName: 'PFC Added.A', rank: 'PFC', section: '3/2/B/1-7', source: 'manual', recordedBy: 'Host.L' };

const factory = (props = {}) => mount(GuestsPanel, { props: { sectionId: 12, week: '2026-10-03', guests: [SEEN, ADDED], ...props } });

function answerSearch(troopers, more = false) {
    globalThis.fetch = vi.fn().mockResolvedValue({ status: 200, ok: true, json: () => Promise.resolve({ troopers, more }) });
}

async function search(wrapper, text) {
    await wrapper.find('[data-testid="guest-add"]').trigger('click');
    await wrapper.find('[data-testid="guest-search"]').setValue(text);
    await vi.advanceTimersByTimeAsync(300);
    await flushPromises();
}

describe('GuestsPanel', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        post.mockReset();
        toastSuccess.mockReset();
        toastError.mockReset();
    });
    afterEach(() => vi.useRealTimers());

    it('lists the practice\'s guests with where each entry came from', () => {
        const wrapper = factory();

        expect(wrapper.find('[data-testid="guest-7"] [data-testid="guest-source"]').text()).toContain('Seen on the game server');
        expect(wrapper.find('[data-testid="guest-8"] [data-testid="guest-source"]').text()).toBe('Added by Host.L');
        expect(wrapper.find('[data-testid="guest-8"]').text()).toContain('3/2/B/1-7');
        // Only a hand-added guest can be undone.
        expect(wrapper.find('[data-testid="guest-7"] [data-testid="guest-undo"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="guest-8"] [data-testid="guest-undo"]').exists()).toBe(true);
    });

    it('says when nobody else attended', () => {
        expect(factory({ guests: [] }).find('[data-testid="guest-empty"]').text()).toBe('No guests recorded for this practice.');
    });

    it('searches the section\'s guest endpoint after a pause, then adds the chosen trooper', async () => {
        answerSearch([{ id: 9, name: 'Visitor.V', rank: 'PFC', section: '1/1/B/1-7' }, { id: 8, name: 'Added.A', rank: 'PFC', section: '3/2/B/1-7' }]);
        post.mockResolvedValue({ ok: true, status: 200, data: { guest: { trooperId: 9, name: 'Visitor.V', displayName: 'PFC Visitor.V', rank: 'PFC', section: '1/1/B/1-7', source: 'manual', recordedBy: 'Host.L' }, makeup: 'marked' } });
        const wrapper = factory();

        await search(wrapper, 'vis');

        expect(globalThis.fetch).toHaveBeenCalledWith('/rollcall/12/guests/search?q=vis', expect.objectContaining({ credentials: 'same-origin' }));
        // Someone already listed cannot be added twice.
        expect(wrapper.find('[data-testid="guest-result-8"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="guest-result-8"]').text()).toContain('Already listed');

        await wrapper.find('[data-testid="guest-result-9"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/rollcall/12/guests', { week: '2026-10-03', trooper_id: 9 });
        expect(wrapper.find('[data-testid="guest-9"]').text()).toContain('Visitor.V');
        expect(wrapper.find('[data-testid="guest-picker"]').exists()).toBe(false);
        expect(toastSuccess).toHaveBeenCalledWith('Added PFC Visitor.V as a guest', { description: "A makeup is on their own section's rollcall for this week." });
    });

    it('says why a guest was refused, and keeps the list as it was', async () => {
        answerSearch([{ id: 9, name: 'Visitor.V', rank: 'PFC', section: '1/1/B/1-7' }]);
        post.mockResolvedValue({ ok: false, status: 422, data: { message: 'This practice is confirmed, so its guests can no longer change.', reason: 'confirmed' } });
        const wrapper = factory();

        await search(wrapper, 'vis');
        await wrapper.find('[data-testid="guest-result-9"]').trigger('click');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('Could not add Visitor.V', { description: 'This practice is confirmed, so its guests can no longer change.' });
        expect(wrapper.find('[data-testid="guest-9"]').exists()).toBe(false);
    });

    it('undoes a hand-added guest and says what became of the makeup', async () => {
        post.mockResolvedValue({ ok: true, status: 200, data: { removed: true, makeup: 'withdrawn' } });
        const wrapper = factory();

        await wrapper.find('[data-testid="guest-8"] [data-testid="guest-undo"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/rollcall/12/guests/remove', { week: '2026-10-03', trooper_id: 8 });
        expect(wrapper.find('[data-testid="guest-8"]').exists()).toBe(false);
        expect(toastSuccess).toHaveBeenCalledWith('Removed PFC Added.A', { description: 'The makeup it put on their own rollcall was taken back.' });
    });

    // Review L5: the server's length refusal (422) is not a broken connection, as in the shell's search (#346).
    it('reads the server refusing the length as too long, not as a broken connection', async () => {
        globalThis.fetch = vi.fn().mockResolvedValue({ status: 422, ok: false, json: () => Promise.resolve({ message: 'Search with 2 to 64 characters.' }) });
        const wrapper = factory();

        await search(wrapper, 'visitor');

        expect(wrapper.find('[data-testid="guest-search-error"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="guest-search-too-long"]').text()).toContain('at most 64 characters');
    });

    it('says a search is too long instead of sending it, counting an emoji as one character', async () => {
        answerSearch([]);
        const wrapper = factory();

        // 64 characters is fine even though the emoji make it longer than 64 UTF-16 units.
        await search(wrapper, '\u{1F600}'.repeat(4) + 'a'.repeat(60));
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[data-testid="guest-search-too-long"]').exists()).toBe(false);

        await wrapper.find('[data-testid="guest-search"]').setValue('a'.repeat(65));
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[data-testid="guest-search-too-long"]').text()).toContain('at most 64 characters');
        expect(wrapper.find('[data-testid="guest-search"]').attributes('maxlength')).toBe('64');
    });

    it('asks for hand entries and does not promise that the game server adds guests', () => {
        const text = factory().text();

        expect(text).toContain('Add troopers from other sections who attended.');
        expect(text).toContain('The game server does not add guests yet; it will once practices have set times.');
    });

    it('offers no add or undo to a read-only viewer, and says why', () => {
        const wrapper = factory({ editable: false, disabledReason: 'Read-only: a staff grant lets you view this section, not mark it' });

        expect(wrapper.find('[data-testid="guest-add"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="guest-undo"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Read-only: a staff grant lets you view this section, not mark it.');
    });
});
