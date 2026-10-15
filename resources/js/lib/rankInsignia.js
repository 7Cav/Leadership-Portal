// How the forum's rank insignia images sit inside their files, so the post preview can draw every rank at the same
// visual weight and centred on the insignia itself. The forum serves them at their own sizes (a General of the Army is
// 75 by 69 pixels, a First Lieutenant's bar 10 by 30) and with uneven empty space around the insignia (a Lieutenant
// General's three stars are 22 pixels tall in a 75 by 75 file), so a plain size cap makes some ranks huge and others
// tiny or off centre.
//
// Measured from https://7cav.us/data/roster_ranks/0/{n}.jpg: the file's width and height, then the box around the
// insignia's pixels. Keyed by the image number, which is the rank's id in the Cav roster.
const MEASURED = {
    1: [75, 69, 1, 1, 73, 68], // General of the Army
    2: [75, 17, 1, 0, 73, 17], // General
    3: [75, 75, 1, 27, 73, 22], // Lieutenant General
    4: [75, 75, 1, 21, 73, 34], // Major General
    5: [50, 50, 4, 5, 43, 41], // Brigadier General
    6: [75, 38, 0, 0, 75, 38], // Colonel
    7: [33, 35, 0, 0, 33, 35], // Lieutenant Colonel
    8: [33, 35, 0, 0, 33, 35], // Major
    9: [30, 30, 0, 0, 30, 30], // Captain
    10: [10, 30, 0, 0, 10, 30], // First Lieutenant
    11: [10, 30, 0, 0, 10, 30], // Second Lieutenant
    12: [30, 54, 1, 1, 28, 52], // Command Sergeant Major
    13: [30, 54, 1, 2, 28, 52], // Sergeant Major
    14: [30, 54, 1, 1, 28, 52], // First Sergeant
    15: [30, 54, 1, 2, 28, 52], // Master Sergeant
    16: [30, 50, 1, 2, 28, 48], // Sergeant First Class
    17: [30, 45, 1, 1, 28, 43], // Staff Sergeant
    18: [30, 38, 1, 1, 28, 37], // Sergeant
    19: [30, 33, 1, 1, 28, 32], // Corporal
    20: [30, 33, 1, 0, 28, 32], // Specialist
    21: [30, 33, 1, 2, 28, 30], // Private First Class
    22: [30, 28, 1, 2, 28, 26], // Private
    23: [32, 37, 5, 1, 24, 32], // Recruit
    26: [9, 30, 0, 0, 9, 30], // Chief Warrant Officer 5
    27: [9, 30, 0, 0, 9, 30], // Chief Warrant Officer 4
    28: [9, 30, 0, 0, 9, 30], // Chief Warrant Officer 3
    29: [9, 30, 0, 0, 9, 30], // Chief Warrant Officer 2
    30: [9, 30, 0, 0, 9, 30], // Warrant Officer 1
};

/** Every insignia is drawn inside a square of this many pixels. */
export const INSIGNIA_BOX = 18;

// The insignia is scaled so its area matches a square of this side, then shrunk further if it would leave the box.
// Sizing by area rather than by height keeps a thin bar from looming over a set of chevrons, and a wide row of stars
// from shrinking to a speck.
const WEIGHT = 15;

const RANK_IMAGE = /^https:\/\/7cav\.us\/data\/roster_ranks\/0\/(\d+)\.jpg(\?\d+)?$/;

/**
 * Where to draw a rank insignia inside an `INSIGNIA_BOX` square: the image's drawn width and height and its offset, so
 * the insignia's own centre lands in the middle of the square. Null for an image that isn't a known rank insignia.
 *
 * @param {string} src
 * @returns {{width: number, height: number, left: number, top: number} | null}
 */
export function insigniaPlacement(src) {
    const match = RANK_IMAGE.exec(String(src));
    const measured = match ? MEASURED[match[1]] : undefined;
    if (!measured) return null;

    const [fileWidth, fileHeight, x, y, width, height] = measured;
    const scale = Math.min(WEIGHT / Math.sqrt(width * height), INSIGNIA_BOX / Math.max(width, height));
    const round = (n) => Math.round(n * 100) / 100;

    return {
        width: round(fileWidth * scale),
        height: round(fileHeight * scale),
        left: round(INSIGNIA_BOX / 2 - (x + width / 2) * scale),
        top: round(INSIGNIA_BOX / 2 - (y + height / 2) * scale),
    };
}
