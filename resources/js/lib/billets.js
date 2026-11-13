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

// A billet in two units at once (2026-11-13), in words an Admin reads at a glance. `title`: two or more Cav billets
// share one name, each placed in its own unit. `rule`: one billet an Admin placed in one unit while the automatic
// placement (the import's rules, or the unit the billet commands) puts it in another; its holder sits where the Admin
// put it, the first unit listed.
const COUNTS = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'];

// "A", "A and B", "A, B and C".
export function joinAnd(items) {
    if (items.length < 2) return items.join('');
    return `${items.slice(0, -1).join(', ')} and ${items.at(-1)}`;
}

// The badge beside a unit in a billet placed in two units.
export function placementBadge(at) {
    return at.source === 'admin' ? 'placed by Admin' : 'automatic';
}

export function conflictHeading(conflict) {
    const units = new Set(conflict.units.map((at) => at.unitId)).size;
    const many = units === 2 ? 'two' : 'several';
    return conflict.kind === 'title' ? `Same billet name in ${many} units` : `Billet placed in ${many} units`;
}

// Two billets of one name: 'Two Cav billets are both called "X": one in A, one in B.'
export function sameNameSentence(conflict) {
    const byUnit = new Map();
    for (const at of conflict.units) byUnit.set(at.unitId, { label: at.label, count: (byUnit.get(at.unitId)?.count ?? 0) + 1 });
    const count = conflict.units.length;
    const where = [...byUnit.values()].map(({ label, count: here }) => `${(COUNTS[here] ?? String(here)).toLowerCase()} in ${label}`).join(', ');
    return `${COUNTS[count] ?? count} Cav billets are ${count === 2 ? 'both' : 'all'} called "${conflict.title}": ${where}.`;
}

// The units of a billet placed in two units, each once, the one its holder sits in first.
export function placementUnits(conflict) {
    const seen = new Set();
    return conflict.units.filter((at) => !seen.has(at.unitId) && seen.add(at.unitId));
}

// Where a conflict sits besides `unitId`, for that unit's Billets list: "DCOS2", "A/1-7 and B/1-7".
export function otherUnitsLabel(conflict, unitId) {
    return joinAnd([...new Set(conflict.units.filter((at) => at.unitId !== unitId).map((at) => at.label))]);
}

// POST a billet write. `onDone` runs on success; `onError(message, confirm)` gets the server's refusal, with `confirm`
// naming what the Admin may confirm to go ahead: 'move' for a billet placed elsewhere, 'level' for a billet placed in a
// unit of another level than its title names. Null for any other refusal.
export function postBillet(path, data, { failed, onDone, onError, onFinish }) {
    router.post(path, data, {
        preserveScroll: true,
        onSuccess: () => onDone?.(),
        onError: (errors) => {
            if (errors.billet_move) onError?.(errors.billet_move, 'move');
            else if (errors.billet_level) onError?.(errors.billet_level, 'level');
            else onError?.(errors.billet ?? errors.unit_id ?? errors.reason ?? Object.values(errors)[0] ?? `${failed}. Try again.`, null);
        },
        onHttpException: onHttpException(failed, (message) => onError?.(message, null)),
        onFinish: () => onFinish?.(),
    });
}
