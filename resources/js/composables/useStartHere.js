import { computed, reactive } from 'vue';

// The "Start here" checklist's progress (#412). Kept in this browser on purpose for this version: per-user storage in
// the database is a follow-up if people ask for it on a second device. One module-level store, so the card in the
// right rail and a guide's "Tick it off" button can never disagree.
//
// - Keyed by user id, so two people on one computer never share ticks.
// - During a view-as preview the card shows the previewed role's items, and ticks last only for the visit: a preview
//   changes nothing, here as anywhere else.
// - A saved id whose item no longer exists for this viewer is ignored, never counted.
// - On a narrow screen the card starts collapsed until the viewer opens or closes it themselves.
// - When browser storage is unavailable (private mode, blocked site data) the card still works for the visit.

const store = reactive({ key: null, done: [], collapsed: false, saving: true });

function read(key, fallback) {
    try {
        const raw = localStorage.getItem(key);

        return raw === null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

function write(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage is unavailable: progress lasts for this visit only.
    }
}

const doneKey = (userId) => `docs-start-here:${userId}`;
const collapsedKey = (userId) => `docs-start-here-collapsed:${userId}`;

// Loads the viewer's saved progress once per viewer (and again when a preview starts or ends).
function load(userId, previewing) {
    const key = `${userId}:${previewing ? 'preview' : 'own'}`;
    if (store.key === key) return;

    store.key = key;
    store.saving = !previewing && userId != null;
    const saved = store.saving ? read(doneKey(userId), []) : [];
    store.done = Array.isArray(saved) ? saved.filter((id) => typeof id === 'string') : [];
    // Until the viewer chooses, the card starts collapsed on a narrow screen, where it sits below the guide.
    const chosen = userId != null ? read(collapsedKey(userId), null) : null;
    store.collapsed = chosen === null ? narrow() : chosen === true;
}

function narrow() {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 1279.98px)').matches;
}

/**
 * @param {() => Array<{id: string, label: string, url: string}>} items the viewer's items, as a getter
 * @param {() => {userId: number|null, previewing: boolean}} viewer who is reading, as a getter
 */
export function useStartHere(items, viewer) {
    const current = () => {
        const { userId, previewing } = viewer();
        load(userId, previewing);

        return userId;
    };

    const visible = computed(() => {
        current();

        return items();
    });
    const doneIds = computed(() => {
        current();

        return new Set(store.done);
    });
    const done = computed(() => visible.value.filter((item) => doneIds.value.has(item.id)).length);
    const total = computed(() => visible.value.length);
    const complete = computed(() => total.value > 0 && done.value === total.value);
    const collapsed = computed(() => {
        current();

        return store.collapsed;
    });

    const isDone = (id) => doneIds.value.has(id);

    function setDone(id, value) {
        const userId = current();
        const others = store.done.filter((saved) => saved !== id);
        store.done = value ? [...others, id] : others;
        if (store.saving) write(doneKey(userId), store.done);
    }

    function toggleCollapsed() {
        const userId = current();
        store.collapsed = !store.collapsed;
        if (userId != null) write(collapsedKey(userId), store.collapsed);
    }

    return { items: visible, done, total, complete, collapsed, isDone, setDone, toggleCollapsed };
}

// Test hook: forget the loaded viewer so the next use reads storage again.
export function resetStartHere() {
    store.key = null;
    store.done = [];
    store.collapsed = false;
    store.saving = true;
}
