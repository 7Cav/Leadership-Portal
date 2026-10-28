import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import StartHere from '../StartHere.vue';
import TickItOff from '../TickItOff.vue';
import { resetStartHere } from '@/composables/useStartHere';

const page = reactive({ url: '/docs', props: { auth: { user: { id: 7 } }, viewAs: null } });

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
}));

const LEADER = [
    { id: 'basics/sign-in', label: 'Sign in with your 7cav.us account', url: '/docs/basics/sign-in' },
    { id: 'weekly-rollcall/run', label: 'Run your first rollcall', url: '/docs/weekly-rollcall/run' },
    { id: 'weekly-report/send', label: 'Send your weekly report', url: '/docs/weekly-report/send' },
];
const TROOPER = [LEADER[0]];

enableAutoUnmount(afterEach);

const hidden = (wrapper) => (wrapper.get('#start-here-body').attributes('style') ?? '').includes('display: none');
const card = (checklist = LEADER, audience = 'leader') => mount(StartHere, { props: { checklist, audience } });
const count = (wrapper) => wrapper.get('[data-testid="start-here-count"]').text();
const boxes = (wrapper) => wrapper.findAll('[data-testid="start-here-items"] button[role="checkbox"]');

async function tick(wrapper, index) {
    await boxes(wrapper)[index].trigger('click');
    await nextTick();
}

describe('Start here', () => {
    beforeEach(() => {
        // A wide screen unless a test says otherwise: on a narrow one the card starts collapsed.
        vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: false });
        localStorage.clear();
        resetStartHere();
        page.props = { auth: { user: { id: 7 } }, viewAs: null };
    });

    afterEach(() => vi.restoreAllMocks());

    it('lists the viewer\'s items under their role, with a count and nothing ticked', () => {
        const wrapper = card();

        expect(wrapper.text()).toContain('First week as Leadership billet.');
        expect(wrapper.findAll('li').map((li) => li.text())).toEqual(LEADER.map((item) => item.label));
        expect(count(wrapper)).toBe('0 of 3');
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuetext')).toBe('0 of 3 done');
    });

    it('counts only the items this audience sees: a trooper has one', () => {
        const wrapper = card(TROOPER, 'everyone');

        expect(wrapper.text()).toContain('First week as Trooper.');
        expect(count(wrapper)).toBe('0 of 1');
    });

    it('ticks an item by hand, saves it for this user, and keeps it after a reload', async () => {
        const wrapper = card();
        await tick(wrapper, 1);

        expect(count(wrapper)).toBe('1 of 3');
        expect(wrapper.findAll('li')[1].text()).toContain('(done)');
        expect(JSON.parse(localStorage.getItem('docs-start-here:7'))).toEqual(['weekly-rollcall/run']);

        resetStartHere();
        expect(count(card())).toBe('1 of 3');
    });

    it('keeps two people on one computer apart', async () => {
        await tick(card(), 0);

        resetStartHere();
        page.props.auth.user = { id: 8 };
        expect(count(card())).toBe('0 of 3');
    });

    it('says the viewer is all set once every item is ticked', async () => {
        const wrapper = card(TROOPER, 'everyone');
        expect(wrapper.text()).not.toContain('You\'re all set.');

        await tick(wrapper, 0);
        expect(wrapper.text()).toContain('You\'re all set.');
    });

    it('ignores a saved id whose item no longer exists', () => {
        localStorage.setItem('docs-start-here:7', JSON.stringify(['gone/away', 'basics/sign-in', 42]));

        expect(count(card())).toBe('1 of 3');
    });

    it('collapses to one line and remembers that', async () => {
        const wrapper = card();
        await wrapper.get('[data-testid="start-here-toggle"]').trigger('click');

        expect(hidden(wrapper)).toBe(true);
        expect(wrapper.get('[data-testid="start-here-toggle"]').attributes('aria-expanded')).toBe('false');
        expect(count(wrapper)).toBe('0 of 3');

        resetStartHere();
        expect(hidden(card())).toBe(true);
    });

    it('starts collapsed on a narrow screen until the viewer chooses', () => {
        window.matchMedia.mockReturnValue({ matches: true });

        expect(hidden(card())).toBe(true);
    });

    it('still works for the visit when browser storage is unavailable', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('blocked');
        });

        const wrapper = card();
        await tick(wrapper, 0);

        expect(count(wrapper)).toBe('1 of 3');
    });

    it('does not save ticks made during a view-as preview', async () => {
        localStorage.setItem('docs-start-here:7', JSON.stringify(['basics/sign-in']));
        page.props.viewAs = { kind: 'unit', id: 3, label: '1/1/A/1-7' };

        const wrapper = card();
        expect(count(wrapper)).toBe('0 of 3');
        expect(wrapper.text()).toContain('Ticks are not saved during a preview.');

        await tick(wrapper, 2);
        expect(count(wrapper)).toBe('1 of 3');
        expect(JSON.parse(localStorage.getItem('docs-start-here:7'))).toEqual(['basics/sign-in']);
    });

    it('ticks the same item from the guide\'s "Tick it off" button', async () => {
        const wrapper = card();
        const button = mount(TickItOff, { props: { id: 'weekly-rollcall/run', checklist: LEADER } });

        await button.get('button').trigger('click');
        await nextTick();

        expect(button.get('button').attributes('aria-pressed')).toBe('true');
        expect(button.get('button').text()).toBe('Ticked off');
        expect(count(wrapper)).toBe('1 of 3');

        await button.get('button').trigger('click');
        await nextTick();
        expect(count(wrapper)).toBe('0 of 3');
    });

    it('shows nothing when the viewer has no items', () => {
        expect(card([]).html()).toBe('<!--v-if-->');
    });
});
