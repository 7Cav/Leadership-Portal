// How the forum's rank insignia images sit inside their files, so the post preview can draw every rank at the same
// visual weight and optically centred. The forum serves them at their own sizes (a General of the Army is 75 by 69
// pixels, a First Lieutenant's bar 10 by 30), with uneven empty space (a Lieutenant General's three stars are 22 pixels
// tall in a 75 by 75 file), and with very different amounts of metal: a lieutenant's bar is solid, a set of chevrons is
// mostly gaps. Sized by their files, or even by the box around them, the bars look heavy and the chevrons look high.
//
// Measured from https://7cav.us/data/roster_ranks/0/{n}.jpg and keyed by the image number (the rank's id in the Cav
// roster): the file's width and height; the box around the insignia's pixels (x, y, width, height); the insignia's
// centre of mass (cx, cy), which is where the eye puts its middle; and its ink, the area its pixels cover.
const MEASURED = {
    1: [75, 69, 1, 1, 74, 68, 37.7, 38.0, 1189], // General of the Army
    2: [75, 17, 0, 0, 75, 17, 37.7, 9.2, 422], // General
    3: [75, 75, 1, 27, 74, 22, 37.8, 38.9, 516], // Lieutenant General
    4: [75, 75, 1, 21, 73, 34, 38.0, 39.1, 762], // Major General
    5: [50, 50, 3, 5, 44, 41, 25.7, 26.7, 562], // Brigadier General
    6: [75, 38, 0, 0, 75, 38, 36.6, 17.3, 1485], // Colonel
    7: [33, 35, 0, 0, 33, 35, 16.2, 17.6, 587], // Lieutenant Colonel
    8: [33, 35, 0, 0, 33, 35, 16.6, 17.2, 681], // Major
    9: [30, 30, 0, 0, 30, 30, 15.0, 15.0, 668], // Captain
    10: [10, 30, 0, 0, 10, 30, 5.0, 15.0, 289], // First Lieutenant
    11: [10, 30, 0, 0, 10, 30, 5.0, 15.0, 289], // Second Lieutenant
    12: [30, 54, 1, 1, 28, 52, 15.0, 31.3, 1081], // Command Sergeant Major
    13: [30, 54, 1, 2, 28, 52, 15.0, 32.3, 1080], // Sergeant Major
    14: [30, 54, 1, 1, 28, 52, 15.0, 31.3, 1080], // First Sergeant
    15: [30, 54, 1, 2, 28, 52, 15.0, 32.3, 1080], // Master Sergeant
    16: [30, 50, 1, 2, 28, 48, 15.0, 30.1, 956], // Sergeant First Class
    17: [30, 45, 1, 1, 28, 43, 15.0, 26.2, 807], // Staff Sergeant
    18: [30, 38, 1, 1, 28, 37, 15.0, 22.3, 583], // Sergeant
    19: [30, 33, 1, 1, 28, 32, 15.0, 19.4, 435], // Corporal
    20: [30, 33, 1, 0, 28, 32, 15.0, 12.7, 478], // Specialist
    21: [30, 33, 1, 2, 28, 30, 15.0, 20.2, 476], // Private First Class
    22: [30, 28, 1, 2, 28, 26, 15.0, 16.8, 265], // Private
    23: [32, 37, 5, 1, 24, 32, 16.3, 14.8, 483], // Recruit
    26: [9, 30, 0, 0, 9, 30, 4.5, 15.0, 270], // Chief Warrant Officer 5
    27: [9, 30, 0, 0, 9, 30, 4.5, 15.0, 270], // Chief Warrant Officer 4
    28: [9, 30, 0, 0, 9, 30, 4.5, 15.0, 270], // Chief Warrant Officer 3
    29: [9, 30, 0, 0, 9, 30, 4.5, 15.0, 270], // Chief Warrant Officer 2
    30: [9, 30, 0, 0, 9, 30, 4.5, 15.0, 270], // Warrant Officer 1
};

/** Every insignia is drawn inside a square of this many pixels. */
export const INSIGNIA_BOX = 18;

// Every insignia is scaled to cover the same area (in square pixels) once drawn, so a solid bar and an open set of
// chevrons carry the same weight; one that would then overflow the square (a wide row of stars) is shrunk to fit.
const INK = 85;

const RANK_IMAGE = /^https:\/\/7cav\.us\/data\/roster_ranks\/0\/(\d+)\.jpg(\?\d+)?$/;

// The narrowest an insignia is ever drawn, so a thin bar is still a mark.
const MIN_WIDTH = 3;

// The measurements of a known rank insignia and the scale that gives it the same weight as every other, or null.
function scaled(src) {
    const match = RANK_IMAGE.exec(String(src));
    const measured = match ? MEASURED[match[1]] : undefined;
    if (!measured) return null;

    const [, , , , width, height, , , ink] = measured;

    return { measured, scale: Math.min(Math.sqrt(INK / ink), INSIGNIA_BOX / Math.max(width, height)) };
}

/**
 * The width, in whole pixels, to show a rank insignia at on the forum so every rank carries the same visual weight: its
 * file's width at the scale {@link insigniaPlacement} draws it at, never under 3. The forum takes this as
 * `[IMG width="..px" height="auto"]` and scales the height to match. An image that isn't a known rank insignia gets the
 * square's size.
 *
 * @param {string} src
 * @returns {number}
 */
export function insigniaWidth(src) {
    const found = scaled(src);

    return found ? Math.max(MIN_WIDTH, Math.round(found.measured[0] * found.scale)) : INSIGNIA_BOX;
}

/**
 * Where to draw a rank insignia inside an `INSIGNIA_BOX` square: the image's drawn width and height and its offset, so
 * the insignia's centre of mass lands in the middle of the square, nudged only as far as needed to keep the whole
 * insignia inside it. Null for an image that isn't a known rank insignia.
 *
 * @param {string} src
 * @returns {{width: number, height: number, left: number, top: number} | null}
 */
export function insigniaPlacement(src) {
    const found = scaled(src);
    if (!found) return null;

    const { measured, scale } = found;
    const [fileWidth, fileHeight, x, y, width, height, cx, cy] = measured;
    // Put the centre of mass in the middle, then keep the insignia's box within the square.
    const fit = (center, start, size) => {
        const offset = INSIGNIA_BOX / 2 - center * scale;
        const low = -start * scale;
        const high = INSIGNIA_BOX - (start + size) * scale;

        return Math.min(Math.max(offset, Math.min(low, high)), Math.max(low, high));
    };
    const round = (n) => Math.round(n * 100) / 100;

    return {
        width: round(fileWidth * scale),
        height: round(fileHeight * scale),
        left: round(fit(cx, x, width)),
        top: round(fit(cy, y, height)),
    };
}
