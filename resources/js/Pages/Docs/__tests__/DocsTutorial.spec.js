import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import Index from '../Index.vue';
import Show from '../Show.vue';
import Tutorial from '../Tutorial.vue';
import { resetTutorialProgress, useTutorialProgress } from '@/composables/useTutorialProgress';
import { cavDate, initialPath } from '@/lib/docs';
import { highlight } from '@/lib/docsSearch';

const page = reactive({ url: '/docs', props: { auth: { user: { id: 7 } }, viewAs: null } });
const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    Head: { template: '<head></head>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
    router: { visit },
}));

const link = (title, url) => ({ title, summary: `${title} summary`, url, audience: 'leader', weekly: false });

const PATHS = [
    { key: 'section-leader', title: 'Section Leader', blurb: 'Every week.', guides: [link('Run roll call', '/docs/a/run'), link('Send your report', '/docs/a/report')] },
    { key: 'hq-staff', title: 'HQ staff', blurb: 'Staff report.', guides: [link('Send your staff report', '/docs/a/staff')] },
];

const STEPS = [
    { id: 'first', title: 'Sign in', url: '/docs/tutorial/first' },
    { id: 'second', title: 'Read the roster', url: '/docs/tutorial/second' },
    { id: 'third', title: 'Where to get help', url: '/docs/tutorial/third' },
];
const TUTORIAL = { url: STEPS[0].url, steps: STEPS };

const home = (props = {}) =>
    mount(Index, { props: { audience: 'leader', contents: [], search: [], paths: PATHS, recent: [], tutorial: TUTORIAL, ...props } });

const step = (index, props = {}) =>
    mount(Tutorial, {
        props: {
            audience: 'leader',
            contents: [],
            search: [],
            guide: { title: STEPS[index].title, summary: 'Summary.', url: STEPS[index].url, page: '/roster', try: 'Open the roster', minutes: 2, html: '<p>Body.</p>', headings: [] },
            previous: STEPS[index - 1] ?? null,
            next: STEPS[index + 1] ?? null,
            steps: STEPS,
            tutorial: TUTORIAL,
            ...props,
        },
    });

beforeEach(() => {
    localStorage.clear();
    resetTutorialProgress();
    page.props = { auth: { user: { id: 7 } }, viewAs: null };
    visit.mockClear();
});

afterEach(() => vi.restoreAllMocks());

describe('Docs home role paths', () => {
    it("opens on the viewer's role and lists that path's guides in order", () => {
        const wrapper = home();

        expect(wrapper.get('[data-testid="docs-path-section-leader"]').attributes('aria-selected')).toBe('true');
        expect(wrapper.get('[data-testid="docs-path-panel"]').findAll('a').map((a) => a.attributes('href'))).toEqual(['/docs/a/run', '/docs/a/report']);
    });

    it('switches path on a click, and remembers the pick for the next visit', async () => {
        const wrapper = home();
        await wrapper.get('[data-testid="docs-path-hq-staff"]').trigger('click');

        expect(wrapper.get('[data-testid="docs-path-panel"]').text()).toContain('Send your staff report');
        expect(home().get('[data-testid="docs-path-hq-staff"]').attributes('aria-selected')).toBe('true');
    });

    it('moves between roles with the arrow keys', async () => {
        const wrapper = home();
        await wrapper.get('[data-testid="docs-path-section-leader"]').trigger('keydown', { key: 'ArrowRight' });

        expect(wrapper.get('[data-testid="docs-path-hq-staff"]').attributes('aria-selected')).toBe('true');
    });

    it('still works when browser storage throws', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });

        expect(home().get('[data-testid="docs-path-section-leader"]').attributes('aria-selected')).toBe('true');
    });

    it('picks the default path by audience, a remembered one when still offered, else the first', () => {
        expect(initialPath(PATHS, 'leader')).toBe('section-leader');
        expect(initialPath(PATHS, 'leader', 'hq-staff')).toBe('hq-staff');
        expect(initialPath(PATHS, 'leader', 'gone')).toBe('section-leader');
        expect(initialPath(PATHS, 'admin')).toBe('section-leader');
        expect(initialPath([], 'leader')).toBe(null);
    });

    it('offers the tutorial, and continues from the first step not done', () => {
        expect(home().get('[data-testid="docs-tutorial-start"]').text()).toBe('Start the tutorial');

        localStorage.setItem('docs-tutorial:7', JSON.stringify(['first']));
        resetTutorialProgress();
        const resumed = home().get('[data-testid="docs-tutorial-start"]');
        expect(resumed.text()).toBe('Continue with step 2');
        expect(resumed.attributes('href')).toBe('/docs/tutorial/second');
    });

    it('shows no tutorial call to action to a reader without one, and lists recent guides with Cav dates', () => {
        const wrapper = home({ tutorial: null, recent: [{ ...link('Read the roster', '/docs/b/roster'), updated: '2026-10-31' }] });

        expect(wrapper.find('[data-testid="docs-tutorial-cta"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="docs-recent"]').text()).toContain('31OCT26');
        expect(cavDate('2026-01-05')).toBe('05JAN26');
        expect(cavDate('soon')).toBe('');
    });
});

describe('Tutorial step', () => {
    it('says which step this is and links the real page to try it on', () => {
        const wrapper = step(1);

        expect(wrapper.get('[data-testid="tutorial-position"]').text()).toContain('Step 2 of 3');
        expect(wrapper.get('[data-testid="tutorial-try"]').attributes('href')).toBe('/roster');
        expect(wrapper.get('[data-testid="tutorial-try"]').text()).toBe('Open the roster');
        expect(wrapper.get('[data-testid="tutorial-back"]').attributes('href')).toBe('/docs/tutorial/first');
    });

    it('marks the step done on Next, remembers it in this browser, and moves on', async () => {
        const wrapper = step(0);
        await wrapper.get('[data-testid="tutorial-next"]').trigger('click');

        expect(visit).toHaveBeenCalledWith('/docs/tutorial/second');
        expect(JSON.parse(localStorage.getItem('docs-tutorial:7'))).toEqual(['first']);

        resetTutorialProgress();
        expect(step(1).get('[data-testid="tutorial-bar-first"]').attributes('aria-label')).toBe('Step 1: Sign in, done');
    });

    it('finishes on the last step and goes back to docs home', async () => {
        const wrapper = step(2);
        expect(wrapper.get('[data-testid="tutorial-next"]').text()).toBe('Finish the tutorial');

        await wrapper.get('[data-testid="tutorial-next"]').trigger('click');
        expect(visit).toHaveBeenCalledWith('/docs');
    });

    it('ticks and unticks a step by hand', async () => {
        const wrapper = step(1);
        const box = wrapper.get('[data-testid="tutorial-done"]');

        await box.setValue(true);
        expect(JSON.parse(localStorage.getItem('docs-tutorial:7'))).toEqual(['second']);
        await box.setValue(false);
        expect(JSON.parse(localStorage.getItem('docs-tutorial:7'))).toEqual([]);
    });

    it('keeps nothing during a view-as preview, and ignores ids of steps that no longer exist', () => {
        localStorage.setItem('docs-tutorial:7', JSON.stringify(['first', 'removed']));
        resetTutorialProgress();
        const progress = useTutorialProgress(
            () => STEPS,
            () => ({ userId: 7, previewing: false }),
        );
        expect(progress.done.value).toEqual(['first']);

        const preview = useTutorialProgress(
            () => STEPS,
            () => ({ userId: 7, previewing: true }),
        );
        preview.mark('second');
        expect(JSON.parse(localStorage.getItem('docs-tutorial:7'))).toEqual(['first', 'removed']);
    });
});

describe('Guide page table of contents', () => {
    it('folds "On this page" above the body on a narrow screen', () => {
        page.url = '/docs/a/run';
        const guide = mount(Show, {
            props: {
                audience: 'leader',
                contents: [],
                search: [],
                guide: {
                    title: 'Run roll call',
                    summary: 'Summary.',
                    url: '/docs/a/run',
                    section: { key: 'a', title: 'Weekly roll call' },
                    audience: 'leader',
                    page: null,
                    weekly: false,
                    minutes: null,
                    readingMinutes: 3,
                    html: '<h2 id="one">One</h2><h2 id="two">Two</h2>',
                    headings: [
                        { id: 'one', text: 'One' },
                        { id: 'two', text: 'Two' },
                    ],
                },
            },
        });

        const toc = guide.get('[data-testid="on-this-page-mobile"]');
        expect(toc.element.tagName).toBe('DETAILS');
        expect(toc.findAll('a').map((a) => a.attributes('href'))).toEqual(['#one', '#two']);
        expect(guide.get('[data-testid="guide-reading"]').text()).toBe('3 min read');
        expect(guide.get('[data-testid="guide-breadcrumb"]').text()).toContain('Weekly roll call');
    });
});

describe('search highlight', () => {
    it('marks the matched words and joins the rest', () => {
        expect(highlight('Add a guest to practice', 'guest')).toEqual([
            { text: 'Add a ', hit: false },
            { text: 'guest', hit: true },
            { text: ' to practice', hit: false },
        ]);
        expect(highlight('Add a guest', '')).toEqual([{ text: 'Add a guest', hit: false }]);
    });
});

describe('steps the portal knows are done', () => {
    it('ticks a step the portal knows is done, says so, and will not untick it', async () => {
        page.props = { ...page.props, portalDone: ['second'] };
        const wrapper = step(1);

        const box = wrapper.get('[data-testid="tutorial-done"]');
        expect(box.element.checked).toBe(true);
        expect(box.attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="tutorial-done-hint"]').text()).toBe('Done in the portal');
        expect(wrapper.get('[data-testid="tutorial-position"]').text()).toContain('1 done');

        await box.trigger('change');
        expect(box.element.checked).toBe(true);
        expect(JSON.parse(localStorage.getItem('docs-tutorial:7') ?? '[]')).toEqual([]);
    });

    it('merges the portal ticks with the ones saved in this browser, and keeps manual ticks for the rest', async () => {
        localStorage.setItem('docs-tutorial:7', JSON.stringify(['third']));
        page.props = { ...page.props, portalDone: ['first'] };
        resetTutorialProgress();
        const wrapper = step(1);

        expect(wrapper.get('[data-testid="tutorial-position"]').text()).toContain('2 done');
        expect(wrapper.find('[data-testid="tutorial-done-hint"]').exists()).toBe(false);

        await wrapper.get('[data-testid="tutorial-done"]').trigger('change');
        expect(JSON.parse(localStorage.getItem('docs-tutorial:7'))).toEqual(['third', 'second']);
        expect(wrapper.get('[data-testid="tutorial-position"]').text()).toContain('3 done');
    });
});

describe('sub-step letters', () => {
    it('keeps the lettered list the server marks under a numbered step', () => {
        const html = '<h2 id="a">Record</h2><ol type="A" class="docs-substeps"><li>Open it.</li><li>Mark it.</li></ol><ol><li>Plain.</li></ol>';
        const wrapper = step(0, { guide: { title: 'Sign in', summary: 'S.', url: STEPS[0].url, page: null, try: null, minutes: null, html, headings: [] } });

        const lists = wrapper.get('[data-testid="guide-body"]').findAll('ol');
        expect(lists[0].attributes('type')).toBe('A');
        expect(lists[0].classes()).toContain('docs-substeps');
        expect(lists[1].attributes('type')).toBeUndefined();
    });
});
