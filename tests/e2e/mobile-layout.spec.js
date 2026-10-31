import { expect, test } from '@playwright/test';

// On a phone nothing may make the page wider than the screen: a wide table scrolls inside its own card, and the
// sticky top bar spans the full width. Seeded users: Pike.S (Admin), Hale.S (company A/1-7), Vasquez.R (section).
// MOBILE_SHOTS=<dir> also saves a full-page screenshot of each page, for before/after comparisons.

const pages = [
    ['home', 'Hale.S', '/home'],
    ['org-structure', 'Pike.S', '/settings/org-structure'],
    ['roster', 'Hale.S', '/roster'],
    ['roster-matrix', 'Hale.S', '/roster?view=matrix'],
    ['roster-strength', 'Hale.S', '/roster?view=strength'],
    ['rollcall', 'Vasquez.R', '/rollcall'],
    ['reports', 'Hale.S', '/reports'],
    ['staff-report', 'Hale.S', '/staff-report'],
    ['changelog', 'Pike.S', '/changelog'],
];

for (const width of [375, 768]) {
    test.describe(`at ${width}px`, () => {
        test.use({ viewport: { width, height: 812 } });

        for (const [name, user, path] of pages) {
            test(`${name} is no wider than the screen`, async ({ page }) => {
                await page.goto(`/__e2e/login/${user}?to=${encodeURIComponent(path)}`);
                await page.waitForLoadState('networkidle');
                await expect(page.locator('#main')).toBeVisible();

                const widths = await page.evaluate(() => {
                    const vw = document.documentElement.clientWidth;
                    // Report what sticks out, skipping anything inside a container that scrolls on its own.
                    const scrolls = (el) => {
                        for (let p = el.parentElement; p; p = p.parentElement) {
                            const ox = getComputedStyle(p).overflowX;
                            if (ox === 'auto' || ox === 'scroll' || ox === 'hidden') return true;
                        }
                        return false;
                    };
                    const offenders = [...document.querySelectorAll('#main *')]
                        .filter((el) => el.getBoundingClientRect().right > vw + 1 && !scrolls(el))
                        .slice(0, 5)
                        .map((el) => `${el.tagName.toLowerCase()}.${String(el.className).slice(0, 80)} -> ${Math.round(el.getBoundingClientRect().right)}`);
                    return { scroll: document.documentElement.scrollWidth, inner: vw, offenders };
                });

                if (process.env.MOBILE_SHOTS) {
                    await page.screenshot({ path: `${process.env.MOBILE_SHOTS}/${name}-${width}.png`, fullPage: true });
                }

                expect(widths.scroll, widths.offenders.join('\n')).toBeLessThanOrEqual(widths.inner);

                // The sticky bar spans the whole body (the root keeps a gutter for the scrollbar).
                const bar = await page.evaluate(() => {
                    const { right, bottom } = document.querySelector('header').getBoundingClientRect();
                    return { right, bottom, body: document.body.getBoundingClientRect().right };
                });
                expect(Math.round(bar.right)).toBeGreaterThanOrEqual(Math.round(bar.body) - 1);

                // The page title starts below the sticky bar, not under it.
                const title = await page.locator('#main h1').first().boundingBox();
                expect(title.y).toBeGreaterThanOrEqual(bar.bottom);
            });
        }
    });
}
