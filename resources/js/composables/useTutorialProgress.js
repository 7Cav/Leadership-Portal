import { computed, reactive } from 'vue';

// The getting started tutorial's progress: which steps this viewer has finished. Kept in this browser, like Start
// here (useStartHere): keyed by user id so two people on one computer never share it, held for the visit only during a
// view-as preview, and still working for the visit when browser storage is unavailable.

const store = reactive({ key: null, done: [], saving: true });

const storageKey = (userId) => `docs-tutorial:${userId}`;

function read(key) {
    try {
        const saved = JSON.parse(localStorage.getItem(key) ?? '[]');

        return Array.isArray(saved) ? saved.filter((id) => typeof id === 'string') : [];
    } catch {
        return [];
    }
}

function write(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage is unavailable: progress lasts for this visit only.
    }
}

function load(userId, previewing) {
    const key = `${userId}:${previewing ? 'preview' : 'own'}`;
    if (store.key === key) return;

    store.key = key;
    store.saving = !previewing && userId != null;
    store.done = store.saving ? read(storageKey(userId)) : [];
}

/**
 * @param {() => Array<{id: string}>} steps every step of the tutorial, as a getter
 * @param {() => {userId: number|null, previewing: boolean}} viewer who is reading, as a getter
 */
export function useTutorialProgress(steps, viewer) {
    const sync = () => {
        const { userId, previewing } = viewer();
        load(userId, previewing);

        return userId;
    };
    sync();

    const ids = () => steps().map((step) => step.id);
    // A saved id for a step that is no longer in the tutorial is ignored, never counted.
    const done = computed(() => (sync(), store.done.filter((id) => ids().includes(id))));
    const isDone = (id) => done.value.includes(id);

    function mark(id, value = true) {
        const userId = sync();
        const others = store.done.filter((saved) => saved !== id);
        store.done = value ? [...others, id] : others;
        if (store.saving) write(storageKey(userId), store.done);
    }

    return {
        done,
        isDone,
        mark,
        count: computed(() => done.value.length),
        total: computed(() => steps().length),
        finished: computed(() => steps().length > 0 && done.value.length === steps().length),
    };
}

// For the tests: forget what was loaded, so the next use reads storage again.
export function resetTutorialProgress() {
    store.key = null;
    store.done = [];
    store.saving = true;
}
