import { forumDate } from './forumDate';

// Pure helpers behind the report roll-up workspace (Story 5.8). The server sends the chain as a
// tree of nodes (ReportRollupController::present); everything here only reads it — no KPI is
// computed in the browser, only formatted.

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// The pill vocabulary. Holding uses info so it reads as "waiting on the chain", not late.
export const STATUS = {
    sent: { label: 'Sent', variant: 'success' },
    draft: { label: 'Draft', variant: 'warning' },
    holding: { label: 'Holding', variant: 'info' },
    not_started: { label: 'Not started', variant: 'outline' },
};

export const TYPE_LABEL = {
    regiment: 'Regiment',
    battalion: 'Battalion',
    company: 'Company',
    platoon: 'Platoon',
    section: 'Section',
};

const PLURAL = { battalion: 'Battalions', company: 'Companies', platoon: 'Platoons', section: 'Sections' };

// What the pill says: a sent report is sent; an unsent unit still waiting on a child is holding
// (5.7 / AD-13 — never shown as complete); otherwise its own draft or not-started state.
export function displayStatus(node) {
    if (node.status === 'sent') return 'sent';
    if (node.holding) return 'holding';

    return node.status;
}

// A KPI ratio as a whole percentage, or null when nothing was eligible (rendered "n/a", never 0%).
export function percent(ratio) {
    return ratio && ratio.denominator > 0 ? Math.round((ratio.numerator / ratio.denominator) * 100) : null;
}

// Basis points to signed percentage points, one decimal only when it is not whole.
export function points(bp) {
    if (bp === null || bp === undefined) return null;
    const value = bp / 100;

    return Number.isInteger(value) ? value : Math.round(value * 10) / 10;
}

export function unitLabel(node) {
    return node.callsign || node.name;
}

// `Tue 06Oct26 22:14Z`, read in UTC like every portal timestamp (AD-11).
export function dayTime(value) {
    const date = new Date(value);
    const hh = String(date.getUTCHours()).padStart(2, '0');
    const mm = String(date.getUTCMinutes()).padStart(2, '0');

    return `${WEEKDAYS[date.getUTCDay()]} ${forumDate(date)} ${hh}:${mm}Z`;
}

// Every node by id, with its parent id and tree level (roots are level 1).
export function indexTree(roots) {
    const byId = new Map();
    const walk = (node, parentId, level) => {
        byId.set(node.id, { node, parentId, level });
        node.children.forEach((child) => walk(child, node.id, level + 1));
    };
    roots.forEach((root) => walk(root, null, 1));

    return byId;
}

// The ids expanded on first load: the top two levels, so a company opens on its sections and a
// battalion on its platoons — deeper echelons wait for the reader to open them.
export function defaultExpanded(roots) {
    const expanded = new Set();
    const walk = (node, level) => {
        if (node.children.length && level < 3) expanded.add(node.id);
        node.children.forEach((child) => walk(child, level + 1));
    };
    roots.forEach((root) => walk(root, 1));

    return expanded;
}

// The rows on screen, depth-first: children only under an expanded parent, and with the "not
// sent" filter only branches that still have an unsent report in them.
export function visibleRows(roots, { expanded, filter }) {
    const rows = [];
    const walk = (node, level, parentId) => {
        if (filter === 'open' && !node.open) return;
        rows.push({ node, level, parentId, hasChildren: node.children.length > 0, expanded: expanded.has(node.id) });
        if (expanded.has(node.id)) node.children.forEach((child) => walk(child, level + 1, node.id));
    };
    roots.forEach((root) => walk(root, 1, null));

    return rows;
}

export function countNodes(roots, predicate = () => true) {
    let count = 0;
    const walk = (node) => {
        if (predicate(node)) count += 1;
        node.children.forEach(walk);
    };
    roots.forEach(walk);

    return count;
}

// "Sections sent 5 of 8": the leaves under the scope, plus each echelon between the leaves and
// the top ("Platoons sent 0 of 2"), and where the top unit's own report stands.
export function progress(roots) {
    const leaves = [];
    const byType = new Map();
    const walk = (node, isRoot) => {
        if (!node.children.length) leaves.push(node);
        else if (!isRoot) {
            const tally = byType.get(node.type) ?? { sent: 0, total: 0 };
            tally.total += 1;
            if (node.status === 'sent') tally.sent += 1;
            byType.set(node.type, tally);
        }
        node.children.forEach((child) => walk(child, false));
    };
    roots.forEach((root) => walk(root, true));

    const count = (status) => leaves.filter((leaf) => displayStatus(leaf) === status).length;
    // Mixed leaves (an empty platoon beside sections) are counted as units, not mislabelled.
    const leafType = leaves.every((leaf) => leaf.type === leaves[0]?.type) ? (leaves[0]?.type ?? 'section') : null;

    return {
        label: `${PLURAL[leafType] ?? 'Units'} sent ${count('sent')} of ${leaves.length}`,
        sent: count('sent'),
        total: leaves.length,
        segments: ['sent', 'draft', 'not_started']
            .map((status) => ({ status, label: STATUS[status].label, count: count(status) })),
        echelons: [...byType.entries()].map(([type, tally]) => `${PLURAL[type] ?? type} sent ${tally.sent} of ${tally.total}`),
        rootStatus: roots.length === 1 ? STATUS[displayStatus(roots[0])].label.toLowerCase() : null,
    };
}

// A forum-ready reminder for a leader whose report is not in: the unit, the week and the
// deadline, and where to send it. There is no messaging integration, so it is copied, not sent.
export function reminderText(node, { weekStart, reportDue, origin, now = Date.now() }) {
    const due = dayTime(reportDue);
    const deadline = new Date(reportDue).getTime() > now ? `is due ${due}` : `was due ${due} and is overdue`;
    const unit = node.callsign ? `${node.name} (${node.callsign})` : node.name;

    return `Reminder: the ${unit} weekly report for the week of ${forumDate(weekStart)} ${deadline}. `
        + `Please send it from the Leadership Portal: ${origin}${node.reportUrl}`;
}
