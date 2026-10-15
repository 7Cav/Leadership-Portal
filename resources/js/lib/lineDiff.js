// A line-by-line diff of two texts, for comparing template versions (Settings, Templates). It finds the longest common
// subsequence of lines (LCS), so the lines both texts share stay in step and only the rest shows as removed or added.
// Pure: no DOM, no Vue.

// Past this many cells the LCS table would be slow to build in the browser. The common start and end are trimmed first,
// so only two texts that differ almost everywhere get near it; they are shown as the old middle removed and the new
// middle added, which is still correct, only less fine-grained.
export const MAX_CELLS = 4_000_000;

const linesOf = (text) => (text === '' ? [] : text.replace(/\r\n?/g, '\n').split('\n'));

/**
 * The lines of `before` and `after` in order, each marked as kept, removed (only in `before`) or added (only in `after`),
 * with its line number on each side it is on. A removed line comes before the added lines that replace it.
 *
 * @param {string} before
 * @param {string} after
 * @param {{maxCells?: number}} [options]
 * @returns {{type: 'same' | 'removed' | 'added', text: string, oldLine: ?number, newLine: ?number}[]}
 */
export function diffLines(before, after, { maxCells = MAX_CELLS } = {}) {
    const a = linesOf(before);
    const b = linesOf(after);

    let start = 0;
    while (start < a.length && start < b.length && a[start] === b[start]) start++;

    let endA = a.length;
    let endB = b.length;
    while (endA > start && endB > start && a[endA - 1] === b[endB - 1]) {
        endA--;
        endB--;
    }

    const out = [];
    const same = (i, j) => out.push({ type: 'same', text: a[i], oldLine: i + 1, newLine: j + 1 });
    const removed = (i) => out.push({ type: 'removed', text: a[i], oldLine: i + 1, newLine: null });
    const added = (j) => out.push({ type: 'added', text: b[j], oldLine: null, newLine: j + 1 });

    for (let i = 0; i < start; i++) same(i, i);

    const n = endA - start;
    const m = endB - start;

    if (n * m > maxCells) {
        for (let i = start; i < endA; i++) removed(i);
        for (let j = start; j < endB; j++) added(j);
    } else {
        // lengths[i * (m + 1) + j]: the LCS of a[start + i ..] and b[start + j ..], filled from the end so the walk below
        // can go forwards.
        const width = m + 1;
        const lengths = new Uint32Array((n + 1) * width);
        for (let i = n - 1; i >= 0; i--) {
            for (let j = m - 1; j >= 0; j--) {
                lengths[i * width + j] =
                    a[start + i] === b[start + j] ? lengths[(i + 1) * width + j + 1] + 1 : Math.max(lengths[(i + 1) * width + j], lengths[i * width + j + 1]);
            }
        }

        let i = 0;
        let j = 0;
        while (i < n && j < m) {
            if (a[start + i] === b[start + j]) {
                same(start + i, start + j);
                i++;
                j++;
            } else if (lengths[(i + 1) * width + j] >= lengths[i * width + j + 1]) {
                removed(start + i);
                i++;
            } else {
                added(start + j);
                j++;
            }
        }
        for (; i < n; i++) removed(start + i);
        for (; j < m; j++) added(start + j);
    }

    for (let i = endA, j = endB; i < a.length; i++, j++) same(i, j);

    return out;
}

/**
 * The diff with long runs of kept lines folded away: each change keeps `context` kept lines on either side, and a run
 * longer than that becomes one `skip` entry saying how many lines it hides.
 *
 * @param {ReturnType<typeof diffLines>} lines
 * @param {number} [context]
 * @returns {({type: 'same' | 'removed' | 'added', text: string, oldLine: ?number, newLine: ?number} | {type: 'skip', count: number})[]}
 */
export function foldUnchanged(lines, context = 3) {
    const out = [];
    let i = 0;

    while (i < lines.length) {
        if (lines[i].type !== 'same') {
            out.push(lines[i]);
            i++;
            continue;
        }

        let end = i;
        while (end < lines.length && lines[end].type === 'same') end++;

        const keepBefore = i === 0 ? 0 : context;
        const keepAfter = end === lines.length ? 0 : context;

        if (end - i <= keepBefore + keepAfter) {
            out.push(...lines.slice(i, end));
        } else {
            out.push(...lines.slice(i, i + keepBefore));
            out.push({ type: 'skip', count: end - i - keepBefore - keepAfter });
            out.push(...lines.slice(end - keepAfter, end));
        }
        i = end;
    }

    return out;
}

/**
 * How many lines were added and removed.
 *
 * @param {ReturnType<typeof diffLines>} lines
 */
export function diffCounts(lines) {
    return {
        added: lines.filter((line) => line.type === 'added').length,
        removed: lines.filter((line) => line.type === 'removed').length,
    };
}
