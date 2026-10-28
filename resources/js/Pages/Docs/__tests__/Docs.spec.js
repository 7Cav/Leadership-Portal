import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import Index from '../Index.vue';
import Show from '../Show.vue';
import DocsTree from '@/components/docs/DocsTree.vue';

const page = reactive({ url: '/docs', props: {} });

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
}));

const link = (title, url, audience = 'leader', weekly = false) => ({ title, summary: `${title} summary`, url, audience, weekly });

const CONTENTS = [
    { key: 'basics', title: 'The basics', guides: [link('Read the roster', '/docs/basics/roster', 'everyone')] },
    {
        key: 'weekly-rollcall',
        title: 'Weekly rollcall',
        guides: [link('Run this week\'s rollcall', '/docs/weekly-rollcall/run', 'leader', true), link('Add a guest', '/docs/weekly-rollcall/guests')],
    },
];

const GUIDE = {
    title: 'Run this week\'s rollcall',
    summary: 'Mark attendance, confirm, post.',
    url: '/docs/weekly-rollcall/run',
    section: { key: 'weekly-rollcall', title: 'Weekly rollcall' },
    audience: 'leader',
    page: '/rollcall',
    weekly: true,
    minutes: 5,
    html: '<blockquote class="callout callout-why"><p><strong>Why this matters.</strong> It feeds the report.</p></blockquote><h2 id="open-rollcall">Open Rollcall</h2><p>Text.</p><h2 id="mark-each-trooper">Mark each trooper</h2>',
    headings: [
        { id: 'open-rollcall', text: 'Open Rollcall' },
        { id: 'mark-each-trooper', text: 'Mark each trooper' },
    ],
    checklist: null,
};

const show = (props = {}) =>
    mount(Show, {
        props: { audience: 'leader', contents: CONTENTS, checklist: [], feedbackUrl: null, guide: GUIDE, previous: CONTENTS[0].guides[0], next: CONTENTS[1].guides[1], ...props },
    });

describe('DocsTree', () => {
    it('lists each section and its guides, highlighting the open guide and opening its section', () => {
        const tree = mount(DocsTree, { props: { contents: CONTENTS, current: '/docs/weekly-rollcall/guests' } });
        const sections = tree.findAll('details');

        expect(sections.map((s) => s.find('summary').text())).toEqual(['The basics', 'Weekly rollcall']);
        expect(sections[0].attributes('open')).toBeUndefined();
        expect(sections[1].attributes('open')).toBeDefined();
        expect(tree.find('[aria-current="page"]').text()).toBe('Add a guest');
    });

    it('opens every section and marks docs home current on docs home', () => {
        const tree = mount(DocsTree, { props: { contents: CONTENTS, current: null } });

        expect(tree.findAll('details').every((s) => s.attributes('open') !== undefined)).toBe(true);
        expect(tree.find('[aria-current="page"]').text()).toBe('Docs home');
    });
});

describe('Docs home', () => {
    it('asks what the reader needs to do, names their role and groups their guides', () => {
        const home = mount(Index, { props: { audience: 'leader', contents: CONTENTS, checklist: [] } });

        expect(home.find('h1').text()).toBe('What do you need to do?');
        expect(home.get('[data-testid="docs-role"]').text()).toBe('Leadership billet');
        expect(home.findAll('[data-testid^="docs-group-"]').map((g) => g.find('h2').text())).toEqual(['Every week', 'When it comes up', 'For everyone']);
        expect(home.get('[data-testid="docs-group-weekly"]').text()).toContain('Run this week\'s rollcall summary');
    });

    it('shows a trooper only For everyone, and no Admin group', () => {
        const home = mount(Index, { props: { audience: 'everyone', contents: [CONTENTS[0]], checklist: [] } });

        expect(home.get('[data-testid="docs-role"]').text()).toBe('Trooper');
        expect(home.findAll('[data-testid^="docs-group-"]').map((g) => g.find('h2').text())).toEqual(['For everyone']);
    });
});

describe('Guide page', () => {
    it('shows the title, summary, tags, body and the steps under "On this page"', () => {
        page.url = '/docs/weekly-rollcall/run';
        const guide = show();

        expect(guide.find('h1').text()).toBe('Run this week\'s rollcall');
        expect(guide.text()).toContain('Mark attendance, confirm, post.');
        expect(guide.get('[data-testid="guide-audience"]').text()).toBe('Every leadership billet');
        expect(guide.text()).toContain('About 5 minutes');
        expect(guide.text()).toContain('Once a week');
        expect(guide.get('[data-testid="guide-body"]').html()).toContain('<strong>Why this matters.</strong>');
        expect(guide.get('[data-testid="on-this-page"]').findAll('a[href^="#"]').map((a) => a.text())).toEqual(['Open Rollcall', 'Mark each trooper']);
    });

    it('opens the screen the guide explains, and links the previous and next guides', () => {
        const guide = show();

        expect(guide.get('[data-testid="open-in-portal"]').attributes('href')).toBe('/rollcall');
        expect(guide.get('[data-testid="open-in-portal"]').text()).toBe('Open Rollcall');
        expect(guide.find('a[rel="prev"]').attributes('href')).toBe('/docs/basics/roster');
        expect(guide.get('[data-testid="next-guide"]').attributes('href')).toBe('/docs/weekly-rollcall/guests');
    });

    it('offers no "Open in portal" for a page with parts that depend on the reader', () => {
        const guide = show({ guide: { ...GUIDE, page: '/reports/{unit}/{week}' } });

        expect(guide.find('[data-testid="open-in-portal"]').exists()).toBe(false);
    });

    it('says where the reader came from when the "?" help opened it', () => {
        page.url = '/docs/weekly-rollcall/run?from=/rollcall';
        const guide = show();

        expect(guide.get('[data-testid="came-from"]').text()).toContain('You opened this from Rollcall.');
        expect(guide.get('[data-testid="came-from"] a').attributes('href')).toBe('/rollcall');

        page.url = '/docs/weekly-rollcall/run';
        expect(show().find('[data-testid="came-from"]').exists()).toBe(false);
    });

    it('thanks a Yes, and sends a No to the feedback link when one is set', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);

        const yes = show();
        await yes.get('[data-testid="guide-feedback"]').findAll('button')[0].trigger('click');
        expect(yes.get('[data-testid="guide-feedback"]').text()).toBe('Thanks for letting us know.');

        const no = show({ feedbackUrl: 'https://7cav.us/threads/portal-docs.1/' });
        await no.get('[data-testid="guide-feedback"]').findAll('button')[1].trigger('click');
        expect(open).toHaveBeenCalledWith('https://7cav.us/threads/portal-docs.1/', '_blank', 'noopener');

        open.mockClear();
        const unset = show();
        await unset.get('[data-testid="guide-feedback"]').findAll('button')[1].trigger('click');
        expect(open).not.toHaveBeenCalled();
        expect(unset.get('[data-testid="guide-feedback"]').text()).toContain('Ask your chain of command, or S6');
        open.mockRestore();
    });
});
