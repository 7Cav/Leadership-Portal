// The model behind the template editor's visual mode (#704). A template is cut into tokens (a run of text, a template code
// such as {{name}} or {{#troopers}}, a BBCode tag such as [B] or [/TD]) that cover every character exactly once, and the
// tokens are paired into a tree. Every pair keeps its open and close exactly as written ("[b]", "{{# troopers }}"), so
// writing the tree out again gives back the template byte for byte: opening a template in the visual mode and saving it
// untouched can never change it. The visual mode is drawn from this tree (`templateDom.js`).
//
// The tree only claims what nests cleanly. What does not (a tag and a section that cross, a tag never closed, a close
// with nothing open, a table holding loose text, a tag whose option the forum would refuse) is kept as a `raw` piece that
// writes out what was written and is shown as code, rather than guessed at. A template that is not valid yet is still
// read whole: checking it is `parse()`'s job in `bbcode.js`, which this file does not replace.
//
// Node shapes, each with `start` and `end`, its place in the source:
//   { type: 'text', value, hidden? }   `hidden` for a line end the forum does not show either (between rows, after a block)
//   { type: 'value', raw, name, filter }                        a printed code, {{name}} or {{name|upper}}
//   { type: 'raw', raw }                                        a token shown as the code it is
//   { type: 'image', raw }                                      a whole [IMG]...[/IMG] whose address is text and codes
//   { type: 'tag', name, option, open, close, children, structured? }   a BBCode pair; `structured` on a table drawn as one
//   { type: 'section', name, inverted, open, close, children, context } a section; `context` is 'flow', 'table' (a block
//                                                               of rows, such as the trooper loop) or 'row' (cells)
// A pair also has `openEnd` and `closeStart`, where its content begins and ends.

import { CODE_TAG } from './bbcode';
import { TAG as BBCODE_TAG, bbcodeElement } from './bbcodeRender';

// The BBCode tags read as pairs. Any other name in brackets is text, as the forum shows it.
const PAIRED = new Set(['b', 'i', 'u', 's', 'color', 'size', 'center', 'left', 'right', 'justify', 'url', 'quote', 'list', 'table', 'tr', 'td', 'th', 'img']);
// Tags that stand alone: a list item and a line. Shown as code.
const SINGLE = new Set(['*', 'hr']);
// Blocks: a line end just inside one, or just after one, is layout rather than a blank line, as in the preview.
const BLOCK = new Set(['center', 'left', 'right', 'justify', 'quote', 'list', 'table']);
const CELLS = new Set(['td', 'th']);

/**
 * Cut a template into tokens that cover every character once, in order.
 *
 * @returns {{kind: 'text'|'code'|'bb', raw: string, start: number, end: number}[]} a `code` also has `mark` ('', '#',
 *   '^' or '/'), `name` and `filter`; a `bb` has `closing`, `name` (lower case) and `option` (as written after "=")
 */
export function tokenize(source) {
    const tokens = [];
    const code = new RegExp(CODE_TAG.source, 'g');
    const bb = new RegExp(BBCODE_TAG.source, 'gi');
    const find = (pattern, from) => {
        pattern.lastIndex = from;

        return pattern.exec(source);
    };

    let at = 0;
    let nextCode = find(code, 0);
    let nextBb = find(bb, 0);

    const text = (to) => {
        if (to <= at) return;
        const last = tokens.at(-1);
        if (last?.kind === 'text' && last.end === at) {
            last.raw += source.slice(at, to);
            last.end = to;
        } else {
            tokens.push({ kind: 'text', raw: source.slice(at, to), start: at, end: to });
        }
        at = to;
    };

    for (;;) {
        if (nextCode && nextCode.index < at) nextCode = find(code, at);
        if (nextBb && nextBb.index < at) nextBb = find(bb, at);
        const match = nextCode && (!nextBb || nextCode.index < nextBb.index) ? nextCode : nextBb;
        if (!match) break;

        const start = match.index;
        const end = start + match[0].length;
        if (match === nextCode) {
            text(start);
            tokens.push({ kind: 'code', raw: match[0], start, end, mark: match[1] ?? '', name: match[2], filter: match[3] });
            at = end;
            continue;
        }

        const name = (match[2] ?? match[4]).toLowerCase();
        if (!PAIRED.has(name) && !SINGLE.has(name)) {
            // Not a tag the forum knows: it stays text, and the search goes on from just inside it.
            text(start + 1);
            nextBb = find(bb, start + 1);
            continue;
        }
        text(start);
        tokens.push({ kind: 'bb', raw: match[0], start, end, closing: match[1] === '/', name, option: match[3] });
        at = end;
    }
    text(source.length);

    return tokens;
}

/**
 * Read a template into the tree the visual mode is drawn from.
 *
 * @returns {object[]} the nodes (see the shapes at the top of this file)
 */
export function buildDoc(source) {
    const root = { children: [] };
    const stack = [root];
    const top = () => stack.at(-1);
    const raw = (token) => ({ type: 'raw', raw: token.raw, start: token.start, end: token.end });

    // A pair never closed, or cut through by another's close, is shown as the code it is, with what it held.
    const unwrapTop = () => {
        const frame = stack.pop();
        top().children.push({ type: 'raw', raw: frame.open, start: frame.start, end: frame.openEnd }, ...frame.children);
    };

    const close = (matches, token) => {
        const index = stack.findLastIndex((frame, at) => at > 0 && matches(frame));
        if (index === -1) {
            top().children.push(raw(token));

            return;
        }
        while (stack.length - 1 > index) unwrapTop();
        const frame = stack.pop();
        Object.assign(frame, { close: token.raw, closeStart: token.start, end: token.end });
        top().children.push(frame);
    };

    for (const token of tokenize(source)) {
        const { start, end } = token;
        if (token.kind === 'text') {
            top().children.push({ type: 'text', value: token.raw, start, end });
        } else if (token.kind === 'code') {
            if (token.mark === '') {
                top().children.push({ type: 'value', raw: token.raw, name: token.name, filter: token.filter, start, end });
            } else if (token.mark === '/') {
                close((frame) => frame.type === 'section' && frame.name === token.name, token);
            } else {
                stack.push({ type: 'section', name: token.name, inverted: token.mark === '^', open: token.raw, start, openEnd: end, children: [] });
            }
        } else if (token.closing) {
            close((frame) => frame.type === 'tag' && frame.name === token.name, token);
        } else if (PAIRED.has(token.name)) {
            stack.push({ type: 'tag', name: token.name, option: token.option, open: token.raw, start, openEnd: end, children: [] });
        } else {
            top().children.push(raw(token));
        }
    }
    while (stack.length > 1) unwrapTop();

    return normalize(root.children, 'flow');
}

/** Write a tree out as the template it was read from. */
export function serialize(nodes) {
    let out = '';
    for (const node of nodes) {
        if (node.type === 'text') out += node.value;
        else if (node.type === 'tag' || node.type === 'section') out += node.open + serialize(node.children) + node.close;
        else out += node.raw;
    }

    return out;
}

/**
 * What may go at a place in a template: inside the trooper loop, the trooper's own codes may; outside it they may not.
 * A cursor in the middle of a code counts as just before it. Answers for a template that is not valid yet as well.
 */
export function contextAt(source, offset) {
    const open = [];
    for (const token of tokenize(source)) {
        if (token.end > offset) break;
        if (token.kind !== 'code' || token.mark === '') continue;
        if (token.mark === '/') {
            const index = open.lastIndexOf(token.name);
            if (index !== -1) open.length = index;
        } else {
            open.push(token.name);
        }
    }

    return { inRow: open.includes('troopers') };
}

const whitespace = (node) => node.type === 'text' && node.value.trim() === '';

// What a table may hold to be drawn as one: rows, a section of rows one deep (the trooper loop), and the line ends
// between them; a row likewise holds cells, a section of cells one deep, and line ends.
function holdsOnly(children, part, allowSection) {
    return children.every((child) => {
        if (whitespace(child)) return true;
        if (child.type === 'tag' && part(child)) return true;

        return allowSection && child.type === 'section' && holdsOnly(child.children, part, false);
    });
}

const isRow = (node) => node.name === 'tr' && node.option === undefined && holdsOnly(node.children, isCell, true);
const isCell = (node) => CELLS.has(node.name) && node.option === undefined;
const isTable = (node) => node.option === undefined && holdsOnly(node.children, isRow, true);

function unwrap(node, context) {
    return [
        { type: 'raw', raw: node.open, start: node.start, end: node.openEnd },
        ...normalize(node.children, context),
        { type: 'raw', raw: node.close, start: node.closeStart, end: node.end },
    ];
}

function normalize(nodes, context) {
    const out = [];
    for (const node of nodes) {
        if (node.type === 'text') {
            out.push(context === 'flow' ? node : { ...node, hidden: true });
        } else if (node.type === 'section') {
            out.push({ ...node, context, children: normalize(node.children, context) });
        } else if (node.type !== 'tag') {
            out.push(node);
        } else if (node.name === 'table') {
            out.push(...(context === 'flow' && isTable(node) ? [{ ...node, structured: true, children: normalize(node.children, 'table') }] : unwrap(node, context)));
        } else if (node.name === 'tr') {
            out.push(...(context === 'table' ? [{ ...node, children: normalize(node.children, 'row') }] : unwrap(node, context)));
        } else if (CELLS.has(node.name)) {
            out.push(...(context === 'row' ? [{ ...node, children: trimEnd(normalize(node.children, 'flow')) }] : unwrap(node, context)));
        } else if (node.name === 'img') {
            out.push(...(isAddress(node.children) ? [{ type: 'image', raw: serialize([node]), start: node.start, end: node.end }] : unwrap(node, context)));
        } else if (context === 'flow' && bbcodeElement(node.name, node.option)) {
            const children = normalize(node.children, 'flow');
            out.push({ ...node, children: BLOCK.has(node.name) ? trimEnd(trimStart(children)) : children });
        } else {
            out.push(...unwrap(node, context));
        }
    }

    return context === 'flow' ? afterBlocks(out) : out;
}

// An image holds its address: text on one line and printed codes.
const isAddress = (children) => children.every((child) => child.type === 'value' || (child.type === 'text' && !/[\r\n]/.test(child.value)));

const LEADING_BREAK = /^\r?\n/;
const TRAILING_BREAK = /\r?\n$/;

// Splits a text node at `at` characters in, so a line end the forum does not show can be hidden on its own.
function split(node, at) {
    return [
        { ...node, value: node.value.slice(0, at), end: node.start + at },
        { ...node, value: node.value.slice(at), start: node.start + at },
    ].filter((part) => part.value !== '');
}

function trimStart(children) {
    const first = children[0];
    const found = first?.type === 'text' && !first.hidden ? first.value.match(LEADING_BREAK) : null;
    if (!found) return children;
    const [line, rest] = split(first, found[0].length);

    return [{ ...line, hidden: true }, ...(rest && rest.start === line.end ? [rest] : []), ...children.slice(1)];
}

function trimEnd(children) {
    const last = children.at(-1);
    const found = last?.type === 'text' && !last.hidden ? last.value.match(TRAILING_BREAK) : null;
    if (!found) return children;
    const parts = split(last, last.value.length - found[0].length);
    const line = parts.at(-1);

    return [...children.slice(0, -1), ...(parts.length > 1 ? [parts[0]] : []), { ...line, hidden: true }];
}

function afterBlocks(nodes) {
    const out = [];
    nodes.forEach((node, index) => {
        const before = nodes[index - 1];
        const afterBlock = before?.type === 'tag' && BLOCK.has(before.name);
        out.push(...(afterBlock && node.type === 'text' && !node.hidden ? trimStart([node]) : [node]));
    });

    return out;
}
