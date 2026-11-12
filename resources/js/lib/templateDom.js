// The template editor's visual mode on the page (#704): draws the tree from `templateDoc.js` as elements a leader can type
// into, and reads what is on the page back into the template.
//
// Every element drawn carries the template text it stands for, so reading it back needs nothing but the page:
//   data-raw="..."                       a piece written out exactly as it stands: a code chip, a piece of raw code, an
//                                        image, a hidden line end, a section's end. It cannot be edited, so it is there
//                                        whole or not at all.
//   data-open="..." data-close="..."     formatting, a table, a row or a cell: its open, what is inside, its close
//   data-pair                            a section, its two ends marked `data-edge`. In running text the ends are chips
//                                        ("If Will attend" ... "End"), and if the leader deletes either, both go and what
//                                        it showed stays. A block of rows (the trooper loop) opens with a label row and
//                                        closes with a hidden row; a group of cells has both ends hidden. Neither can be
//                                        deleted from the page.
// Text is read as it stands. Anything else (formatting the browser added while editing) is read as its text, a line the
// browser started is read as a line end and a line break element as nothing (see `walk`), so an edit can never put
// markup into the template that nobody chose.
//
// Nothing here builds HTML from a string: every element is created and every text is a text node, so nothing in a
// template can add markup or a script to the page.

import { LABELLED, codeLabel } from './bbcode';
import { bbcodeElement } from './bbcodeRender';

const LOOP_LABELS = { troopers: 'Repeats for each trooper', notroopers: 'When the section has no troopers' };

/** What a section is called on its chip: "Each trooper", "If Rank insignia", "If not Will attend". */
export function sectionLabel(name, inverted) {
    if (name === 'troopers') return inverted ? 'No troopers' : 'Each trooper';

    return `${inverted ? 'If not' : 'If'} ${codeLabel(name)}`;
}

/** What a printed code is called on its chip: "Trooper name", "Week in capitals". */
export function valueLabel(name, filter) {
    return filter === undefined ? codeLabel(name) : `${codeLabel(name)} ${codeLabel(filter)}`;
}

const IMAGE = /^\[img[^\]]*\]([\s\S]*)\[\/img\]$/i;
const ONE_CODE = /^\{\{\s*([a-z_]+)\s*\}\}$/;

function imageLabel(raw) {
    const address = raw.match(IMAGE)?.[1] ?? '';
    const code = address.match(ONE_CODE);

    return `Image: ${code ? codeLabel(code[1]) : address}`;
}

function chip(doc, raw, label, spoken, className, tag = 'span') {
    const element = doc.createElement(tag);
    element.className = className;
    element.setAttribute('contenteditable', 'false');
    element.dataset.raw = raw;
    element.setAttribute('role', 'img');
    element.setAttribute('aria-label', spoken);
    element.title = raw;
    element.textContent = label;

    return element;
}

function hidden(doc, raw, tag = 'span') {
    const element = doc.createElement(tag);
    element.hidden = true;
    element.setAttribute('contenteditable', 'false');
    element.dataset.raw = raw;

    return element;
}

function pairOf(doc, tag, node) {
    const element = doc.createElement(tag);
    element.dataset.open = node.open;
    element.dataset.close = node.close;

    return element;
}

function drawSection(doc, node, condition) {
    const label = sectionLabel(node.name, node.inverted);
    const children = (context) => drawNodes(doc, node.children, context);

    if (node.context === 'table') {
        const block = doc.createElement('tbody');
        block.className = 'tpl-loop';
        block.dataset.pair = '';
        block.dataset.section = node.name;
        if (node.inverted) block.dataset.inverted = '';

        const head = doc.createElement('tr');
        head.className = 'tpl-loop-label';
        head.setAttribute('contenteditable', 'false');
        head.dataset.edge = 'open';
        head.dataset.raw = node.open;
        const cell = doc.createElement('td');
        cell.colSpan = 99;
        const named = node.name === 'troopers' ? LOOP_LABELS[node.inverted ? 'notroopers' : 'troopers'] : `Rows shown only ${label.toLowerCase()}`;
        cell.textContent = named;
        head.append(cell);

        const end = hidden(doc, node.close, 'tr');
        end.dataset.edge = 'close';
        block.append(head, children(null), end);

        return block;
    }

    if (node.context === 'row') {
        const group = doc.createElement('span');
        group.dataset.pair = '';
        group.dataset.section = node.name;
        group.style.display = 'contents';
        const open = hidden(doc, node.open);
        open.dataset.edge = 'open';
        const close = hidden(doc, node.close);
        close.dataset.edge = 'close';
        group.append(open, children(`Only ${label.charAt(0).toLowerCase()}${label.slice(1)}`), close);

        return group;
    }

    const group = doc.createElement('span');
    group.className = 'tpl-if';
    group.dataset.pair = '';
    group.dataset.section = node.name;
    const open = chip(doc, node.open, label, `${label}, template code ${node.open}`, 'tpl-chip tpl-edge');
    open.dataset.edge = 'open';
    const close = chip(doc, node.close, 'End', `End of ${label}, template code ${node.close}`, 'tpl-chip tpl-edge');
    close.dataset.edge = 'close';
    group.append(open, children(condition), close);

    return group;
}

function drawTag(doc, node, condition) {
    if (node.name === 'table') {
        const table = pairOf(doc, 'table', node);
        table.className = 'tpl-table';
        table.append(drawNodes(doc, node.children, null));

        return table;
    }
    if (node.name === 'tr') {
        const row = pairOf(doc, 'tr', node);
        row.append(drawNodes(doc, node.children, condition));

        return row;
    }
    if (node.name === 'td' || node.name === 'th') {
        const cell = pairOf(doc, node.name, node);
        if (condition) cell.dataset.condition = condition;
        cell.append(drawNodes(doc, node.children, null));

        return cell;
    }

    const spec = bbcodeElement(node.name, node.option);
    const element = pairOf(doc, spec.tag, node);
    Object.assign(element.style, spec.style ?? {});
    if (spec.tag === 'a') {
        // Shown as a link, but not one: a click in the editor places the cursor rather than leaving the page.
        element.className = 'tpl-link';
        element.title = spec.attrs.href;
    } else if (spec.attrs?.type) {
        element.setAttribute('type', spec.attrs.type);
    }
    element.append(drawNodes(doc, node.children, null));

    return element;
}

function drawNode(doc, node, condition) {
    switch (node.type) {
        case 'text':
            return node.hidden ? hidden(doc, node.value) : doc.createTextNode(node.value);
        case 'value': {
            const label = valueLabel(node.name, node.filter);
            const known = LABELLED.includes(node.name) && (node.filter === undefined || LABELLED.includes(node.filter));

            return chip(doc, node.raw, label, `${label}, template code ${node.raw}`, known ? 'tpl-chip' : 'tpl-chip tpl-unknown');
        }
        case 'image': {
            const label = imageLabel(node.raw);

            return chip(doc, node.raw, label, `${label}, template code ${node.raw}`, 'tpl-chip tpl-image');
        }
        case 'raw':
            return chip(doc, node.raw, node.raw, `Code ${node.raw}`, 'tpl-raw');
        case 'section':
            return drawSection(doc, node, condition);
        default:
            return drawTag(doc, node, condition);
    }
}

function drawNodes(doc, nodes, condition) {
    const fragment = doc.createDocumentFragment();
    for (const node of nodes) fragment.append(drawNode(doc, node, condition));

    return fragment;
}

/** Draw a template's tree (`buildDoc()`) as elements to put in the visual editor. */
export function drawDoc(nodes, doc = document) {
    return drawNodes(doc, nodes, null);
}

const ELEMENT = 1;
const TEXT = 3;
const BROWSER_LINES = new Set(['DIV', 'P']);

// Walks the surface in the order its template is written, calling `on.text(node, at)`, `on.raw(element, at, raw)` and
// `on.boundary(element, index, at)` with `at`, how far into the template each one starts. Returns the template.
function walk(root, on) {
    let out = '';

    const visitChildren = (element, skipEdges) => {
        const nodes = [...element.childNodes];
        nodes.forEach((child, index) => {
            on.boundary(element, index, out.length);
            if (skipEdges && child.nodeType === ELEMENT && child.dataset.edge) return;
            visit(child);
        });
        on.boundary(element, nodes.length, out.length);
    };

    const visit = (node) => {
        if (node.nodeType === TEXT) {
            on.text(node, out.length);
            out += node.data;

            return;
        }
        if (node.nodeType !== ELEMENT) return;
        const { dataset } = node;

        if (dataset.raw !== undefined) {
            on.raw(node, out.length, dataset.raw);
            out += dataset.raw;

            return;
        }
        if (dataset.pair !== undefined) {
            const edges = [...node.children].filter((child) => child.dataset.edge).map((child) => child.dataset.edge);
            visitChildren(node, !(edges.includes('open') && edges.includes('close')));

            return;
        }
        if (dataset.open !== undefined) {
            on.raw(null, out.length, dataset.open);
            out += dataset.open;
            visitChildren(node, false);
            on.raw(null, out.length, dataset.close ?? '');
            out += dataset.close ?? '';

            return;
        }
        // A line break element is only ever the browser's placeholder for a line it emptied: the editor puts Enter,
        // Shift+Enter and a paste in as text, so a line end the leader wrote is always in a text node.
        if (node.tagName === 'BR') return;
        if (BROWSER_LINES.has(node.tagName) && out !== '' && !out.endsWith('\n')) out += '\n';
        visitChildren(node, false);
    };

    visitChildren(root, false);

    return out;
}

/**
 * Whether typing over or deleting a selection would break the template's structure: it reaches over one end of a
 * condition or of the trooper loop, or runs from one cell (or from outside a table) into another. The browser would take
 * the end away and leave the other, or empty two cells into one, so the editor refuses it instead. A selection inside one
 * cell or one line, across formatting and whole codes, is the browser's to delete.
 */
export function crossesStructure(range) {
    if (range.collapsed) return false;
    const root = range.commonAncestorContainer;
    const scope = root.nodeType === ELEMENT ? root : root.parentElement;
    // A section the selection takes one end of and not the other; taking both (a whole condition, everything) is fine.
    const pairs = new Set([...scope.querySelectorAll('[data-edge]')].map((edge) => edge.closest('[data-pair]')));
    const halfTaken = (pair) => [...pair.children].filter((child) => child.dataset.edge && range.intersectsNode(child)).length === 1;
    if ([...pairs].some(halfTaken)) return true;

    // From one cell into another, or between a cell and outside its table. A selection taking a whole table starts and
    // ends outside every cell, so it is not refused.
    const cellOf = (node) => (node.nodeType === ELEMENT ? node : node.parentElement)?.closest('td, th') ?? null;

    return cellOf(range.startContainer) !== cellOf(range.endContainer);
}

/**
 * Whether the browser has put markup of its own on the surface: bold or a colour it carried on while the leader typed, a
 * new line or a formatting command. It is read as plain text, so the surface is drawn again to show what the template
 * now holds.
 */
export function hasForeignMarkup(root) {
    return [...root.querySelectorAll('*')].some(
        (element) =>
            element.nodeName !== 'BR' &&
            element.dataset.raw === undefined &&
            element.dataset.open === undefined &&
            element.dataset.pair === undefined &&
            !element.parentElement?.closest('[data-raw]'),
    );
}

/**
 * Whether a section on the surface has lost one of its two ends, which the leader deleted. The template no longer has
 * the section, so the surface is drawn again to stop showing the end that is left.
 */
export function hasLoneEdge(root) {
    return [...root.querySelectorAll('[data-pair]')].some((pair) => [...pair.children].filter((child) => child.dataset.edge).length !== 2);
}

/**
 * Read the surface back into the template, and where in it each of `points` (`{node, offset}`, as a selection gives
 * them) falls. A point inside a chip counts as just before the chip; a point the reading never reached (inside the end
 * of a section that is no longer read) is null, so the caller keeps what it knew rather than guess.
 *
 * @returns {{source: string, offsets: (number|null)[]}}
 */
export function readDom(root, points = []) {
    const offsets = points.map(() => null);
    const settle = (test, at) => {
        points.forEach((point, index) => {
            if (offsets[index] === null && test(point)) offsets[index] = at;
        });
    };

    const source = walk(root, {
        text: (node, at) => {
            points.forEach((point, index) => {
                if (offsets[index] === null && point.node === node) offsets[index] = at + Math.min(point.offset, node.data.length);
            });
        },
        raw: (element, at) => element && settle((point) => element === point.node || element.contains(point.node), at),
        boundary: (element, index, at) => settle((point) => point.node === element && point.offset === index, at),
    });

    return { source, offsets };
}

// Where a cursor may go: not inside a piece that cannot be edited, and not between the rows or cells of a table, where
// what is typed would land outside every cell.
function cursorMayGo(element) {
    if (element.closest('[data-raw]') || element.getAttribute('contenteditable') === 'false') return false;
    if (['TABLE', 'TBODY', 'THEAD', 'TR'].includes(element.nodeName)) return false;

    return !element.closest('tr') || element.closest('td, th') !== null;
}

/**
 * The point on the surface for a place in the template: inside the text it falls in where there is one, otherwise the
 * nearest place a cursor may go (`cursorMayGo`), text before a gap between elements when two are as near. A place inside
 * a tag or a code is so moved to the nearest edge a leader could type at.
 *
 * @returns {{node: Node, offset: number}}
 */
export function pointAt(root, place) {
    let found = null;
    let best = null;
    const consider = (point, at, text) => {
        const distance = Math.abs(at - place);
        if (!best || distance < best.distance || (distance === best.distance && text && !best.text)) best = { point, distance, text };
    };

    walk(root, {
        text: (node, at) => {
            const end = at + node.data.length;
            if (!found && place >= at && place <= end) found = { node, offset: place - at };
            consider({ node, offset: place < at ? 0 : node.data.length }, place < at ? at : end, true);
        },
        raw: () => {},
        boundary: (element, index, at) => {
            if (cursorMayGo(element)) consider({ node: element, offset: index }, at, false);
        },
    });

    return found ?? best?.point ?? { node: root, offset: root.childNodes.length };
}
