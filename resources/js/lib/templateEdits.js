// The template editor's toolbar and code picker (#704). Every edit takes the template text and a selection in it (`start`
// and `end`, as offsets) and gives back the new text with the selection to restore, so one implementation serves the
// visual mode and the Source tab alike. An edit that would break what was whole is refused with null: a wrap that would
// leave tags crossing each other, a selection that ends inside a code, a column outside a table. The editor then leaves
// the template as it was and says why.

import { contextAt, buildDoc, tokenize } from './templateDoc';
import { bbcodeElement, safeLink } from './bbcodeRender';

/** @typedef {{source: string, start: number, end: number}} Edited */

// Applies non-overlapping edits `{start, end, text}`, and maps a place in the old text to the new one. A place at an
// insertion stays before what went in.
function apply(source, edits) {
    const sorted = [...edits].sort((a, b) => a.start - b.start);
    let out = '';
    let at = 0;
    for (const edit of sorted) {
        out += source.slice(at, edit.start) + edit.text;
        at = edit.end;
    }
    out += source.slice(at);

    const map = (place) => {
        let shift = 0;
        for (const edit of sorted) {
            if (edit.end <= place && edit.start < place) shift += edit.text.length - (edit.end - edit.start);
            else if (edit.start < place) return edit.start + edit.text.length + shift;
        }

        return place + shift;
    };

    return { source: out, map };
}

function countRaw(nodes) {
    return nodes.reduce((count, node) => count + (node.type === 'raw' ? 1 : 0) + (node.children ? countRaw(node.children) : 0), 0);
}

// An edit is kept only when it leaves everything that was whole still whole: no more pieces of raw code than before.
function keepsWhole(before, after) {
    return countRaw(buildDoc(after)) <= countRaw(buildDoc(before));
}

// A place in the middle of a tag or a code, where nothing may be cut.
function insideToken(source, place) {
    return tokenize(source).some((token) => token.kind !== 'text' && place > token.start && place < token.end);
}

function wrap(source, start, end, open, close, caretAfter = false) {
    if (insideToken(source, start) || insideToken(source, end)) return null;
    const result = source.slice(0, start) + open + source.slice(start, end) + close + source.slice(end);
    if (!keepsWhole(source, result)) return null;
    if (caretAfter) {
        const caret = start === end ? start + open.length : end + open.length + close.length;

        return { source: result, start: caret, end: caret };
    }

    return { source: result, start: start + open.length, end: end + open.length };
}

// Whether [start, end) takes one end of a pair (a tag, a section, a table's row or cell) and leaves the other: removing
// "[/TD][TD]" would merge two cells into one, which is a valid template but not what anyone selected text to do.
function cutsPair(nodes, start, end) {
    return nodes.some((node) => {
        if (!node.children) return false;
        const openTaken = start <= node.start && node.openEnd <= end;
        const closeTaken = start <= node.closeStart && node.end <= end;

        return openTaken !== closeTaken || cutsPair(node.children, start, end);
    });
}

function replace(source, start, end, text) {
    if (insideToken(source, start) || insideToken(source, end)) return null;
    if (start !== end && cutsPair(buildDoc(source), start, end)) return null;
    const result = source.slice(0, start) + text + source.slice(end);
    if (!keepsWhole(source, result)) return null;

    return { source: result, start: start + text.length, end: start + text.length };
}

// The innermost node, walking down from `nodes`, whose content holds the whole of [start, end], with its ancestors.
function enclosing(nodes, start, end, matches, path = []) {
    for (const node of nodes) {
        if (!node.children || node.openEnd > start || node.closeStart < end) continue;
        const inner = enclosing(node.children, start, end, matches, [...path, node]);
        if (inner) return inner;
        if (matches(node)) return { node, path };
    }

    return null;
}

const tagName = (name) => (node) => node.type === 'tag' && node.name === name;

/**
 * Bold, italic, underline, strike or centre: wraps the selection in the tag, or takes the tag off when the selection is
 * already inside one.
 *
 * @returns {Edited | null}
 */
export function toggleTag(source, start, end, name) {
    const found = enclosing(buildDoc(source), start, end, tagName(name));
    if (found) {
        const { node } = found;
        const { source: result, map } = apply(source, [
            { start: node.start, end: node.openEnd, text: '' },
            { start: node.closeStart, end: node.end, text: '' },
        ]);

        return { source: result, start: map(start), end: map(end) };
    }
    const tag = name.toUpperCase();

    return wrap(source, start, end, `[${tag}]`, `[/${tag}]`);
}

/**
 * A colour or a size: changes the one the selection is inside, or wraps the selection in a new one. Refuses a value the
 * forum would not take.
 *
 * @returns {Edited | null}
 */
export function setOption(source, start, end, name, value) {
    const option = String(value).trim();
    if (!bbcodeElement(name, option)) return null;
    const open = `[${name.toUpperCase()}=${option}]`;

    const found = enclosing(buildDoc(source), start, end, tagName(name));
    if (found) {
        const { node } = found;
        const { source: result, map } = apply(source, [{ start: node.start, end: node.openEnd, text: open }]);

        return { source: result, start: map(start), end: map(end) };
    }

    return wrap(source, start, end, open, `[/${name.toUpperCase()}]`);
}

/**
 * Whether an address can go in a link or an image: a full http or https one, with no bracket (which would end the tag
 * early) and no code mark (which would break the codes around it).
 */
export function usableAddress(address) {
    const written = String(address ?? '').trim();

    return safeLink(written) !== null && !/[[\]]|\{\{|\}\}/.test(written);
}

/**
 * Text typed or pasted over the selection, the cursor after it. Refused when the selection cuts through a section, a tag
 * or a table, which would leave half of it behind.
 *
 * @returns {Edited | null}
 */
export function insertText(source, start, end, text) {
    return replace(source, start, end, text);
}

/**
 * Links the selection to an address, or writes the address as its own link text when nothing is selected. Only a usable
 * address (`usableAddress`).
 *
 * @returns {Edited | null}
 */
export function insertLink(source, start, end, address) {
    if (!usableAddress(address)) return null;
    const written = String(address).trim();
    if (start === end) return replace(source, start, end, `[URL=${written}]${written}[/URL]`);

    return wrap(source, start, end, `[URL=${written}]`, '[/URL]', true);
}

const ONE_CODE = /^\{\{[a-z_]+\}\}$/;

/**
 * An image at the cursor, from a full http or https address or a code that prints one ({{rc_image}}).
 *
 * @returns {Edited | null}
 */
export function insertImage(source, start, end, address) {
    const written = String(address).trim();
    if (!ONE_CODE.test(written) && !usableAddress(written)) return null;

    return replace(source, start, end, `[IMG]${written}[/IMG]`);
}

/**
 * A code from the picker: a value in place of the selection, or a section's open and close around it (with the cursor
 * between them when nothing is selected).
 *
 * @param {{open: string, close: string}} entry
 * @returns {Edited | null}
 */
export function insertCode(source, start, end, entry) {
    if (entry.close === '') return replace(source, start, end, entry.open);

    return wrap(source, start, end, entry.open, entry.close, true);
}

const TABLE = `[TABLE]
[TR][TH]#[/TH][TH]Name[/TH][TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]
{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{display_name}}[/TD][TD]{{rc}}[/TD][TD]{{sp}}[/TD][/TR]
{{/troopers}}[/TABLE]`;

/**
 * A trooper table at the cursor: a header row and one row repeated for each trooper. Not inside the trooper loop, where
 * a second loop is not allowed.
 *
 * @returns {Edited | null}
 */
export function insertTable(source, start, end) {
    if (contextAt(source, start).inRow) return null;

    return replace(source, start, end, TABLE);
}

// The cells of a row in order, each with the section it alone fills (a column shown under a condition), if any.
function cellsOf(row) {
    return row.children.flatMap((child) => {
        if (child.type === 'tag') return [{ cell: child, span: child }];
        if (child.type !== 'section') return [];
        const cells = child.children.filter((inner) => inner.type === 'tag');

        return cells.map((cell) => ({ cell, span: cells.length === 1 ? child : cell }));
    });
}

const rowsOf = (table) => table.children.flatMap((child) => (child.type === 'section' ? child.children : [child])).filter((child) => child.type === 'tag');

// The cell at `place`, its column, and the rows of its table that are as wide as its own.
function column(source, place) {
    const found = enclosing(buildDoc(source), place, place, (node) => node.type === 'tag' && (node.name === 'td' || node.name === 'th'));
    if (!found) return null;
    const row = found.path.findLast(tagName('tr'));
    const table = found.path.findLast((node) => node.type === 'tag' && node.name === 'table' && node.structured);
    if (!row || !table) return null;

    const cells = cellsOf(row);
    const index = cells.findIndex((entry) => entry.cell === found.node);
    const rows = rowsOf(table).map(cellsOf).filter((entries) => entries.length === cells.length);

    return { index, width: cells.length, rows, row: cells };
}

/**
 * A column after the cursor's, in every row of the table as wide as the cursor's row: a header cell named "Column" after
 * a header cell, an empty cell after any other. The cursor goes into the new cell in its own row.
 *
 * @returns {Edited | null}
 */
export function addColumn(source, place) {
    const found = column(source, place);
    if (!found) return null;

    const edits = found.rows.map((entries) => {
        const { cell, span } = entries[found.index];

        return { start: span.end, end: span.end, text: cell.name === 'th' ? '[TH]Column[/TH]' : '[TD][/TD]' };
    });
    const { source: result, map } = apply(source, edits);
    const { cell, span } = found.row[found.index];
    const start = map(span.end) + '[TH]'.length;

    return { source: result, start, end: cell.name === 'th' ? start + 'Column'.length : start };
}

/**
 * Removes the cursor's column from every row of the table as wide as the cursor's row, with the condition around it when
 * the condition held only that cell. A row's last column stays.
 *
 * @returns {Edited | null}
 */
export function removeColumn(source, place) {
    const found = column(source, place);
    if (!found || found.width < 2) return null;

    const edits = found.rows.map((entries) => {
        const { span } = entries[found.index];

        return { start: span.start, end: span.end, text: '' };
    });
    const { source: result, map } = apply(source, edits);
    const caret = map(found.row[found.index].span.start);

    return { source: result, start: caret, end: caret };
}
