// The rollcall section a leader last opened, kept per portal user in this browser so the picker
// can open it straight away next time. Storage can be blocked (private mode, disabled site data);
// then nothing is remembered and the picker shows as before.
const KEY = 'portal-rollcall-section';

const keyFor = (userId) => `${KEY}:${userId}`;

export function rememberSection(userId, sectionId) {
    if (!userId || !sectionId) return;

    try {
        localStorage.setItem(keyFor(userId), String(sectionId));
    } catch {
        // Intentionally ignored: see above.
    }
}

// The remembered section, only when it is still one of `sections` (the viewer's scope, as the
// server sent it). Anything else, including a section that has left the scope, is null.
export function rememberedSection(userId, sections) {
    if (!userId) return null;

    let stored = null;
    try {
        stored = localStorage.getItem(keyFor(userId));
    } catch {
        return null;
    }

    const id = Number(stored);

    return stored && Number.isInteger(id) ? (sections.find((section) => section.id === id) ?? null) : null;
}
