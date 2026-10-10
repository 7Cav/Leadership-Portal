// Where an attendance mark came from, as the portal says it everywhere (Story 3.9, AD-20, AD-14). The one place the three
// kinds, their words and their colours are defined, so the rollcall, the section overview and the report screens cannot
// each invent their own legend.
//
// The kind is read from the stored `attendance.source` and `attendance.status` and from nothing else: a mark whose status
// is `makeup` is a makeup, whoever made it, and any other mark is auto or manual by its source. A value that is not stored
// (a suggestion carried over, an unsaved choice) and a derived leave have no source, so they get no kind.

export const SOURCE_KINDS = {
    auto: { label: 'auto', title: 'Captured automatically from the game server', dot: 'bg-success' },
    manual: { label: 'manual', title: 'Marked by hand by a leader', dot: 'bg-warning' },
    makeup: { label: 'makeup', title: 'Made up another day', dot: 'bg-info' },
};

/** The order the legend lists them in. */
export const LEGEND_ORDER = ['auto', 'manual', 'makeup'];

/**
 * @param {string|null|undefined} source `attendance.source`: 'manual', 'auto' or null
 * @param {string|null|undefined} status `attendance.status`, such as 'makeup'
 * @returns {'auto'|'manual'|'makeup'|null} null when the value was not stored, or its source is not one we know
 */
export function sourceKind(source, status) {
    if (status === 'makeup') return 'makeup';

    return source === 'auto' || source === 'manual' ? source : null;
}
