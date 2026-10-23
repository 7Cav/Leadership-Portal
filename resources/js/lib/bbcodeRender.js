// A preview of a BBCode post as the forum (XenForo) shows it, for the rollcall's forum post and the template editor.
// It reads the BBCode into a tree of plain nodes; `components/BbcodePreview.vue` turns the tree into Vue elements. No
// string of HTML is ever built: text stays text (Vue writes it as a text node), and the only attributes are the few
// this file sets itself from values it has checked. A template or a trooper's name can therefore never add markup,
// a script or an event handler to the page.
//
// What it does not know it leaves alone: an unknown tag, a tag whose option fails the check below, a tag in a place it
// cannot go and a tag that is never closed are all shown as the text that was written, as the forum does.
//
// Node shapes:
//   { type: 'text', text }
//   { type: 'br' }
//   { type: 'element', tag, kind, attrs, style, children }   `kind` is the BBCode name, `tag` the HTML element; the
//                                                              style of an [IMG width=".." height=".."] holds its sizes
//   { type: 'quote-by', text }                                 the "name said:" line of a [QUOTE=name]

const ALIGN = { center: 'center', left: 'left', right: 'right', justify: 'justify' };

// XenForo's font sizes for [SIZE=1] to [SIZE=7], in pixels; a size in pixels must be within these bounds.
export const SIZES = { 1: 9, 2: 10, 3: 12, 4: 15, 5: 18, 6: 22, 7: 26 };
const MIN_PX = 8;
const MAX_PX = 36;

// Deep nesting has no use in a post and would only make the preview recurse; past this a tag is shown as text.
const MAX_DEPTH = 40;

const BLOCK = new Set(['center', 'left', 'right', 'justify', 'quote', 'list', 'table', 'hr']);
const TABLE_PARTS = new Set(['table', 'tr']);
// Tags whose last line break, just before the closing tag, is layout rather than a blank line.
const TRIM_END = new Set([...BLOCK, 'li', 'td', 'th']);

// One opening or closing tag. The option stops at "]" or a line end, so a broken tag cannot run on through the post. The
// second form is XenForo's [IMG width="322px" height="auto"], the only tag here that takes named options.
const TAG = /\[(\/?)([a-z]+|\*)(?:=([^\]\r\n]*))?\]|\[(img)(\s[^\]\r\n]*)\]/gi;

// The largest side, in pixels, an [IMG] may be given.
const MAX_IMG_PX = 1000;
// One `name="value"`, `name='value'` or `name=value` among an [IMG]'s options, after the space that separates it.
const IMG_OPTION = /\s+([a-z]+)=(?:"([^"]*)"|'([^']*)'|([^\s"']+))/iy;

const text = (value) => ({ type: 'text', text: value });

/** An http or https address, written in full, as the href of a link; anything else is null. */
export function safeLink(value) {
    return checkedUrl(value, /^https?:\/\//i, ['http:', 'https:']);
}

// The hosts an [IMG] in the preview may load from: the 7cav.us forum and its subdomains, the same host the rank images
// come from (`CavRosterEntry::RANK_IMAGE`, `rankIconOf`). A template is written by an Admin but shown to every leader,
// so an image anywhere else would have each leader's browser call that host when they open the preview.
const IMAGE_HOST = /^(?:[a-z0-9-]+\.)*7cav\.us$/;

/** An https address on 7cav.us (or a subdomain), written in full, as the src of an image; anything else is null. */
export function safeImage(value) {
    const href = httpsAddress(value);

    return href !== null && IMAGE_HOST.test(new URL(href).hostname) ? href : null;
}

function httpsAddress(value) {
    return checkedUrl(value, /^https:\/\//i, ['https:']);
}

function checkedUrl(value, prefix, protocols) {
    const trimmed = String(value ?? '').trim();
    if (!prefix.test(trimmed) || /[\s"'<>\\]/.test(trimmed)) return null;

    try {
        const url = new URL(trimmed);

        return protocols.includes(url.protocol) && url.hostname !== '' ? url.href : null;
    } catch {
        return null;
    }
}

/** A colour name, #rgb, #rrggbb or rgb(r, g, b) for [COLOR=...]; anything else is null. */
export function safeColor(value) {
    const trimmed = String(value ?? '').trim();
    if (/^[a-z]{3,20}$/i.test(trimmed)) return trimmed.toLowerCase();
    if (/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(trimmed)) return trimmed.toLowerCase();

    const rgb = trimmed.match(/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i);
    if (rgb && rgb.slice(1).every((part) => Number(part) <= 255)) return `rgb(${rgb[1]}, ${rgb[2]}, ${rgb[3]})`;

    return null;
}

/** [SIZE=1] to [SIZE=7] as the forum maps them, or a size in pixels within bounds, as a CSS size; anything else is null. */
export function safeSize(value) {
    const trimmed = String(value ?? '').trim();
    if (/^[1-7]$/.test(trimmed)) return `${SIZES[trimmed]}px`;

    const px = trimmed.match(/^(\d{1,2})px$/i);
    if (px && Number(px[1]) >= MIN_PX && Number(px[1]) <= MAX_PX) return `${Number(px[1])}px`;

    return null;
}

/** One side of an [IMG]: a whole number of pixels from 1 to 1000, or `auto`, as a CSS size; anything else is null. */
export function safeImageSide(value) {
    const side = String(value ?? '');
    if (/^auto$/i.test(side)) return 'auto';

    const px = side.match(/^(\d{1,4})px$/i);
    if (px && Number(px[1]) >= 1 && Number(px[1]) <= MAX_IMG_PX) return `${Number(px[1])}px`;

    return null;
}

/**
 * The sizes in an [IMG]'s options, as the forum takes them: `width` and `height`, each at most once, in any order,
 * separated by spaces, quoted or not, each a size {@link safeImageSide} accepts (`auto` keeps that side in proportion to the other). Null for
 * anything else, so the tag is shown as written.
 *
 * @returns {{width?: string, height?: string} | null}
 */
export function imageSize(options) {
    const source = String(options ?? '').trimEnd();
    const size = {};
    IMG_OPTION.lastIndex = 0;

    while (IMG_OPTION.lastIndex < source.length) {
        const match = IMG_OPTION.exec(source);
        if (!match) return null;

        const key = match[1].toLowerCase();
        const side = safeImageSide(match[2] ?? match[3] ?? match[4]);
        if (!['width', 'height'].includes(key) || key in size || side === null) return null;
        size[key] = side;
    }

    return Object.keys(size).length ? size : null;
}

// An option may be quoted, as the forum's editor writes [URL="..."].
function unquote(option) {
    const match = option.match(/^(["'])(.*)\1$/);

    return match ? match[2] : option;
}

// What each tag becomes, given its option (undefined when it has none). Null means the tag cannot be used as written, so
// it stays text.
const TAGS = {
    b: (option) => option === undefined && { tag: 'strong' },
    i: (option) => option === undefined && { tag: 'em' },
    u: (option) => option === undefined && { tag: 'u' },
    s: (option) => option === undefined && { tag: 's' },
    center: (option) => option === undefined && { tag: 'div', style: { textAlign: 'center' } },
    left: (option) => option === undefined && { tag: 'div', style: { textAlign: 'left' } },
    right: (option) => option === undefined && { tag: 'div', style: { textAlign: 'right' } },
    justify: (option) => option === undefined && { tag: 'div', style: { textAlign: 'justify' } },
    color: (option) => {
        const color = safeColor(option);

        return color && { tag: 'span', style: { color } };
    },
    size: (option) => {
        const size = safeSize(option);

        return size && { tag: 'span', style: { fontSize: size } };
    },
    url: (option) => {
        const href = safeLink(option);

        return href && { tag: 'a', attrs: linkAttrs(href) };
    },
    quote: (option) => ({ tag: 'blockquote', by: option === undefined ? '' : option.split(',')[0].trim() }),
    list: (option) => {
        if (option === undefined) return { tag: 'ul' };
        if (['1', 'a', 'A', 'i', 'I'].includes(option)) return { tag: 'ol', attrs: { type: option } };

        return null;
    },
    '*': (option) => option === undefined && { tag: 'li' },
    table: (option) => option === undefined && { tag: 'table' },
    tr: (option) => option === undefined && { tag: 'tr' },
    td: (option) => option === undefined && { tag: 'td' },
    th: (option) => option === undefined && { tag: 'th' },
};

function linkAttrs(href) {
    return { href, rel: 'noopener noreferrer nofollow', target: '_blank' };
}

/**
 * Read BBCode into the preview tree.
 *
 * @param {string} source the BBCode, as the leader would paste it into the forum
 * @returns {object[]} the nodes (see the shapes at the top of this file)
 */
export function parseBbcode(source) {
    const input = String(source ?? '');
    const root = { kind: null, children: [] };
    const stack = [root];
    let last = 0;
    // A line break straight after a block (or straight inside one) is part of the layout, not a blank line.
    let trimNext = false;

    const top = () => stack.at(-1);

    // Where the next [/IMG] or [/URL] at or after `from` starts, or -1. Searched in the post itself, case-insensitively,
    // so the place is right even after a letter whose lower case is longer ("İ"). `from` only grows, so the last answer
    // still holds while it is not behind `from`, and a post full of unclosed tags is not searched again each time.
    const closes = {};
    const closeOf = (name, from) => {
        const known = closes[name];
        if (known !== undefined && (known === -1 || known >= from)) return known;
        const pattern = new RegExp(`\\[/${name}\\]`, 'gi');
        pattern.lastIndex = from;
        closes[name] = pattern.exec(input)?.index ?? -1;

        return closes[name];
    };

    const addText = (value) => {
        let chunk = value;
        if (trimNext) chunk = chunk.replace(/^\r?\n/, '');
        trimNext = false;
        if (chunk === '') return;

        const frame = top();
        // Only rows go in a table and only cells in a row; what is written between them is not shown.
        if (TABLE_PARTS.has(frame.kind)) return;
        if (frame.kind === 'list' && chunk.trim() === '') return;

        chunk.split(/\r?\n/).forEach((line, index) => {
            if (index > 0) frame.children.push({ type: 'br' });
            if (line !== '') frame.children.push(text(line));
        });
    };

    const literal = (raw) => {
        const frame = top();
        if (TABLE_PARTS.has(frame.kind)) return;
        trimNext = false;
        frame.children.push(text(raw));
    };

    const closeTo = (index) => {
        while (stack.length - 1 > index) {
            trimLastBreak(stack.pop().node);
        }
    };

    const nearest = (kinds) => {
        for (let index = stack.length - 1; index > 0; index -= 1) if (kinds.includes(stack[index].kind)) return index;

        return -1;
    };

    for (const match of input.matchAll(TAG)) {
        const [raw, slash] = match;
        const name = (match[2] ?? match[4]).toLowerCase();
        const option = match[3] === undefined ? undefined : unquote(match[3].trim());
        // The sizes of an [IMG width=".." height=".."], or null when they are not ones the forum takes.
        const size = match[5] === undefined ? {} : imageSize(match[5]);

        if (match.index < last) continue;
        addText(input.slice(last, match.index));
        last = match.index + raw.length;

        if (slash) {
            if (name === 'hr') continue;
            const index = stack.findLastIndex((frame, at) => at > 0 && frame.kind === (name === '*' ? 'li' : name));
            if (index === -1) {
                literal(raw);
                continue;
            }
            closeTo(index);
            stack.pop();
            trimLastBreak(stack.at(-1).children.at(-1));
            trimNext = BLOCK.has(name);
            continue;
        }

        if (name === 'hr' && option === undefined) {
            if (TABLE_PARTS.has(top().kind)) continue;
            top().children.push({ type: 'element', tag: 'hr', kind: 'hr', attrs: {}, style: {}, children: [] });
            trimNext = true;
            continue;
        }

        // [IMG] and a bare [URL] hold an address, not more BBCode.
        if ((name === 'img' && option === undefined) || (name === 'url' && option === undefined)) {
            const close = closeOf(name, last);
            const address = close === -1 ? null : input.slice(last, close);
            const safe = address === null ? null : name === 'img' ? safeImage(address) : safeLink(address);
            // An https image on another host is shown as a link to it, so the preview never loads it on its own.
            const elsewhere = name === 'img' && safe === null && address !== null ? httpsAddress(address) : null;
            const href = safe ?? elsewhere;
            if (href === null || size === null || TABLE_PARTS.has(top().kind) || stack.length > MAX_DEPTH) {
                literal(raw);
                continue;
            }
            const node =
                name === 'img' && elsewhere === null
                    ? { type: 'element', tag: 'img', kind: 'img', attrs: { src: href, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }, style: size, children: [] }
                    : { type: 'element', tag: 'a', kind: 'url', attrs: linkAttrs(href), style: {}, children: [text(address.trim())] };
            top().children.push(node);
            last = close + name.length + 3;
            trimNext = false;
            continue;
        }

        const define = TAGS[name];
        const spec = define ? define(option) : null;
        if (!spec || stack.length > MAX_DEPTH) {
            literal(raw);
            continue;
        }

        // Rows, cells and list items only go where they belong; a new row or item closes the one before it.
        if (name === 'tr' || name === '*') {
            const index = nearest(name === 'tr' ? ['table'] : ['list']);
            if (index === -1 || nearest(['table', 'list', 'quote']) !== index) {
                literal(raw);
                continue;
            }
            closeTo(index);
        } else if (name === 'td' || name === 'th') {
            const index = nearest(['table', 'tr']);
            if (index === -1 || stack[index].kind !== 'tr') {
                literal(raw);
                continue;
            }
            closeTo(index);
        } else if (TABLE_PARTS.has(top().kind)) {
            continue;
        }

        const node = { type: 'element', tag: spec.tag, kind: name === '*' ? 'li' : name, attrs: spec.attrs ?? {}, style: spec.style ?? {}, children: [], raw };
        if (spec.by) node.children.push({ type: 'quote-by', text: `${spec.by} said:` });
        top().children.push(node);
        stack.push({ kind: node.kind, children: node.children, node });
        trimNext = BLOCK.has(name);
    }

    addText(input.slice(last));

    // A tag never closed is shown as written, with what followed it.
    while (stack.length > 1) {
        const frame = stack.pop();
        const parent = top().children;
        const at = parent.lastIndexOf(frame.node);
        const kept = frame.node.kind === 'quote' ? frame.node.children.filter((child) => child.type !== 'quote-by') : frame.node.children;
        parent.splice(at, 1, text(frame.node.raw), ...kept);
    }

    return strip(root.children);
}

// The line break just before a block's closing tag belongs to the layout too.
function trimLastBreak(node) {
    if (!node || node.type !== 'element' || !TRIM_END.has(node.kind)) return;
    if (node.children.at(-1)?.type === 'br') node.children.pop();
}

// `raw` is only needed while reading; the tree a caller gets has the documented shape.
function strip(nodes) {
    return nodes.map((node) => {
        if (node.type !== 'element') return node;
        const { raw: _raw, ...rest } = node;

        return { ...rest, children: strip(node.children) };
    });
}
