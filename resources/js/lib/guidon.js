// A unit's guidon (#446): the flag, drawn as SVG from the saved design in one of FORMATS, all on the swallowtail: split
// on the diagonal (the first format, and what every design saved without a format draws), split into a top and a bottom
// half (the wiki's Regimental HQ and 3-7 HQ guidons), quartered in the two colors like a checkerboard, or one solid
// color. This is the one place the flag is drawn: the small
// flag next to a unit, the generator's preview, and both downloads (the SVG itself, and the PNG a canvas makes from it)
// all come from guidonSvg(). It only ever draws a design that passes the same rules the server checks (a known format,
// two `#rrggbb` colors, two short texts of letters, digits, `.`, `/`, `-` and spaces, each placed left or centered), and
// escapes the text anyway, so nothing typed can become markup.

import { CAP_HEIGHT, GLYPHS } from "./guidonGlyphs";

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
    { value: "diagonal", label: "Diagonal" },
    { value: "horizontal", label: "Top and bottom" },
    { value: "quartered", label: "Quartered" },
    { value: "solid", label: "Solid" },
];
export const DEFAULT_FORMAT = "diagonal";
// A format that was dropped, and the one a design saved in it draws as. The server no longer saves it.
const RETIRED_FORMATS = { band: "horizontal" };
// Where a text sits on its half or quarter, for the top and bottom, quartered and solid formats. The diagonal format keeps its own fixed spots.
export const ALIGNS = [
    { value: "left", label: "Left" },
    { value: "center", label: "Center" },
];
export const DEFAULT_ALIGN = "left";
const FORMAT_VALUES = [
    ...FORMATS.map((f) => f.value),
    ...Object.keys(RETIRED_FORMATS),
];
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
    { name: "Navy", hex: "#1f2a44" },
    { name: "White", hex: "#ffffff" },
    { name: "Black", hex: "#000000" },
    { name: "Red", hex: "#b22234" },
    { name: "Maroon", hex: "#800000" },
    { name: "Cavalry yellow", hex: "#ffc72c" },
    { name: "Orange", hex: "#e87722" },
    { name: "Green", hex: "#2e5e3e" },
    { name: "Blue", hex: "#0033a0" },
    { name: "Gray", hex: "#6b7280" },
];

export function isColor(value) {
    return typeof value === "string" && HEX.test(value);
}

export function isText(value) {
    return (
        typeof value === "string" &&
        value.length <= MAX_TEXT &&
        TEXT.test(value)
    );
}

export function isLarge(value) {
    return typeof value === "string" && LARGE.test(value);
}

const optional = (value, allowed) =>
    value === undefined || value === null || allowed.includes(value);

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
        (design.large === undefined ||
            design.large === null ||
            isLarge(design.large))
    );
}

// The format a design draws in: diagonal for one saved before formats existed, top and bottom for one saved as a band.
export function formatOf(design) {
    const format = design?.format ?? DEFAULT_FORMAT;

    return RETIRED_FORMATS[format] ?? format;
}

// Whether the design shows two colors. A solid guidon shows one, so it has no pair to warn about.
export function hasPair(design) {
    return formatOf(design) !== "solid";
}

// The pair warning for a design: its two colors are too alike, in a format that shows both.
export function pairTooClose(design) {
    return hasPair(design) && tooClose(design.primary, design.secondary);
}

// What a leader typed, kept to what the server accepts: allowed characters only, at most MAX_TEXT of them.
export function cleanText(value) {
    return String(value ?? "")
        .replace(NOT_TEXT, "")
        .slice(0, MAX_TEXT);
}

// What a leader typed for the large character: letters and digits only, at most MAX_LARGE of them.
export function cleanLarge(value) {
    return String(value ?? "")
        .replace(NOT_LARGE, "")
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
    return contrastRatio(hex, "#ffffff") >= contrastRatio(hex, "#000000")
        ? "#ffffff"
        : "#000000";
}

// Below this contrast ratio the other color does not read as text on a color (WCAG's ratio for large text).
export const MIN_OPPOSITE_CONTRAST = 3;

// The color for a large character or a text beside it on `background`: the other color of the flag when it reads there,
// otherwise black or white, whichever reads better.
export function oppositeOn(background, other) {
    return contrastRatio(background, other) >= MIN_OPPOSITE_CONTRAST
        ? other
        : textColorOn(background);
}

// Whether the two halves are too alike to tell apart.
export function tooClose(a, b) {
    return contrastRatio(a, b) < MIN_PAIR_CONTRAST;
}

// The text a new guidon starts with, from the unit's designation: the battalion and the 7 on the top left ("2/7"),
// the company letter on the bottom right ("A"). A battalion gets only its number; the regiment gets "7". A designation
// that is not a number (ACD) is used as it is, kept to what a guidon may carry.
export function defaultText(unit) {
    const callsign = String(unit?.callsign ?? "");
    const battalion = (designation) => {
        const numbered = /^(\d+)-7$/.exec(designation);

        return numbered ? `${numbered[1]}/7` : cleanText(designation);
    };

    if (unit?.type === "company") {
        const [letter = "", bn = ""] = callsign.split("/");

        return { topLeft: battalion(bn), bottomRight: cleanText(letter) };
    }
    if (unit?.type === "battalion")
        return { topLeft: battalion(callsign), bottomRight: "" };

    return { topLeft: "7", bottomRight: "" };
}

export function defaultDesign(unit) {
    return {
        format: DEFAULT_FORMAT,
        primary: "#1f2a44",
        secondary: "#ffffff",
        ...defaultText(unit),
        topAlign: DEFAULT_ALIGN,
        bottomAlign: DEFAULT_ALIGN,
        large: "",
    };
}

function escapeXml(value) {
    return String(value).replace(
        /[&<>"']/g,
        (c) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&apos;",
            })[c],
    );
}

// Text is drawn as paths from Oswald Medium's outlines (lib/guidonGlyphs.js), a tall, narrow face of medium weight like the regiment's
// official guidons. A font would not reach the flag: it is shown as an <img> and downloaded as a file, so the outlines
// travel inside the SVG and it draws the same in every browser, image viewer and wiki.
function textWidth(text) {
    return [...text].reduce((sum, c) => sum + (GLYPHS[c]?.[0] ?? 0), 0);
}

// The ink box of each glyph in font units, from its path's points. A curve stays inside its control points, so the
// box is never smaller than the ink.
const glyphBoxes = {};
function glyphBox(c) {
    if (c in glyphBoxes) return glyphBoxes[c];
    const box = {
        minX: Infinity,
        minY: Infinity,
        maxX: -Infinity,
        maxY: -Infinity,
    };
    let x = 0;
    let y = 0;
    const take = () => {
        box.minX = Math.min(box.minX, x);
        box.maxX = Math.max(box.maxX, x);
        box.minY = Math.min(box.minY, y);
        box.maxY = Math.max(box.maxY, y);
    };
    for (const [, cmd, args] of (GLYPHS[c]?.[1] ?? "").matchAll(
        /([MLQHVZ])([^MLQHVZ]*)/g,
    )) {
        const n = args.trim()
            ? args
                  .trim()
                  .split(/[\s,]+/)
                  .map(Number)
            : [];
        if (cmd === "H") n.forEach((v) => ((x = v), take()));
        else if (cmd === "V") n.forEach((v) => ((y = v), take()));
        else
            for (let i = 0; i + 1 < n.length; i += 2)
                ((x = n[i]), (y = n[i + 1]), take());
    }
    glyphBoxes[c] = box.minX === Infinity ? null : box;

    return glyphBoxes[c];
}

// The ink box of a whole text in font units, x from its first glyph's origin, y down from the baseline. Null for a
// text with no ink.
export function inkBox(text) {
    let advance = 0;
    let box = null;
    for (const c of text) {
        const g = glyphBox(c);
        if (g) {
            box ??= {
                minX: Infinity,
                minY: Infinity,
                maxX: -Infinity,
                maxY: -Infinity,
            };
            box.minX = Math.min(box.minX, advance + g.minX);
            box.maxX = Math.max(box.maxX, advance + g.maxX);
            box.minY = Math.min(box.minY, g.minY);
            box.maxY = Math.max(box.maxY, g.maxY);
        }
        advance += GLYPHS[c]?.[0] ?? 0;
    }

    return box;
}

// How far every letter, digit and large character keeps from the flag's edge, the cut and the split between colors.
export const EDGE_PAD = 6;

// The flag's right edge at height y: the fly at the top and bottom, the swallowtail cut between.
export const flagRight = (y) => WIDTH - NOTCH + Math.abs(y - HEIGHT / 2);
// Where the diagonal split crosses height y.
export const diagonalX = (y) => WIDTH - (y * WIDTH) / HEIGHT;

// A region a text may sit in: a band of heights and, at each height, the x range it may use, always inside the
// flag's outline.
const region = (top, bottom, lo = () => 0, hi = () => Infinity) => ({
    top,
    bottom,
    lo,
    hi: (y) => Math.min(hi(y), flagRight(y)),
});

// The flag's outline: the hoist on the left, the swallowtail cut in the fly on the right.
const OUTLINE = `0,0 ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT} 0,${HEIGHT}`;

// Quartered: the swallowtail split across the middle (as the top and bottom format) and by an upright line at
// QUARTER_SPLIT, the first color top left and bottom right, the second top right and bottom left; each right quarter
// carries its tail. The line is where the four quarters have the same area: the left ones are QUARTER_SPLIT wide, the
// right ones run from it to the fly at the edge and to the cut's point (WIDTH - NOTCH) at the middle, so
// QUARTER_SPLIT * H = (2 * WIDTH - NOTCH - 2 * QUARTER_SPLIT) * H / 2 gives (2 * WIDTH - NOTCH) / 4 = 123. Splitting the
// body before the tails at its middle (x = 96) would leave the right quarters half again as big as the left ones.
export const QUARTER_SPLIT = (2 * WIDTH - NOTCH) / 4;
const split = () => QUARTER_SPLIT;

export const REGIONS = {
    flag: region(0, HEIGHT),
    topHalf: region(0, HEIGHT / 2),
    bottomHalf: region(HEIGHT / 2, HEIGHT),
    diagonalTop: region(0, HEIGHT, () => 0, diagonalX),
    diagonalBottom: region(0, HEIGHT, diagonalX),
    quarterTopLeft: region(0, HEIGHT / 2, () => 0, split),
    quarterTopRight: region(0, HEIGHT / 2, split),
    quarterBottomRight: region(HEIGHT / 2, HEIGHT, split),
    leftColumn: region(0, HEIGHT, () => 0, split),
};

// The x range free for a box from y0 to y1 in a region, EDGE_PAD in from every edge. The band is widened by the pad
// too, so a slanted edge or the cut's point stays at least the pad away. Null when the box doesn't fit at all.
export function usableSpan(r, y0, y1, pad = EDGE_PAD) {
    const top = y0 - pad;
    const bottom = y1 + pad;
    if (top < r.top || bottom > r.bottom) return null;
    const ys = [top, bottom];
    for (let y = Math.ceil(top); y < bottom; y += 0.5) ys.push(y);
    if (top < HEIGHT / 2 && bottom > HEIGHT / 2) ys.push(HEIGHT / 2);
    const lo = Math.max(...ys.map(r.lo)) + pad;
    const hi = Math.min(...ys.map(r.hi)) - pad;

    return hi > lo ? [lo, hi] : null;
}

// Where a text goes: its scale and the origin of its first glyph on the baseline, its capitals centered on y, at most
// `cap` tall and `maxWidth` wide, shrunk until its ink stays inside the region. `mode` is 'start' (first glyph at x,
// or further right when the edge is closer), 'axis' (ink centered on x) or 'span' (ink centered on the space free at
// its height). Null for a text with no ink.
export function fitText(
    text,
    { x = 0, y, cap, maxWidth = Infinity, mode = "start", region: r },
) {
    const ink = inkBox(text);
    if (!ink) return null;
    // Checked at the precision the drawing writes, so rounding can't push the ink over.
    const round = (n, places) => Math.round(n * 10 ** places) / 10 ** places;
    for (
        let shrink = Math.min(cap / CAP_HEIGHT, maxWidth / textWidth(text));
        shrink > 0.001;
        shrink *= 0.98
    ) {
        const scale = round(shrink, 4);
        const baseline = round(y + (CAP_HEIGHT * scale) / 2, 2);
        const box = {
            y0: baseline + ink.minY * scale,
            y1: baseline + ink.maxY * scale,
        };
        const span = usableSpan(r, box.y0, box.y1);
        if (!span) continue;
        const middle = ((ink.minX + ink.maxX) / 2) * scale;
        const left = round(
            mode === "start"
                ? Math.max(x, span[0] - ink.minX * scale)
                : mode === "axis"
                  ? x - middle
                  : (span[0] + span[1]) / 2 - middle,
            2,
        );
        box.x0 = left + ink.minX * scale;
        box.x1 = left + ink.maxX * scale;
        if (box.x0 >= span[0] - 1e-9 && box.x1 <= span[1] + 1e-9)
            return { text, scale, left, baseline, box };
    }

    return null;
}

// Two texts in one column: short ones (two characters or fewer) share a center line, that of the wider one, since
// left edges make an A over a 7 look off; longer ones keep the common left edge.
const SHORT = 2;
function column(top, bottom, topOptions, bottomOptions) {
    const a = fitText(top, topOptions);
    const b = fitText(bottom, bottomOptions);
    if (!a || !b || top.trim().length > SHORT || bottom.trim().length > SHORT)
        return [a, b];
    const wider = a.box.x1 - a.box.x0 >= b.box.x1 - b.box.x0 ? a : b;
    const axis = (wider.box.x0 + wider.box.x1) / 2;

    return [
        fitText(top, { ...topOptions, x: axis, mode: "axis" }),
        fitText(bottom, { ...bottomOptions, x: axis, mode: "axis" }),
    ];
}

// A fitted text drawn as paths. The group carries the text and its cap height as data, for the tests and anyone
// reading the file.
function draw(fit, fill) {
    if (!fit) return "";
    let advance = 0;
    const paths = [...fit.text].map((c) => {
        const [width, d] = GLYPHS[c];
        const path = d
            ? `<path transform="translate(${advance})" d="${d}"/>`
            : "";
        advance += width;

        return path;
    });

    return `<g data-text="${escapeXml(fit.text)}" data-cap="${Math.round(CAP_HEIGHT * fit.scale)}" fill="${fill}" transform="translate(${fit.left} ${fit.baseline}) scale(${fit.scale})">${paths.join("")}</g>`;
}

// The top and bottom and the solid formats: each text's capitals sit in the middle of its half's height, left near
// the hoist or centered on the part of the half free at its height.
const HALF_LEFT = 24;
const HALF_CAP = 64;

// The large character hugs the hoist (about 3% of the width in) and is about 72% of the flag's height, centered on
// it; two characters shrink to end by LARGE_RIGHT. The texts start LARGE_GAP (about 5% of the width) after its ink:
// a narrow 1 or 7 pulls them closer than a wide 2 or two characters.
const LARGE_LEFT = 9;
const LARGE_RIGHT = 148;
const LARGE_CAP = Math.round(HEIGHT * 0.72);
const LARGE_GAP = 14;

function largeFit(text, place = null) {
    if (!text) return null;

    return place
        ? fitText(text, {
              y: HEIGHT / 2,
              cap: LARGE_CAP,
              maxWidth: place.width,
              mode: "span",
              region: place.region,
          })
        : fitText(text, {
              x: LARGE_LEFT,
              y: HEIGHT / 2,
              cap: LARGE_CAP,
              maxWidth: LARGE_RIGHT - LARGE_LEFT,
              region: REGIONS.flag,
          });
}

// Where the texts start beside a large character.
const besideLarge = (fit) => Math.round(fit.box.x1 + LARGE_GAP);

// The large character and the two texts of the top and bottom and the solid formats.
function halfFits(design) {
    const top = { y: HEIGHT / 4, cap: HALF_CAP, region: REGIONS.topHalf };
    const bottom = {
        y: (HEIGHT * 3) / 4,
        cap: HALF_CAP,
        region: REGIONS.bottomHalf,
    };
    const large = largeFit(design.large);
    if (large) {
        const x = besideLarge(large);

        return [
            large,
            ...column(
                design.topLeft,
                design.bottomRight,
                { ...top, x },
                { ...bottom, x },
            ),
        ];
    }
    const one = (text, options, align) =>
        fitText(
            text,
            align === "center"
                ? { ...options, mode: "span" }
                : { ...options, x: HALF_LEFT },
        );

    return [
        null,
        one(design.topLeft, top, design.topAlign),
        one(design.bottomRight, bottom, design.bottomAlign),
    ];
}

// The large character, most of the height of the flag across both halves, like the wiki's Reserve guidon. It takes
// the opposite color of each half it crosses: drawn once per half, clipped to that half, in the other color.
function largeChar(fit, halves) {
    if (!fit) return [];

    return halves.flatMap(({ points, fill }, i) => [
        `<clipPath id="guidon-half-${i}"><polygon points="${points}"/></clipPath>`,
        `<g clip-path="url(#guidon-half-${i})">${draw(fit, fill)}</g>`,
    ]);
}

// Each format's shapes and texts, inside the `0 0 WIDTH HEIGHT` view box. Each text takes the color that reads on the
// half it sits on, and shrinks where it would reach an edge, the cut or the split.
function diagonal(design, primary, secondary) {
    // The diagonal runs from the bottom left corner to the top right one; the bottom right half carries the tails.
    const topLeft = `0,0 ${WIDTH},0 0,${HEIGHT}`;
    const bottomRight = `0,${HEIGHT} ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT}`;
    const shapes = [
        `<polygon points="${topLeft}" fill="${primary}"/>`,
        `<polygon points="${bottomRight}" fill="${secondary}"/>`,
    ];

    // Without a large character the texts keep the spots a guidon saved before formats had.
    if (!design.large) {
        return [
            ...shapes,
            draw(
                fitText(design.topLeft, {
                    x: 80,
                    y: 72,
                    cap: 70,
                    maxWidth: 150,
                    mode: "axis",
                    region: REGIONS.diagonalTop,
                }),
                textColorOn(primary),
            ),
            draw(
                fitText(design.bottomRight, {
                    x: 172,
                    y: 160,
                    cap: 80,
                    maxWidth: 110,
                    mode: "axis",
                    region: REGIONS.diagonalBottom,
                }),
                textColorOn(secondary),
            ),
        ];
    }

    // With a large character the texts take the other half's color too, as on the wiki's Reserve guidon. Both start
    // just right of it, the top text in the corner above the diagonal, the bottom text below it, clear of the cut.
    const large = largeFit(design.large);
    const x = besideLarge(large);
    const [top, bottom] = column(
        design.topLeft,
        design.bottomRight,
        { x, y: 44, cap: 44, region: REGIONS.diagonalTop },
        { x, y: 172, cap: 48, region: REGIONS.diagonalBottom },
    );

    return [
        ...shapes,
        ...largeChar(large, [
            { points: topLeft, fill: oppositeOn(primary, secondary) },
            { points: bottomRight, fill: oppositeOn(secondary, primary) },
        ]),
        draw(top, oppositeOn(primary, secondary)),
        draw(bottom, oppositeOn(secondary, primary)),
    ];
}

// Split across the middle: the first color on top down to the cut's point, the second below it with the tails.
function horizontal(design, primary, secondary) {
    const top = `0,0 ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} 0,${HEIGHT / 2}`;
    const bottom = `0,${HEIGHT / 2} ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT} 0,${HEIGHT}`;
    const [large, topText, bottomText] = halfFits(design);

    return [
        `<polygon points="${top}" fill="${primary}"/>`,
        `<polygon points="${bottom}" fill="${secondary}"/>`,
        ...largeChar(large, [
            { points: top, fill: oppositeOn(primary, secondary) },
            { points: bottom, fill: oppositeOn(secondary, primary) },
        ]),
        draw(
            topText,
            design.large
                ? oppositeOn(primary, secondary)
                : textColorOn(primary),
        ),
        draw(
            bottomText,
            design.large
                ? oppositeOn(secondary, primary)
                : textColorOn(secondary),
        ),
    ];
}

// One color; the texts sit where the top and bottom format puts them, and a large character in the color that reads.
function solid(design, primary) {
    const fill = textColorOn(primary);
    const [large, topText, bottomText] = halfFits(design);

    return [
        `<polygon points="${OUTLINE}" fill="${primary}"/>`,
        ...largeChar(large, [{ points: OUTLINE, fill }]),
        draw(topText, fill),
        draw(bottomText, fill),
    ];
}

const QUARTERS = {
    topLeft: `0,0 ${QUARTER_SPLIT},0 ${QUARTER_SPLIT},${HEIGHT / 2} 0,${HEIGHT / 2}`,
    topRight: `${QUARTER_SPLIT},0 ${WIDTH},0 ${WIDTH - NOTCH},${HEIGHT / 2} ${QUARTER_SPLIT},${HEIGHT / 2}`,
    bottomLeft: `0,${HEIGHT / 2} ${QUARTER_SPLIT},${HEIGHT / 2} ${QUARTER_SPLIT},${HEIGHT} 0,${HEIGHT}`,
    bottomRight: `${QUARTER_SPLIT},${HEIGHT / 2} ${WIDTH - NOTCH},${HEIGHT / 2} ${WIDTH},${HEIGHT} ${QUARTER_SPLIT},${HEIGHT}`,
};
// The quarter texts are about a quarter of the flag's height, 12 units in from the quarter's edges.
const QUARTER_CAP = 56;
const QUARTER_PAD = 12;

function quartered(design, primary, secondary) {
    const shapes = [
        `<polygon points="${QUARTERS.topLeft}" fill="${primary}"/>`,
        `<polygon points="${QUARTERS.topRight}" fill="${secondary}"/>`,
        `<polygon points="${QUARTERS.bottomLeft}" fill="${secondary}"/>`,
        `<polygon points="${QUARTERS.bottomRight}" fill="${primary}"/>`,
    ];
    const top = { y: HEIGHT / 4, cap: QUARTER_CAP };
    const bottom = { y: (HEIGHT * 3) / 4, cap: QUARTER_CAP };

    // Without a large character the top text sits in the top left quarter and the bottom text in the bottom right
    // one, both on the first color.
    if (!design.large) {
        const one = (text, options, align, from) =>
            fitText(
                text,
                align === "center"
                    ? { ...options, mode: "span" }
                    : { ...options, x: from },
            );

        return [
            ...shapes,
            draw(
                one(
                    design.topLeft,
                    { ...top, region: REGIONS.quarterTopLeft },
                    design.topAlign,
                    QUARTER_PAD,
                ),
                textColorOn(primary),
            ),
            draw(
                one(
                    design.bottomRight,
                    { ...bottom, region: REGIONS.quarterBottomRight },
                    design.bottomAlign,
                    QUARTER_SPLIT + QUARTER_PAD,
                ),
                textColorOn(primary),
            ),
        ];
    }

    // The large character fills the left quarters, centered on them, in the opposite color of each; the top text
    // moves right into the top right quarter, so both texts sit in the right column, each in its quarter's opposite,
    // starting right after the split (a text can't cross into a quarter of the other color).
    const large = largeFit(design.large, {
        width: QUARTER_SPLIT - 2 * QUARTER_PAD,
        region: REGIONS.leftColumn,
    });
    const x = QUARTER_SPLIT + QUARTER_PAD;
    const [topText, bottomText] = column(
        design.topLeft,
        design.bottomRight,
        { ...top, x, region: REGIONS.quarterTopRight },
        { ...bottom, x, region: REGIONS.quarterBottomRight },
    );

    return [
        ...shapes,
        ...largeChar(large, [
            { points: QUARTERS.topLeft, fill: oppositeOn(primary, secondary) },
            {
                points: QUARTERS.bottomLeft,
                fill: oppositeOn(secondary, primary),
            },
        ]),
        draw(topText, oppositeOn(secondary, primary)),
        draw(bottomText, oppositeOn(primary, secondary)),
    ];
}

const DRAW = { diagonal, horizontal, quartered, solid };

// The flag as an SVG document, `width` pixels wide; `plain` leaves out the texts and the large character. Null for a
// design that breaks the rules: nothing is drawn from it.
export function guidonSvg(design, { width = WIDTH, plain = false } = {}) {
    if (!isDesign(design)) return null;

    const height = Math.round((width * HEIGHT) / WIDTH);
    const primary = design.primary.toLowerCase();
    const secondary = design.secondary.toLowerCase();

    return [
        `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${WIDTH} ${HEIGHT}">`,
        ...DRAW[formatOf(design)](
            plain
                ? { ...design, topLeft: "", bottomRight: "", large: "" }
                : design,
            primary,
            secondary,
        ),
        // A faint edge, so a white half still shows against a white page.
        `<polygon points="${OUTLINE}" fill="none" stroke="#000000" stroke-opacity="0.25" stroke-width="2" stroke-linejoin="round"/>`,
        "</svg>",
    ].join("");
}

// The flag as a `data:` URL for an <img>: the browser draws it as an image, never as part of the page.
export function guidonDataUrl(design, options) {
    const svg = guidonSvg(design, options);

    return svg === null
        ? null
        : `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

// The file name a download gets: `guidon-A-1-7.svg`.
export function guidonFileName(unit, extension) {
    const name = String(unit?.callsign ?? unit?.name ?? "unit")
        .replace(/[^A-Za-z0-9-]+/g, "-")
        .replace(/^-+|-+$/g, "");

    return `guidon-${name || "unit"}.${extension}`;
}

// The flag as a PNG `data:` URL, `width` pixels wide, drawn on a canvas from the same SVG.
export function guidonPngDataUrl(design, width = DOWNLOAD_WIDTH) {
    const src = guidonDataUrl(design, { width });
    if (src === null) return Promise.reject(new Error("Not a guidon design."));

    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => {
            const canvas = document.createElement("canvas");
            canvas.width = width;
            canvas.height = Math.round((width * HEIGHT) / WIDTH);
            canvas
                .getContext("2d")
                .drawImage(image, 0, 0, canvas.width, canvas.height);
            resolve(canvas.toDataURL("image/png"));
        };
        image.onerror = () =>
            reject(new Error("The guidon could not be drawn."));
        image.src = src;
    });
}
