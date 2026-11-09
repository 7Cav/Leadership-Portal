import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { currentIndex, finishedGuides } from '@/lib/tutorial';
import { useTutorialProgress } from '@/composables/useTutorialProgress';

// The hands-on tutorial in the shell: whether this page runs in it (the shared `tutorial` prop, set only by the server
// while the request runs on the person's practice copy), the step shown, and the three actions. The step picked with
// Next or Back is kept for the browser tab only, so a reload stays on it.

const picked = ref(null);
const STORAGE_KEY = 'portal-tutorial-step';

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

// Set once Exit is chosen, so the coach card stops reading practice pages while the real portal loads.
export const leaving = ref(false);

export function useTutorial() {
    const page = usePage();
    const steps = computed(() => page.props.tutorialMode?.steps ?? []);
    const active = computed(() => page.props.tutorialMode != null);
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

    function restart() {
        picked.value = null;
        writePicked(null);
        router.post('/tutorial/restart');
    }

    function exit() {
        leaving.value = true;
        picked.value = null;
        writePicked(null);
        router.delete('/tutorial');
    }

    return {
        active,
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
    leaving.value = false;
}
