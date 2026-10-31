import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import LoaSyncProblems from '../LoaSyncProblems.vue';

const page = { props: { forum: { baseUrl: 'https://7cav.us/' } } };
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

// `LoaSyncProblems::latest()`: what the latest leave sync could not read, for an Admin.
const run = (over = {}) => ({
    status: 'partial',
    finishedAt: '2026-10-08T12:15:00+00:00',
    unread: 2,
    needsReview: 3,
    failure: null,
    problems: [
        { threadId: 812, text: 'Thread 812: The forum answered 503.' },
        { threadId: null, text: 'Section 180: stopped at the 50-page limit before reaching the cutoff' },
    ],
    ...over,
});

describe('LoaSyncProblems', () => {
    it('sums up the run, and lists each problem with a link to its thread on the forum', () => {
        const wrapper = mount(LoaSyncProblems, { props: { run: run() } });

        expect(wrapper.get('summary').text()).toBe('Latest leave sync, 08OCT26 12\u200A15Z: 2 problems reading the forum, 3 posts need review');
        const items = wrapper.findAll('[data-testid="loa-sync-unread"] li');
        expect(items[0].get('a').attributes('href')).toBe('https://7cav.us/threads/812/');
        expect(items[0].get('a').attributes('target')).toBe('_blank');
        expect(items[0].text()).toContain('Thread 812: The forum answered 503.');
        expect(items[1].find('a').exists()).toBe(false);
        expect(wrapper.text()).toContain('Threads it could not read are tried again by later syncs.');
        expect(wrapper.text()).toContain('A post that needs review is stored without dates.');
    });

    it('says why a failed run stopped', () => {
        const wrapper = mount(LoaSyncProblems, { props: { run: run({ status: 'failure', unread: 0, needsReview: 0, failure: 'The forum answered 401.', problems: [] }) } });

        expect(wrapper.get('summary').text()).toBe('Latest leave sync, 08OCT26 12\u200A15Z: failed');
        expect(wrapper.get('[data-testid="loa-sync-failure"]').text()).toBe('The sync stopped: The forum answered 401.');
    });

    it('counts the problems it does not list, and uses the singular for one', () => {
        const wrapper = mount(LoaSyncProblems, { props: { run: run({ unread: 25, needsReview: 1 }) } });

        expect(wrapper.get('summary').text()).toContain('25 problems reading the forum, 1 post needs review');
        expect(wrapper.text()).toContain('23 more not listed.');
    });

    it('draws nothing for a run with no problems', () => {
        expect(mount(LoaSyncProblems, { props: { run: run({ status: 'success', unread: 0, needsReview: 0, problems: [] }) } }).html()).not.toContain('details');
    });
});
