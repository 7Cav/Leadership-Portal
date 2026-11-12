// The billets on Settings, Org structure (2026-11-13): words shared by the unit's Billets list and the summary card,
// and the one way they send a billet write. The server is the boundary: Admin only, a reason where one is required,
// and every write audited.
import { router } from '@inertiajs/vue3';
import { onHttpException } from '@/lib/httpError';

export function holdersLabel(count) {
    if (!count) return 'vacant';
    return `${count} ${count === 1 ? 'holder' : 'holders'}`;
}

// Why the billet lands in its unit, as the list shows it beside the title.
export function sourceLabel(billet) {
    if (billet.source === 'command') return 'Commands this unit';
    if (billet.source === 'admin') return 'Placed by an Admin';
    return billet.rule ? `Rule: ${billet.rule}` : 'Rule';
}

// Where a billet in a conflict lands and why, such as "B/1-7 (placed by an Admin)".
export function conflictUnitLabel(at) {
    const why = { admin: 'placed by an Admin', rule: 'by the import rules', command: 'commands it' }[at.source] ?? at.source;
    return `${at.label} (${why})`;
}

// POST a billet write. `onDone` runs on success; `onError(message, needsMove)` gets the server's refusal, with
// `needsMove` true when the billet is placed elsewhere and the Admin may confirm moving it here.
export function postBillet(path, data, { failed, onDone, onError, onFinish }) {
    router.post(path, data, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => {
            if (errors.billet_move) onError?.(errors.billet_move, true);
            else onError?.(errors.billet ?? errors.unit_id ?? errors.reason ?? Object.values(errors)[0] ?? `${failed}. Try again.`, false);
        },
        onHttpException: onHttpException(failed, (message) => onError?.(message, false)),
        onFinish: () => onFinish?.(),
    });
}
