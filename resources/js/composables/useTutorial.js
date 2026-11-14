import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { currentIndex, finishedGuides, withClicks } from '@/lib/tutorial';
import { useTutorialProgress } from '@/composables/useTutorialProgress';

// The hands-on tutorial in the shell: whether this page runs in it (the shared `tutorial` prop, set only by the server
// while the request runs on the person's practice copy), the step shown, and the three actions. The step picked with
// Next or Back is kept for the browser tab only, so a reload stays on it.

const picked = ref(null);
const STORAGE_KEY = 'portal-tutorial-step';
// Checklist items ticked by a click in this tab (`step id:item index`), for actions the server never sees.
const clicked = ref([]);
const CLICKS_KEY = 'portal-tutorial-clicks';

function readClicks() {
    try {
        const value = JSON.parse(sessionStorage.getItem(CLICKS_KEY) ?? '[]');

        return Array.isArray(value) ? value : [];
    } catch {
        return [];
    }
}

function writeClicks(keys) {
    try {
        if (keys.length) sessionStorage.setItem(CLICKS_KEY, JSON.stringify(keys));
        else sessionStorage.removeItem(CLICKS_KEY);
    } catch {
        // Storage is unavailable: the ticks hold for this page only.
    }
}

/** Tick every checklist item whose `click` selector matches `target` (a clicked element or one inside it). */
export function recordClick(steps, target) {
    const keys = new Set(clicked.value);
    for (const step of steps ?? []) {
        (step.checklist ?? []).forEach((item, i) => {
            if (item.click && target?.closest?.(item.click)) keys.add(`${step.id}:${i}`);
        });
    }
    if (keys.size === clicked.value.length) return;
    clicked.value = [...keys];
    writeClicks(clicked.value);
}

function readPicked() {
    try {
        return sessionStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function writePicked(id) {
    try {
        if (id == null) sessionStorage.removeItem(STORAGE_KEY);
        else sessionStorage.setItem(STORAGE_KEY, id);
    } catch {
        // Storage is unavailable: the step holds for this page only.
    }
}

picked.value = typeof window === 'undefined' ? null : readPicked();
clicked.value = typeof window === 'undefined' ? [] : readClicks();

// Set once Exit is chosen, so the coach card stops reading practice pages while the real portal loads.
export const leaving = ref(false);

export function useTutorial() {
    const page = usePage();
    const steps = computed(() => withClicks(page.props.tutorialMode?.steps ?? [], clicked.value));
    const active = computed(() => page.props.tutorialMode != null);
    const title = computed(() => page.props.tutorialMode?.title ?? 'Tutorial');
    const index = computed(() => currentIndex(steps.value, picked.value));
    const step = computed(() => steps.value[index.value] ?? null);
    // Next step waits until every item of this step is done; Skip moves on without them.
    const stepDone = computed(() => (step.value?.checklist ?? []).every((item) => item.done));

    // A finished step ticks the matching step of the getting started guide (resources/docs/tutorial).
    const guide = useTutorialProgress(
        () => [],
        () => ({ userId: page.props.auth?.user?.id ?? null, previewing: false, portal: [] }),
    );
    watch(
        () => finishedGuides(steps.value),
        (ids) => ids.forEach((id) => guide.mark(id)),
        { immediate: true },
    );

    function go(offset) {
        const next = steps.value[index.value + offset];
        if (!next) return;
        picked.value = next.id;
        writePicked(next.id);
        router.visit(next.href);
    }

    function forgetClicks() {
        clicked.value = [];
        writeClicks([]);
    }

    function restart() {
        picked.value = null;
        writePicked(null);
        forgetClicks();
        router.post('/tutorial/restart');
    }

    function exit() {
        leaving.value = true;
        picked.value = null;
        writePicked(null);
        forgetClicks();
        router.delete('/tutorial');
    }

    return {
        active,
        title,
        steps,
        index,
        step,
        stepDone,
        next: () => stepDone.value && go(1),
        skip: () => go(1),
        back: () => go(-1),
        restart,
        exit,
    };
}

// For the tests: forget the picked step.
export function resetTutorial() {
    picked.value = null;
    clicked.value = [];
    leaving.value = false;
}
