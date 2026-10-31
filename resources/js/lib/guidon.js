// A unit's guidon (#446): the flag, drawn as SVG from the saved design in one of FORMATS: a rectangle with a slanted
// band across the top (the regiment's own 2/7 A guidon, and the format a large character fits best), or the swallowtail
// split on the diagonal (the first format, and what every design saved without a format draws), split into a top and
// a bottom half (the wiki's Regimental HQ and 3-7 HQ guidons), or one solid color. This is the one place the flag is drawn: the small
// flag next to a unit, the generator's preview, and both downloads (the SVG itself, and the PNG a canvas makes from it)
// all come from guidonSvg(). It only ever draws a design that passes the same rules the server checks (a known format,
// two `#rrggbb` colors, two short texts of letters, digits, `.`, `/`, `-` and spaces, each placed left or centered), and
// escapes the text anyway, so nothing typed can become markup.

import { CAP_HEIGHT, GLYPHS } from './guidonGlyphs';

// A US Army guidon is 20 by 27¾ inches with a 10 inch swallowtail cut; the drawing keeps those proportions.
export const WIDTH = 300;
export const HEIGHT = 216;
const NOTCH = 108;

// Sized for the wiki's unit pages.
export const DOWNLOAD_WIDTH = 300;

export const MAX_TEXT = 6;

// Below this height in pixels a guidon is drawn plain: its colors, format and split, no text and no large character,
// which would only be a smudge that small (lists of units, the 16px flag beside a unit name).
export const TEXTLESS_BELOW = 20;

// The formats a guidon can take, in the order the generator offers them. A design with no format is diagonal.
export const FORMATS = [
    { value: 'band', label: 'Band' },
    { value: 'diagonal', label: 'Diagonal' },
    { value: 'horizontal', label: 'Top and bottom' },
    { value: 'solid', label: 'Solid' },
];
export const DEFAULT_FORMAT = 'diagonal';
// Where a text sits on its half, for the top and bottom formats. The diagonal format keeps its own fixed spots.
export const ALIGNS = [
    { value: 'left', label: 'Left' },
    { value: 'center', label: 'Center' },
];
export const DEFAULT_ALIGN = 'left';
const FORMAT_VALUES = FORMATS.map((f) => f.value);
const ALIGN_VALUES = ALIGNS.map((a) => a.value);
const HEX = /^#[0-9a-f]{6}$/i;
const TEXT = /^[A-Za-z0-9./\- ]*$/;
// Any character the server would refuse, for stripping from what a leader types.
const NOT_TEXT = /[^A-Za-z0-9./\- ]/g;
// The large character at the hoist: one or two letters or digits, or none.
export const MAX_LARGE = 2;
const LARGE = /^[A-Za-z0-9]{0,2}$/;
const NOT_LARGE = /[^A-Za-z0-9]/g;

// Below this contrast ratio the two halves are hard to tell apart (WCAG ratio, 1 is identical, 21 black on white).
export const MIN_PAIR_CONTRAST = 1.5;

// The set palette (the wiki's guidon colors and the Army's branch colors); any other color can be picked as well.
export const PALETTE = [
    { name: 'Navy', hex: '#1f2a44' },
    { name: 'White', hex: '#ffffff' },
    { name: 'Black', hex: '#000000' },
    { name: 'Red', hex: '#b22234' },
    { name: 'Maroon', hex: '#800000' },
    { name: 'Cavalry yellow', hex: '#ffc72c' },
    { name: 'Orange', hex: '#e87722' },
    { name: 'Green', hex: '#2e5e3e' },
    { name: 'Blue', hex: '#0033a0' },
    { name: 'Gray', hex: '#6b7280' },
];

export function isColor(value) {
    return typeof value === 'string' && HEX.test(value);
}

export function isText(value) {
    return typeof value === 'string' && value.length <= MAX_TEXT && TEXT.test(value);
}

export function isLarge(value) {
    return typeof value === 'string' && LARGE.test(value);
}

const optional = (value, allowed) => value === undefined || value === null || allowed.includes(value);

export function isDesign(design) {
    return (
        Boolean(design) &&
        isColor(design.primary) &&
        isColor(design.secondary) &&
        isText(design.topLeft) &&
        isText(design.bottomRight) &&
        optional(design.format, FORMAT_VALUES) &&
        optional(design.topAlign, ALIGN_VALUES) &&
        optional(design.bottomAlign, ALIGN_VALUES) &&
        (design.large === undefined || design.large === null || isLarge(design.large))
    );
}

// The format a design draws in: diagonal for one saved before formats existed.
export function formatOf(design) {
    return design?.format ?? DEFAULT_FORMAT;
}

// Whether the design shows two colors. A solid guidon shows one, so it has no pair to warn about.
export function hasPair(design) {
    return formatOf(design) !== 'solid';
}

// The pair warning for a design: its two colors are too alike, in a format that shows both.
export function pairTooClose(design) {
    return hasPair(design) && tooClose(design.primary, design.secondary);
}

// What a leader typed, kept to what the server accepts: allowed characters only, at most MAX_TEXT of them.
export function cleanText(value) {
    return String(value ?? '')
        .replace(NOT_TEXT, '')
        .slice(0, MAX_TEXT);
}

// What a leader typed for the large character: letters and digits only, at most MAX_LARGE of them.
export function cleanLarge(value) {
    return String(value ?? '')
        .replace(NOT_LARGE, '')
        .slice(0, MAX_LARGE);
}

// WCAG 2 relative luminance of a `#rrggbb` color.
export function luminance(hex) {
    const channel = (offset) => {
        const c = parseInt(hex.slice(offset, offset + 2), 16) / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
}

// WCAG 2 contrast ratio between two colors, from 1 to 21.
export function contrastRatio(a, b) {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

// Black or white text, whichever reads better on the color.
export function textColorOn(hex) {
    return contrastRatio(hex, '#ffffff') >= contrastRatio(hex, '#000000') ? '#ffffff' : '#000000';
}

// Whether the two halves are too alike to tell apart.
export function tooClose(a, b) {
    return contrastRatio(a, b) < MIN_PAIR_CONTRAST;
}

// The text a new guidon starts with, from the unit's designation: the battalion and the 7 on the top left ("2/7"),
// the company letter on the bottom right ("A"). A battalion gets only its number; the regiment gets "7". A designation
// that is not a number (ACD) is used as it is, kept to what a guidon may carry.
export function defaultText(unit) {
    const callsign = String(unit?.callsign ?? '');
    const battalion = (designation) => {
        const numbered = /^(\d+)-7$/.exec(designation);

        return numbered ? `${numbered[1]}/7` : cleanText(designation);
    };

    if (unit?.type === 'company') {
        const [letter = '', bn = ''] = callsign.split('/');

        return { topLeft: battalion(bn), bottomRight: cleanText(letter) };
    }
    if (unit?.type === 'battalion') return { topLeft: battalion(callsign), bottomRight: '' };

    return { topLeft: '7', bottomRight: '' };
}

export function defaultDesign(unit) {
    return { format: DEFAULT_FORMAT, primary: '#1f2a44', secondary: '#ffffff', ...defaultText(unit), topAlign: DEFAULT_ALIGN, bottomAlign: DEFAULT_ALIGN, large: '' };
}

function escapeXml(value) {
    return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[c]);
}

// Text is drawn as paths from Oswald Medium's outlines (lib/guidonGlyphs.js), a tall, narrow face of medium weight like the regiment's
// official guidons. A font would not reach the flag: it is shown as an <img> and downloaded as a file, so the outlines
// travel inside the SVG and it draws the same in every browser, image viewer and wiki.
function textWidth(text) {
    return [...text].reduce((sum, c) => sum + (GLYPHS[c]?.[0] ?? 0), 0);
}

// A text with its capitals `cap` pixels tall, centered on y, shrunk so it stays within maxWidth. The group carries the
// text and its cap height as data, for the tests and anyone reading the file.
function label(text, x, y, cap, maxWidth, fill, anchor = 'middle') {
    if (!text.trim()) return '';
    const scale = Math.min(cap / CAP_HEIGHT, maxWidth / textWidth(text));
    const width = textWidth(text) * scale;
    const left = anchor === 'middle' ? x - width / 2 : x;
    const round = (n) => Math.round(n * 100) / 100;
    let advance = 0;
    const paths = [...text].map((c) => {
        const [width, d] = GLYPHS[c];
        const path = d ? `<path transform="translate(${advance})" d="${d}"/>` : '';
        advance += width;

        return path;
    });

    return `<g data-text="${escapeXml(text)}" data-cap="${Math.round(CAP_HEIGHT * scale)}" fill="${fill}" transform="translate(${round(left)} ${round(y + (CAP_HEIGHT * scale) / 2)}) scale(${Math.round(scale * 10000) / 10000})">${paths.join('')}</g>`;
}

// The flag's outline: the hoist on the left, the swallowtail cut in the fly on the right. The band format is a plain
// rectangle.
const OUTLINE = `0,0 ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT} 0,${HEIGHT}`;
const RECTANGLE = `0,0 ${WIDTH},0 ${WIDTH},${HEIGHT} 0,${HEIGHT}`;

// The top and bottom formats: each text sits in the middle of its half's height, left near the hoist or centered on
// the part of the half clear of the cut (the cut reaches x = WIDTH - NOTCH / 2 at a quarter of the height).
const HALF_LEFT = 24;
const HALF_CLEAR = WIDTH - NOTCH / 2;
const HALF_WIDTH = HALF_CLEAR - 2 * HALF_LEFT;

// With a large character the texts move right of it (LARGE_RIGHT is where its two characters end).
const LARGE_RIGHT = 148;
// The large character is about 72% of the flag's height, with the rest split above and below it.
const LARGE_CAP = Math.round(HEIGHT * 0.72);
const LARGE_LEFT = HALF_LEFT / 2;

function halfLabel(text, y, align, fill, large) {
    const left = large ? LARGE_RIGHT : HALF_LEFT;
    const width = HALF_CLEAR - left - HALF_LEFT;

    return align === 'center'
        ? label(text, Math.round((left + HALF_CLEAR) / 2), y, 64, width, fill)
        : label(text, left, y, 64, width, fill, 'start');
}

// Where the large character ends on the right, as largeChar draws it.
function largeRight(text) {
    return LARGE_LEFT + Math.min(textWidth(text) * (LARGE_CAP / CAP_HEIGHT), LARGE_RIGHT - HALF_LEFT);
}

// The large character at the hoist, most of the height of the flag across both halves, like the wiki's Reserve guidon.
// It takes the opposite color of each half it crosses: drawn once per half, clipped to that half, in the other color.
function largeChar(text, halves) {
    if (!text) return [];
    // Two characters shrink to fit left of LARGE_RIGHT.
    const glyph = (fill) => label(text, LARGE_LEFT, HEIGHT / 2, LARGE_CAP, LARGE_RIGHT - HALF_LEFT, fill, 'start');

    return halves.flatMap(({ points, fill }, i) => [
        `<clipPath id="guidon-half-${i}"><polygon points="${points}"/></clipPath>`,
        `<g clip-path="url(#guidon-half-${i})">${glyph(fill)}</g>`,
    ]);
}

// Each format's shapes and texts, inside the `0 0 WIDTH HEIGHT` view box. Each text takes the color that reads on the
// half it sits on.
function diagonal(design, primary, secondary) {
    // The diagonal runs from the bottom left corner to the top right one; the bottom right half carries the tails.
    const topLeft = `0,0 ${WIDTH},0 0,${HEIGHT}`;
    const bottomRight = `0,${HEIGHT} ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT}`;

    // Without a large character the texts keep the spots a guidon saved before formats had.
    if (!design.large) {
        return [
            `<polygon points="${topLeft}" fill="${primary}"/>`,
            `<polygon points="${bottomRight}" fill="${secondary}"/>`,
            label(design.topLeft, 80, 72, 70, 150, textColorOn(primary)),
            label(design.bottomRight, 172, 160, 80, 110, textColorOn(secondary)),
        ];
    }

    // With a large character the texts take the other half's color too, as on the wiki's Reserve guidon. Each sits
    // right of the large character with room to spare from the diagonal (x = WIDTH - y * WIDTH / HEIGHT): the top text
    // in the corner above it (clear to x = 211 at its lowest), the bottom text above the cut's lower tail.
    return [
        `<polygon points="${topLeft}" fill="${primary}"/>`,
        `<polygon points="${bottomRight}" fill="${secondary}"/>`,
        ...largeChar(design.large, [
            { points: topLeft, fill: secondary },
            { points: bottomRight, fill: primary },
        ]),
        label(design.topLeft, 150, 44, 44, 80, secondary),
        label(design.bottomRight, 162, 172, 48, 90, primary),
    ];
}

// Split across the middle: the first color on top down to the cut's point, the second below it with the tails.
function horizontal(design, primary, secondary) {
    const top = `0,0 ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} 0,${HEIGHT / 2}`;
    const bottom = `0,${HEIGHT / 2} ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT} 0,${HEIGHT}`;

    return [
        `<polygon points="${top}" fill="${primary}"/>`,
        `<polygon points="${bottom}" fill="${secondary}"/>`,
        ...largeChar(design.large, [
            { points: top, fill: secondary },
            { points: bottom, fill: primary },
        ]),
        halfLabel(design.topLeft, HEIGHT / 4, design.topAlign, design.large ? secondary : textColorOn(primary), design.large),
        halfLabel(design.bottomRight, (HEIGHT * 3) / 4, design.bottomAlign, design.large ? primary : textColorOn(secondary), design.large),
    ];
}

// One color; the texts sit where the top and bottom format puts them, and a large character in the color that reads.
function solid(design, primary) {
    const fill = textColorOn(primary);

    return [
        `<polygon points="${OUTLINE}" fill="${primary}"/>`,
        ...largeChar(design.large, [{ points: OUTLINE, fill }]),
        halfLabel(design.topLeft, HEIGHT / 4, design.topAlign, fill, design.large),
        halfLabel(design.bottomRight, (HEIGHT * 3) / 4, design.bottomAlign, fill, design.large),
    ];
}

// The regiment's own guidon: a rectangle with a band of the first color across the top half, from the hoist to a
// slanted end that runs from the top right corner down to two thirds of the width at the middle; the rest is the
// second color. The top text sits on the band, the bottom text below it, both right of the large character.
const BAND_SLANT = Math.round(WIDTH / 3);
const BAND = `0,0 ${WIDTH},0 ${WIDTH - BAND_SLANT},${HEIGHT / 2} 0,${HEIGHT / 2}`;
const BAND_REST = `0,${HEIGHT / 2} ${WIDTH - BAND_SLANT},${HEIGHT / 2} ${WIDTH},0 ${WIDTH},${HEIGHT} 0,${HEIGHT}`;

function band(design, primary, secondary) {
    const large = Boolean(design.large);
    // The texts are about a third of the flag's height. Their column starts right of the large character (or near the
    // hoist without one) and ends where the slant leaves 12px of the band at the top text's lowest point.
    const left = (large ? largeRight(design.large) : 0) + HALF_LEFT;
    const right = WIDTH - BAND_SLANT - 12 + (BAND_SLANT * (HEIGHT / 2 - 92)) / (HEIGHT / 2);
    const column = (text, y, cap, align, fill) =>
        align === 'center' ? label(text, (left + right) / 2, y, cap, right - left, fill) : label(text, left, y, cap, right - left, fill, 'start');

    return [
        `<polygon points="${BAND}" fill="${primary}"/>`,
        `<polygon points="${BAND_REST}" fill="${secondary}"/>`,
        ...largeChar(design.large, [
            { points: BAND, fill: secondary },
            { points: BAND_REST, fill: primary },
        ]),
        column(design.topLeft, HEIGHT / 4, Math.round(HEIGHT * 0.35), design.topAlign, large ? secondary : textColorOn(primary)),
        column(design.bottomRight, (HEIGHT * 3) / 4, Math.round(HEIGHT * 0.3), design.bottomAlign, large ? primary : textColorOn(secondary)),
    ];
}

const DRAW = { band, diagonal, horizontal, solid };

// The flag as an SVG document, `width` pixels wide; `plain` leaves out the texts and the large character. Null for a
// design that breaks the rules: nothing is drawn from it.
export function guidonSvg(design, { width = WIDTH, plain = false } = {}) {
    if (!isDesign(design)) return null;

    const height = Math.round((width * HEIGHT) / WIDTH);
    const primary = design.primary.toLowerCase();
    const secondary = design.secondary.toLowerCase();

    return [
        `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${WIDTH} ${HEIGHT}">`,
        ...DRAW[formatOf(design)](plain ? { ...design, topLeft: '', bottomRight: '', large: '' } : design, primary, secondary),
        // A faint edge, so a white half still shows against a white page.
        `<polygon points="${formatOf(design) === 'band' ? RECTANGLE : OUTLINE}" fill="none" stroke="#000000" stroke-opacity="0.25" stroke-width="2" stroke-linejoin="round"/>`,
        '</svg>',
    ].join('');
}

// The flag as a `data:` URL for an <img>: the browser draws it as an image, never as part of the page.
export function guidonDataUrl(design, options) {
    const svg = guidonSvg(design, options);

    return svg === null ? null : `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

// The file name a download gets: `guidon-A-1-7.svg`.
export function guidonFileName(unit, extension) {
    const name = String(unit?.callsign ?? unit?.name ?? 'unit').replace(/[^A-Za-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');

    return `guidon-${name || 'unit'}.${extension}`;
}

// The flag as a PNG `data:` URL, `width` pixels wide, drawn on a canvas from the same SVG.
export function guidonPngDataUrl(design, width = DOWNLOAD_WIDTH) {
    const src = guidonDataUrl(design, { width });
    if (src === null) return Promise.reject(new Error('Not a guidon design.'));

    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = Math.round((width * HEIGHT) / WIDTH);
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
            resolve(canvas.toDataURL('image/png'));
        };
        image.onerror = () => reject(new Error('The guidon could not be drawn.'));
        image.src = src;
    });
}
