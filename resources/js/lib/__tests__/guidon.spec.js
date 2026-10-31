import { describe, expect, it } from "vitest";
import { CAP_HEIGHT } from "../guidonGlyphs";
import {
    EDGE_PAD,
    HEIGHT,
    REGIONS,
    diagonalX,
    fitText,
    flagRight,
    inkBox,
    usableSpan,
    QUARTER_SPLIT,
    TEXTLESS_BELOW,
    WIDTH,
    cleanText,
    contrastRatio,
    defaultDesign,
    defaultText,
    formatOf,
    guidonDataUrl,
    guidonFileName,
    guidonSvg,
    cleanLarge,
    isDesign,
    pairTooClose,
    textColorOn,
    tooClose,
} from "../guidon";

const design = {
    primary: "#1f2a44",
    secondary: "#ffffff",
    topLeft: "2/7",
    bottomRight: "A",
};

function parse(svg) {
    return new DOMParser().parseFromString(svg, "image/svg+xml")
        .documentElement;
}

// The texts, drawn as paths: each is a group carrying its text and cap height. `large` picks the large character's
// copies (clipped to a half) or the other texts.
function texts(svg, large = false) {
    return [...svg.querySelectorAll("[data-text]")].filter(
        (g) => g.parentNode.hasAttribute("clip-path") === large,
    );
}

// Where a text's group starts: its left edge and baseline.
function origin(g) {
    const [, x, y] = /translate\(([\d.-]+) ([\d.-]+)\)/.exec(
        g.getAttribute("transform"),
    );

    return { x: Number(x), y: Number(y) };
}

describe("guidonSvg", () => {
    it("draws the swallowtail split on the diagonal, the first color top left and the second bottom right", () => {
        const svg = parse(guidonSvg(design));
        const [topLeft, bottomRight, outline] = svg.querySelectorAll("polygon");

        expect(svg.getAttribute("viewBox")).toBe(`0 0 ${WIDTH} ${HEIGHT}`);
        expect(topLeft.getAttribute("points")).toBe("0,0 300,0 0,216");
        expect(topLeft.getAttribute("fill")).toBe("#1f2a44");
        // The bottom right half runs from the diagonal to the tails, with the swallowtail cut in the fly.
        expect(bottomRight.getAttribute("points")).toBe(
            "0,216 300,0 192,108 300,216",
        );
        expect(bottomRight.getAttribute("fill")).toBe("#ffffff");
        expect(outline.getAttribute("points")).toBe(
            "0,0 300,0 192,108 300,216 0,216",
        );
    });

    it("puts each text on its half in the color that reads on it", () => {
        const [topLeft, bottomRight] = texts(parse(guidonSvg(design)));

        expect(topLeft.getAttribute("data-text")).toBe("2/7");
        expect(topLeft.getAttribute("fill")).toBe("#ffffff");
        expect(bottomRight.getAttribute("data-text")).toBe("A");
        expect(bottomRight.getAttribute("fill")).toBe("#000000");
    });

    it("draws the text as paths in a condensed face, so no font is needed wherever the file goes", () => {
        const svg = parse(guidonSvg(design));
        const [topLeft] = texts(svg);

        expect(svg.querySelectorAll("text")).toHaveLength(0);
        expect(guidonSvg(design)).not.toContain("font-family");
        // One path per glyph: 2, / and 7.
        expect(topLeft.querySelectorAll("path")).toHaveLength(3);
        expect(Number(topLeft.getAttribute("data-cap"))).toBe(70);
    });

    it("draws a plain guidon on request: the colors and split only, no texts and no large character", () => {
        const svg = parse(
            guidonSvg({ ...design, large: "7" }, { plain: true }),
        );

        expect(svg.querySelectorAll("[data-text], clipPath")).toHaveLength(0);
        expect(svg.querySelectorAll("polygon")).toHaveLength(3);
        expect(TEXTLESS_BELOW).toBe(20);
    });

    it("leaves out an empty text and shrinks a long one", () => {
        const short = parse(
            guidonSvg({ ...design, topLeft: "7", bottomRight: "" }),
        );
        const long = parse(guidonSvg({ ...design, topLeft: "ACD 12" }));

        expect(texts(short)).toHaveLength(1);
        expect(Number(texts(long)[0].getAttribute("data-cap"))).toBeLessThan(
            Number(texts(short)[0].getAttribute("data-cap")),
        );
    });

    it("scales to the width asked for, keeping the proportions", () => {
        const svg = parse(guidonSvg(design, { width: 600 }));

        expect(svg.getAttribute("width")).toBe("600");
        expect(svg.getAttribute("height")).toBe("432");
    });

    it("draws nothing from a design that breaks the rules", () => {
        expect(guidonSvg(null)).toBeNull();
        expect(guidonSvg({ ...design, primary: "red" })).toBeNull();
        expect(guidonSvg({ ...design, primary: '#fff" onload="x' })).toBeNull();
        expect(guidonSvg({ ...design, topLeft: "<script>" })).toBeNull();
        expect(guidonSvg({ ...design, bottomRight: "TOOLONG" })).toBeNull();
        expect(guidonDataUrl({ ...design, secondary: "#12345" })).toBeNull();
        expect(isDesign(design)).toBe(true);
    });

    it("is a data URL an <img> can show", () => {
        expect(guidonDataUrl(design)).toMatch(
            /^data:image\/svg\+xml;charset=utf-8,%3Csvg/,
        );
        expect(decodeURIComponent(guidonDataUrl(design).split(",")[1])).toBe(
            guidonSvg(design),
        );
    });
});

describe("contrast", () => {
    it("follows the WCAG ratio", () => {
        expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
        expect(contrastRatio("#777777", "#777777")).toBe(1);
        expect(contrastRatio("#ffffff", "#000000")).toBe(
            contrastRatio("#000000", "#ffffff"),
        );
    });

    it("picks black or white text, whichever reads better", () => {
        expect(textColorOn("#1f2a44")).toBe("#ffffff");
        expect(textColorOn("#000000")).toBe("#ffffff");
        expect(textColorOn("#ffffff")).toBe("#000000");
        expect(textColorOn("#ffc72c")).toBe("#000000");
        expect(textColorOn("#b22234")).toBe("#ffffff");
    });

    it("warns when the two colors are too close, not for the wiki pairs", () => {
        expect(tooClose("#1f2a44", "#ffffff")).toBe(false);
        expect(tooClose("#000000", "#b22234")).toBe(false);
        expect(tooClose("#1f2a44", "#000000")).toBe(true);
        expect(tooClose("#ffffff", "#f0f0f0")).toBe(true);
    });
});

describe("default text", () => {
    it("puts the battalion and the 7 top left and the company letter bottom right", () => {
        expect(defaultText({ type: "company", callsign: "A/2-7" })).toEqual({
            topLeft: "2/7",
            bottomRight: "A",
        });
        expect(defaultText({ type: "company", callsign: "B/ACD" })).toEqual({
            topLeft: "ACD",
            bottomRight: "B",
        });
    });

    it("gives a battalion its number and the regiment its 7", () => {
        expect(defaultText({ type: "battalion", callsign: "1-7" })).toEqual({
            topLeft: "1/7",
            bottomRight: "",
        });
        expect(defaultText({ type: "battalion", callsign: "DEVCOM" })).toEqual({
            topLeft: "DEVCOM",
            bottomRight: "",
        });
        expect(defaultText({ type: "regiment", callsign: "7CAV" })).toEqual({
            topLeft: "7",
            bottomRight: "",
        });
    });

    it("starts from a design that passes the rules", () => {
        expect(
            isDesign(defaultDesign({ type: "company", callsign: "A/2-7" })),
        ).toBe(true);
        expect(
            isDesign(
                defaultDesign({
                    type: "battalion",
                    callsign: "Very-Long Name",
                }),
            ),
        ).toBe(true);
    });
});

describe("helpers", () => {
    it("keeps typed text to what the server accepts", () => {
        expect(cleanText("<b>2/7</b>")).toBe("b2/7/b");
        expect(cleanText('A"&B')).toBe("AB");
        expect(cleanText("ABCDEFGH")).toBe("ABCDEF");
    });

    it("names a download after the unit", () => {
        expect(guidonFileName({ callsign: "A/2-7" }, "png")).toBe(
            "guidon-A-2-7.png",
        );
        expect(guidonFileName({ name: "7th Cavalry Regiment" }, "svg")).toBe(
            "guidon-7th-Cavalry-Regiment.svg",
        );
    });
});

describe("formats", () => {
    const acd = {
        format: "diagonal",
        primary: "#f47a20",
        secondary: "#ffffff",
        topLeft: "ACD",
        bottomRight: "B",
        topAlign: "left",
        bottomAlign: "left",
        large: "7",
    };

    it("draws a design saved before formats the same, with or without the new fields at their defaults", () => {
        const legacy = guidonSvg(design);

        expect(legacy).toContain(
            '<polygon points="0,0 300,0 0,216" fill="#1f2a44"/><polygon points="0,216 300,0 192,108 300,216" fill="#ffffff"/>',
        );
        expect(
            texts(parse(legacy)).map((g) => g.getAttribute("data-text")),
        ).toEqual(["2/7", "A"]);
        expect(
            guidonSvg({
                ...design,
                format: "diagonal",
                topAlign: "left",
                bottomAlign: "left",
                large: "",
            }),
        ).toBe(legacy);
        expect(guidonSvg({ ...design, topAlign: "center" })).toBe(legacy);
    });

    it("splits the top and bottom format across the middle, the first color on top", () => {
        const svg = parse(guidonSvg({ ...design, format: "horizontal" }));
        const [top, bottom] = svg.querySelectorAll("polygon");

        expect(top.getAttribute("points")).toBe("0,0 300,0 192,108 0,108");
        expect(top.getAttribute("fill")).toBe("#1f2a44");
        expect(bottom.getAttribute("points")).toBe(
            "0,108 192,108 300,216 0,216",
        );
        expect(bottom.getAttribute("fill")).toBe("#ffffff");
    });

    it("places each text left or centered on its half, in the color that reads on that half", () => {
        const rhq = {
            ...design,
            format: "horizontal",
            primary: "#b22234",
            topLeft: "U.S.",
            bottomRight: "7",
            topAlign: "center",
            bottomAlign: "left",
        };
        const [top, bottom] = texts(parse(guidonSvg(rhq)));

        expect(top.getAttribute("data-text")).toBe("U.S.");
        // Centered on the half clear of the cut, its capitals centered on the half's height.
        expect(origin(top).x).toBeGreaterThan(24);
        expect(origin(top).y).toBe(
            54 + Number(top.getAttribute("data-cap")) / 2,
        );
        expect(top.getAttribute("fill")).toBe("#ffffff");
        expect(origin(bottom).x).toBe(24);
        expect(origin(bottom).y).toBe(
            162 + Number(bottom.getAttribute("data-cap")) / 2,
        );
        expect(bottom.getAttribute("fill")).toBe("#000000");
    });

    it("draws a solid guidon in one color with no second half", () => {
        const svg = parse(
            guidonSvg({ ...design, format: "solid", primary: "#ffc72c" }),
        );
        const [flag] = svg.querySelectorAll("polygon");

        expect(svg.querySelectorAll("polygon")).toHaveLength(2);
        expect(flag.getAttribute("fill")).toBe("#ffc72c");
        expect(texts(svg).map((g) => g.getAttribute("fill"))).toEqual([
            "#000000",
            "#000000",
        ]);
    });

    it("draws the ACD guidon: a large 7 and both texts in black, as orange and white are too close for the other color to read, the texts right of the 7", () => {
        const svg = parse(guidonSvg(acd));
        const clips = svg.querySelectorAll("clipPath");
        const sevens = texts(svg, true);
        const [top, bottom] = texts(svg);

        expect(clips).toHaveLength(2);
        expect(clips[0].querySelector("polygon").getAttribute("points")).toBe(
            "0,0 300,0 0,216",
        );
        // Orange and white are under 3 to 1, so the other color would not read: black on both halves.
        expect(
            sevens.map((g) => [
                g.getAttribute("data-text"),
                g.getAttribute("fill"),
            ]),
        ).toEqual([
            ["7", "#000000"],
            ["7", "#000000"],
        ]);
        // About 72% of the flag's height.
        expect(Number(sevens[0].getAttribute("data-cap"))).toBe(156);
        expect(top.getAttribute("data-text")).toBe("ACD");
        expect(top.getAttribute("fill")).toBe("#000000");
        // The 7 hugs the hoist; both texts start just right of it, at the same x, about a third of the way across.
        expect(origin(sevens[0]).x).toBe(9);
        expect(origin(top).x).toBe(95);
        expect(origin(top).y).toBeLessThanOrEqual(66);
        expect(bottom.getAttribute("data-text")).toBe("B");
        expect(bottom.getAttribute("fill")).toBe("#000000");
        // The B starts at the same x unless the diagonal is closer there: it keeps clear of the split.
        expect(origin(bottom).x).toBeGreaterThanOrEqual(origin(top).x);
    });

    it("starts the texts just right of the large character, left aligned at the same x whatever their placement", () => {
        const centered = {
            ...acd,
            format: "horizontal",
            topAlign: "center",
            bottomAlign: "center",
        };
        const at = (large) =>
            texts(parse(guidonSvg({ ...centered, large }))).map(
                (g) => origin(g).x,
            );

        expect(at("7")).toEqual([95, 95]);
        // Measured from the glyphs: a 1 pulls the texts closer, a 2 is wider, two characters further still.
        expect(at("1")[0]).toBeLessThan(95);
        expect(at("2")[0]).toBeGreaterThan(95);
        expect(at("12")[0]).toBeGreaterThan(at("2")[0]);
    });

    it("quarters the swallowtail like a checkerboard, the first color top left and bottom right", () => {
        const svg = parse(
            guidonSvg({
                ...design,
                format: "quartered",
                topAlign: "center",
                bottomAlign: "center",
            }),
        );
        const [tl, tr, bl, br, edge] = [
            ...svg.querySelectorAll(":scope > polygon"),
        ];
        const [top, bottom] = texts(svg);

        // Split across the middle and upright at x = 123, where the four quarters have the same area; the right
        // quarters carry the tails.
        expect(QUARTER_SPLIT).toBe(123);
        expect(
            [tl, tr, bl, br].map((p) => [
                p.getAttribute("points"),
                p.getAttribute("fill"),
            ]),
        ).toEqual([
            ["0,0 123,0 123,108 0,108", "#1f2a44"],
            ["123,0 300,0 192,108 123,108", "#ffffff"],
            ["0,108 123,108 123,216 0,216", "#ffffff"],
            ["123,108 192,108 300,216 123,216", "#1f2a44"],
        ]);
        expect(edge.getAttribute("points")).toBe(
            "0,0 300,0 192,108 300,216 0,216",
        );
        // The top text in the top left quarter, the bottom text in the bottom right one, both white on navy.
        expect([
            top.getAttribute("data-text"),
            top.getAttribute("fill"),
        ]).toEqual(["2/7", "#ffffff"]);
        expect([
            bottom.getAttribute("data-text"),
            bottom.getAttribute("fill"),
        ]).toEqual(["A", "#ffffff"]);
        expect(origin(top).x).toBeGreaterThanOrEqual(12);
        expect(origin(top).y).toBeLessThan(108);
        expect(origin(bottom).x).toBeGreaterThan(123);
        expect(origin(bottom).y).toBeGreaterThan(108);
    });

    it("reads each quarter text in black or white, whichever reads on the first color", () => {
        const texts2 = texts(
            parse(
                guidonSvg({
                    ...design,
                    format: "quartered",
                    primary: "#ffc72c",
                    secondary: "#1f2a44",
                }),
            ),
        );

        expect(texts2.map((g) => g.getAttribute("fill"))).toEqual([
            "#000000",
            "#000000",
        ]);
    });

    it("puts a large character across the left quarters, the texts in the right ones, all in the opposite colors", () => {
        const svg = parse(
            guidonSvg({ ...acd, primary: "#b22234", format: "quartered" }),
        );
        const clips = [...svg.querySelectorAll("clipPath polygon")].map((p) =>
            p.getAttribute("points"),
        );
        const sevens = texts(svg, true);
        const [top, bottom] = texts(svg);

        expect(clips).toEqual([
            "0,0 123,0 123,108 0,108",
            "0,108 123,108 123,216 0,216",
        ]);
        expect(
            sevens.map((g) => [
                g.getAttribute("fill"),
                g.getAttribute("data-cap"),
            ]),
        ).toEqual([
            ["#ffffff", "156"],
            ["#b22234", "156"],
        ]);
        // Centered on the left quarters: the 7 is about 80 wide, so it starts about 20 in.
        expect(origin(sevens[0]).x).toBeGreaterThan(15);
        expect(origin(sevens[0]).x).toBeLessThan(30);
        // Top right is white, so the top text is red; bottom right is red, so the bottom text is white.
        expect([
            top.getAttribute("data-text"),
            top.getAttribute("fill"),
        ]).toEqual(["ACD", "#b22234"]);
        expect([
            bottom.getAttribute("data-text"),
            bottom.getAttribute("fill"),
        ]).toEqual(["B", "#ffffff"]);
        expect(origin(top).x).toBe(135);
        expect(origin(bottom).x).toBe(135);
    });

    it("keeps black or white beside a large character when the other color would not read", () => {
        // Blue over purple: about 1.6 to 1, so every element stays white, large character or not.
        const blue = {
            ...design,
            primary: "#004cff",
            secondary: "#8000ff",
            topLeft: "A",
            bottomRight: "BC",
        };
        for (const format of ["diagonal", "horizontal", "quartered"]) {
            const plainFills = texts(parse(guidonSvg({ ...blue, format }))).map(
                (g) => g.getAttribute("fill"),
            );
            const svg = parse(guidonSvg({ ...blue, format, large: "A" }));

            expect(plainFills).toEqual(["#ffffff", "#ffffff"]);
            expect(texts(svg).map((g) => g.getAttribute("fill"))).toEqual([
                "#ffffff",
                "#ffffff",
            ]);
            expect(texts(svg, true).map((g) => g.getAttribute("fill"))).toEqual(
                ["#ffffff", "#ffffff"],
            );
        }
    });

    it("keeps the other color beside a large character when it reads: the ACD/C guidon, white on red and red on white", () => {
        const acdc = {
            ...acd,
            primary: "#b22234",
            topLeft: "ACD",
            bottomRight: "C",
        };
        const svg = parse(guidonSvg(acdc));

        expect(texts(svg).map((g) => g.getAttribute("fill"))).toEqual([
            "#ffffff",
            "#b22234",
        ]);
        expect(texts(svg, true).map((g) => g.getAttribute("fill"))).toEqual([
            "#ffffff",
            "#b22234",
        ]);
    });

    it("draws a design saved as a band, a dropped format, as top and bottom", () => {
        const band = { ...acd, format: "band" };

        expect(isDesign(band)).toBe(true);
        expect(guidonSvg(band)).toBe(
            guidonSvg({ ...acd, format: "horizontal" }),
        );
        expect(formatOf(band)).toBe("horizontal");
    });

    it("refuses an unknown format, placement or large character", () => {
        expect(guidonSvg({ ...design, format: "vertical" })).toBeNull();
        expect(guidonSvg({ ...design, topAlign: "right" })).toBeNull();
        expect(guidonSvg({ ...design, large: "777" })).toBeNull();
        expect(guidonSvg({ ...design, large: "<" })).toBeNull();
        expect(isDesign(acd)).toBe(true);
    });

    it("warns about the pair only in a format that shows both colors", () => {
        const pair = { ...design, primary: "#777777", secondary: "#787878" };

        expect(pairTooClose(pair)).toBe(true);
        expect(pairTooClose({ ...pair, format: "horizontal" })).toBe(true);
        expect(pairTooClose({ ...pair, format: "quartered" })).toBe(true);
        expect(pairTooClose({ ...pair, format: "solid" })).toBe(false);
    });

    it("starts a new guidon diagonal, left aligned, with no large character", () => {
        expect(
            defaultDesign({ type: "company", callsign: "A/2-7" }),
        ).toMatchObject({
            format: "diagonal",
            topAlign: "left",
            bottomAlign: "left",
            large: "",
        });
        expect(cleanLarge("7!x9")).toBe("7x");
    });
});

describe("text fit", () => {
    const PAD = EDGE_PAD - 0.01;
    const EDGES = [
        [0, 0, 300, 0],
        [300, 0, 192, 108],
        [192, 108, 300, 216],
        [300, 216, 0, 216],
        [0, 216, 0, 0],
    ];
    const segmentDistance = ([x, y], [ax, ay, bx, by]) => {
        const t = Math.max(
            0,
            Math.min(
                1,
                ((x - ax) * (bx - ax) + (y - ay) * (by - ay)) /
                    ((bx - ax) ** 2 + (by - ay) ** 2),
            ),
        );

        return Math.hypot(x - (ax + t * (bx - ax)), y - (ay + t * (by - ay)));
    };
    const insideFlag = ([x, y]) =>
        x >= 0 && y >= 0 && y <= HEIGHT && x <= flagRight(y);
    // Signed distance from the diagonal split, positive above it (the top left half).
    const aboveDiagonal = ([x, y]) =>
        ((diagonalX(y) - x) * HEIGHT) / Math.hypot(WIDTH, HEIGHT);

    // The ink box of a drawn text, in flag units.
    function box(g) {
        const [, x, y, s] = /translate\(([\d.-]+) ([\d.-]+)\) scale\(([\d.]+)\)/
            .exec(g.getAttribute("transform"))
            .map(Number);
        const ink = inkBox(g.getAttribute("data-text"));

        return {
            x0: x + ink.minX * s,
            x1: x + ink.maxX * s,
            y0: y + ink.minY * s,
            y1: y + ink.maxY * s,
        };
    }
    const corners = (b) => [
        [b.x0, b.y0],
        [b.x1, b.y0],
        [b.x0, b.y1],
        [b.x1, b.y1],
    ];

    function expectInFlag(b, what) {
        for (const p of corners(b)) {
            expect(insideFlag(p), `${what} corner ${p} inside the flag`).toBe(
                true,
            );
            for (const e of EDGES)
                expect(
                    segmentDistance(p, e),
                    `${what} corner ${p} clear of the edge ${e}`,
                ).toBeGreaterThanOrEqual(PAD);
        }
        // The cut's point must not poke into the box.
        const inBox =
            192 > b.x0 - PAD &&
            192 < b.x1 + PAD &&
            108 > b.y0 - PAD &&
            108 < b.y1 + PAD;
        expect(inBox, `${what} clear of the cut's point`).toBe(false);
    }

    // Which side of the split each text must stay on, per format and with or without a large character.
    function expectOnSide(b, format, which, large, what) {
        const top = which === 0;
        if (format === "diagonal") {
            for (const p of corners(b))
                expect(
                    (top ? 1 : -1) * aboveDiagonal(p),
                    `${what} on its side of the diagonal`,
                ).toBeGreaterThanOrEqual(PAD);
        } else if (top)
            expect(b.y1, `${what} above the middle`).toBeLessThanOrEqual(
                108 - PAD,
            );
        else
            expect(b.y0, `${what} below the middle`).toBeGreaterThanOrEqual(
                108 + PAD,
            );
        if (format === "quartered") {
            if (top && !large)
                expect(b.x1, `${what} left of the split`).toBeLessThanOrEqual(
                    QUARTER_SPLIT - PAD,
                );
            else
                expect(
                    b.x0,
                    `${what} right of the split`,
                ).toBeGreaterThanOrEqual(QUARTER_SPLIT + PAD);
        }
    }

    const WORST = [
        ["WWWWWW", "MMMMMM"],
        ["MMMMMM", "WWWWWW"],
        ["DEV", "COM"],
        ["2/7", "A"],
        ["888888", "888888"],
        ["A", "A"],
        ["A", "7"],
        ["DEVCOM", "W"],
    ];
    const LARGES = ["", "2", "W", "MW", "88"];

    it("keeps every glyph inside the flag, clear of the cut and on its side of the split, in every format", () => {
        for (const format of ["diagonal", "horizontal", "quartered", "solid"]) {
            for (const large of LARGES) {
                for (const align of ["left", "center"]) {
                    for (const [topLeft, bottomRight] of WORST) {
                        const d = {
                            ...design,
                            format,
                            large,
                            topLeft,
                            bottomRight,
                            topAlign: align,
                            bottomAlign: align,
                        };
                        const svg = parse(guidonSvg(d));
                        const what = JSON.stringify(d);
                        const drawn = texts(svg);

                        expect(
                            drawn.map((g) => g.getAttribute("data-text")),
                            what,
                        ).toEqual([topLeft, bottomRight]);
                        drawn.forEach((g, i) => {
                            const b = box(g);
                            expectInFlag(b, `${what} text ${i}`);
                            if (format !== "solid")
                                expectOnSide(
                                    b,
                                    format,
                                    i,
                                    large,
                                    `${what} text ${i}`,
                                );
                        });
                        for (const g of texts(svg, true)) {
                            const b = box(g);
                            expectInFlag(b, `${what} large`);
                            if (format === "quartered")
                                expect(
                                    b.x1,
                                    `${what} large left of the split`,
                                ).toBeLessThanOrEqual(QUARTER_SPLIT - PAD);
                        }
                    }
                }
            }
        }
    });

    it("keeps DEV and COM beside a large 2 on a top and bottom guidon inside the flag and out of the cut", () => {
        const d = {
            format: "horizontal",
            primary: "#1f2a44",
            secondary: "#ffffff",
            large: "2",
            topLeft: "DEV",
            bottomRight: "COM",
            topAlign: "left",
            bottomAlign: "left",
        };
        const [top, bottom] = texts(parse(guidonSvg(d))).map(box);

        expectInFlag(top, "DEV");
        expectInFlag(bottom, "COM");
        // Clear of the cut's upper and lower edges at the boxes' corners nearest it.
        expect(top.x1).toBeLessThanOrEqual(flagRight(top.y1) - EDGE_PAD);
        expect(bottom.x1).toBeLessThanOrEqual(flagRight(bottom.y0) - EDGE_PAD);
        // Three letters each, so they keep a common left edge.
        expect(Math.abs(top.x0 - bottom.x0)).toBeLessThan(2);
    });

    it("centers a short top and bottom text on one line beside a large character: the quartered A over 7", () => {
        const d = {
            format: "quartered",
            primary: "#800000",
            secondary: "#ffc72c",
            large: "2",
            topLeft: "A",
            bottomRight: "7",
            topAlign: "left",
            bottomAlign: "left",
        };
        const [top, bottom] = texts(parse(guidonSvg(d))).map(box);

        expect((top.x0 + top.x1) / 2).toBeCloseTo(
            (bottom.x0 + bottom.x1) / 2,
            1,
        );
        // Each one's capitals centered on its quarter's height.
        expect((top.y0 + top.y1) / 2).toBeCloseTo(54, 0);
        expect((bottom.y0 + bottom.y1) / 2).toBeCloseTo(162, 0);
    });

    it("finds the free span from the outline, narrowest where the cut comes in", () => {
        expect(usableSpan(REGIONS.topHalf, 20, 40)).toEqual([
            EDGE_PAD,
            flagRight(46) - EDGE_PAD,
        ]);
        expect(usableSpan(REGIONS.topHalf, 90, 104)).toBeNull();
        expect(usableSpan(REGIONS.flag, 100, 116)[1]).toBe(192 - EDGE_PAD);
        expect(
            fitText("   ", { y: 54, cap: 64, region: REGIONS.topHalf }),
        ).toBeNull();
    });

    it("shrinks a text that does not fit at its size rather than let it run over", () => {
        const fit = fitText("WWWWWW", {
            x: 150,
            y: 54,
            cap: 64,
            region: REGIONS.topHalf,
        });

        expect(fit.scale * CAP_HEIGHT).toBeLessThan(64);
        expect(fit.box.x1).toBeLessThanOrEqual(
            usableSpan(REGIONS.topHalf, fit.box.y0, fit.box.y1)[1],
        );
    });
});
