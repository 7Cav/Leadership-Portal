import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import TutorialBanner from '../partials/TutorialBanner.vue';
import TutorialCoach from '../partials/TutorialCoach.vue';
import { resetTutorial } from '@/composables/useTutorial';
import { resetTutorialProgress } from '@/composables/useTutorialProgress';

const mockPage = reactive({ url: '/rollcall/5', props: {} });
const mockRouter = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => mockPage,
    router: mockRouter,
}));

const STEPS = [
    {
        id: 'rollcall', title: 'Run roll call', body: 'Mark the practice.', href: '/rollcall/5', note: null, guide: 'run-roll-call', done: true,
        checklist: [{ label: 'Open the roll call of 1st Section', done: true }],
    },
    {
        id: 'roster', title: 'Read the roster', body: 'Look at your unit.', href: '/roster', note: 'Practice data only.', guide: 'read-your-roster', done: false,
        checklist: [{ label: 'Open the roster', done: false }],
    },
];

function setTutorial(tutorial) {
    mockPage.props = { auth: { user: { id: 7, username: 'Hale.S' } }, tutorialMode: tutorial };
}

describe('tutorial shell', () => {
    afterEach(() => {
        resetTutorial();
        resetTutorialProgress();
        localStorage.clear();
        vi.clearAllMocks();
    });

    it('shows nothing outside the tutorial', () => {
        setTutorial(null);
        expect(mount(TutorialBanner).find('[data-testid="tutorial-banner"]').exists()).toBe(false);
        expect(mount(TutorialCoach).find('[data-testid="tutorial-coach"]').exists()).toBe(false);
    });

    it('shows the bar with the step and its dots, and exits or starts over', async () => {
        setTutorial({ steps: STEPS });
        const bar = mount(TutorialBanner);

        expect(bar.text()).toContain('Works like the portal, with practice data. Nothing you do here counts.');
        expect(bar.get('[data-testid="tutorial-step-label"]').text()).toBe('Step 2 of 2: Read the roster');
        expect(bar.findAll('[data-testid="tutorial-dots"] i').map((dot) => dot.attributes('data-state'))).toEqual(['done', 'now']);

        await bar.get('[data-testid="tutorial-restart"]').trigger('click');
        expect(mockRouter.post).toHaveBeenCalledWith('/tutorial/restart');
        await bar.get('[data-testid="tutorial-exit"]').trigger('click');
        expect(mockRouter.delete).toHaveBeenCalledWith('/tutorial');
    });

    it('coaches the current step, goes back, and ticks the guide step of a finished one', async () => {
        setTutorial({ steps: STEPS });
        const coach = mount(TutorialCoach);

        expect(coach.get('#tutorial-coach-title').text()).toBe('Read the roster');
        expect(coach.get('[data-testid="tutorial-checklist"] li').attributes('data-done')).toBe('false');
        expect(coach.find('[data-testid="tutorial-last"]').exists()).toBe(true);
        expect(JSON.parse(localStorage.getItem('docs-tutorial:7'))).toEqual(['run-roll-call']);

        await coach.get('[data-testid="tutorial-back"]').trigger('click');
        expect(mockRouter.visit).toHaveBeenCalledWith('/rollcall/5');
        expect(coach.get('#tutorial-coach-title').text()).toBe('Run roll call');
    });
    it('holds Next step until every item is done, and lets Skip move on', async () => {
        const open = { ...STEPS[0], done: false, checklist: [{ label: 'Open the roll call of 1st Section', done: false }] };
        setTutorial({ steps: [open, STEPS[1]] });
        const coach = mount(TutorialCoach);

        expect(coach.get('#tutorial-coach-title').text()).toBe('Run roll call');
        expect(coach.get('[data-testid="tutorial-next"]').attributes('disabled')).toBeDefined();
        await coach.get('[data-testid="tutorial-next"]').trigger('click');
        expect(mockRouter.visit).not.toHaveBeenCalled();

        await coach.get('[data-testid="tutorial-skip"]').trigger('click');
        expect(mockRouter.visit).toHaveBeenCalledWith('/roster');
    });

    it('offers no Skip once the step is done', async () => {
        setTutorial({ steps: STEPS });
        const coach = mount(TutorialCoach);

        await coach.get('[data-testid="tutorial-back"]').trigger('click');
        expect(coach.get('#tutorial-coach-title').text()).toBe('Run roll call');
        expect(coach.find('[data-testid="tutorial-skip"]').exists()).toBe(false);
        expect(coach.get('[data-testid="tutorial-next"]').attributes('disabled')).toBeUndefined();
    });
    it('shows what the step points at in the tutorial blue through a stylesheet, gone once the card is', async () => {
        setTutorial({ steps: [{ ...STEPS[1], href: '/', highlight: '[data-trooper="9"]' }] });
        const coach = mount(TutorialCoach);
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.getElementById('tutorial-target-style').textContent).toContain(':is([data-trooper="9"])');
        coach.unmount();
        expect(document.getElementById('tutorial-target-style')).toBeNull();
    });

    it('points at the sidebar link while the step is on another page', async () => {
        setTutorial({ steps: [{ ...STEPS[1], href: '/reports/4/2026-11-07', nav: '[data-testid="nav-Reports"]', highlight: '#report-notes' }] });
        const coach = mount(TutorialCoach);
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.getElementById('tutorial-target-style').textContent).toContain('[data-testid="nav-Reports"]');
        expect(document.getElementById('tutorial-target-style').textContent).not.toContain('#report-notes');
        coach.unmount();
    });
});
