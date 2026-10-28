import { expect, test } from '@playwright/test';
import { randomBytes } from 'node:crypto';
import { freshSection, steamId } from './fresh-section.js';

// Story 7.2. Every test links identities, so each one gets a section of its own (GameIdentitySectionSeeder): eight
// troopers linked to no game, under a leader of its own. A retry or a --repeat-each copy starts as clean as the first
// run, and tests on other workers never see each other's links, so they run in parallel like the rest of the suite.
let leader;
test.beforeEach(() => {
    leader = freshSection();
});

// Save the form and wait for the save to land: the page then moves the form on from the game it saved, to the trooper's
// next missing game, or closes the panel when none is left. Typing before then goes into a form that the save's own
// refill is about to clear.
async function saveAndMoveOn(page) {
    const game = await page.getByTestId('identity-game').inputValue();
    await page.getByTestId('identity-save').click();
    await expect.poll(() => page.evaluate(() => document.querySelector('[data-testid="identity-game"]')?.value ?? null)).not.toBe(game);
}

test('a section leader links their troopers, hits a collision, then edits and removes an identity', async ({ page }) => {
    await page.goto(`/__e2e/login/${leader}?to=/home`);
    await page.getByTestId('nav-Game identities').click();

    await expect(page).toHaveURL(/\/game-identities$/);
    await expect(page.getByRole('heading', { name: 'Game identities' })).toBeVisible();
    await expect(page.getByTestId('coverage-linked')).toHaveText('0');
    await expect(page.getByTestId('coverage-slots')).toHaveText('32');
    await expect(page.getByTestId('view-needs')).toHaveAttribute('aria-pressed', 'true');

    const rows = page.locator('[data-testid^="row-"]');
    await expect(rows).toHaveCount(8);
    const firstName = (await page.getByTestId(/^row-/).first().locator('p').first().textContent()).trim();
    const steam = steamId();

    // Link all four games for the first trooper: the queue then drops them.
    await rows.first().getByRole('button', { name: 'Add identity' }).click();
    await expect(page.getByTestId('identity-game')).toHaveValue('hll');
    await page.getByTestId('identity-name').fill('QueueOne');
    await page.getByTestId('identity-id').fill(steam);
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('1');
    await expect(page.getByTestId('identity-game')).toHaveValue('arma');
    await page.getByTestId('identity-name').fill('QueueOne_Arma');
    await page.getByTestId('identity-id').fill(steamId());
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('2');
    await expect(page.getByTestId('identity-game')).toHaveValue('wardogs');
    await page.getByTestId('identity-name').fill('QueueOne_Wardogs');
    await page.getByTestId('identity-id').fill(steamId());
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('3');
    // Still queued: Hell Let Loose: Vietnam is its own game, and the same ID there as on WW2 is no collision (#431).
    await expect(page.getByTestId('identity-game')).toHaveValue('hll-vietnam');
    await page.getByTestId('identity-name').fill('QueueOne_Vietnam');
    await page.getByTestId('identity-id').fill(steam);
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('4');
    await expect(rows).toHaveCount(7);

    // The same Steam ID on another trooper is a readable error on the field, not a 500.
    await rows.first().getByRole('button', { name: 'Add identity' }).click();
    await page.getByTestId('identity-name').fill('Copycat');
    await page.getByTestId('identity-id').fill(steam);
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('error-platform_id')).toContainText(`Already linked to ${firstName} on Hell Let Loose (WW2)`);
    await expect(page.getByTestId('coverage-linked')).toHaveText('4');
    await page.getByTestId('identity-cancel').click();

    // Everyone view: rename in place (same id), then remove.
    await page.getByTestId('view-everyone').click();
    await expect(rows).toHaveCount(8);
    await page.locator('[data-testid^="open-"]').filter({ hasText: 'Edit identities' }).click();
    await page.getByTestId('edit-hll').click();
    await page.getByTestId('identity-name').fill('QueueOne_Renamed');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('identity-hll')).toContainText('QueueOne_Renamed');
    await expect(page.getByTestId('coverage-linked')).toHaveText('4');

    await page.getByTestId('remove-arma').click();
    await page.getByTestId('confirm-remove-arma').click();
    await expect(page.getByTestId('identity-arma')).toHaveCount(0);
    await expect(page.getByTestId('coverage-linked')).toHaveText('3');
});

// #429: a linked identity wraps in full inside its box at every width, never cut off or scrolling the page.
for (const [label, width, height] of [['phone', 375, 800], ['tablet', 820, 1000], ['desktop', 1440, 900]]) {
    test(`a long linked identity wraps in full with Edit and Remove reachable on ${label}`, async ({ page }) => {
        const longId = randomBytes(16).toString('hex'); // 32 characters, unbroken, and no other test's
        const longName = `=7Cav=PFC.${'Austin'.repeat(7)}`; // unbroken, longer than the panel is wide
        await page.setViewportSize({ width, height });
        await page.goto(`/__e2e/login/${leader}?to=/game-identities`);

        await page.locator('[data-testid^="open-"]').last().click();
        await page.getByTestId('identity-game').selectOption('hll');
        await page.getByTestId('identity-name').fill(longName);
        // 32 hex characters is the shape of an Epic ID, the longest ID this game takes.
        await page.getByTestId('identity-kind').selectOption('Epic ID');
        await page.getByTestId('identity-id').fill(longId);
        await page.getByTestId('identity-save').click();

        const box = page.getByTestId('identity-hll');
        await expect(box).toContainText(longId);
        await expect(box).toContainText(longName);
        await expect(box.getByTestId('edit-hll')).toBeVisible();
        await expect(box.getByTestId('remove-hll')).toBeVisible();

        const fits = await page.evaluate(() => {
            const rect = document.querySelector('[data-testid="identity-hll"]').getBoundingClientRect();
            const inside = [...document.querySelectorAll('[data-testid="identity-hll"] *')].every(
                // Right edge inside the box, and no text clipped by an ellipsis (scrollWidth > clientWidth).
                (el) => el.getBoundingClientRect().right <= rect.right + 0.5 && el.scrollWidth <= el.clientWidth + 1,
            );
            const doc = document.documentElement;

            return { inside, pageScroll: doc.scrollWidth > doc.clientWidth };
        });
        expect(fits).toEqual({ inside: true, pageScroll: false });
    });
}

// #427: the identity panel stays in view while you work down the list. The viewport heights are short
// on purpose, so the section's eight troopers overflow the screen the way a long real list does.
const rect = (page, selector) =>
    page.evaluate((s) => {
        const r = document.querySelector(s).getBoundingClientRect();

        return { top: r.top, bottom: r.bottom, left: r.left, right: r.right, vh: window.innerHeight };
    }, selector);

for (const [label, width, height] of [['phone', 375, 500], ['tablet', 820, 600], ['desktop', 1440, 520]]) {
    test(`picking a trooper down the list brings the panel into view, focuses its first field and returns to the row on ${label}`, async ({ page }) => {
        await page.setViewportSize({ width, height });
        await page.goto(`/__e2e/login/${leader}?to=/game-identities`);
        await page.getByTestId('view-everyone').click();

        const lastOpen = page.locator('[data-testid^="open-"]').last();
        const openId = await lastOpen.getAttribute('data-testid');
        const rowId = openId.replace('open-', 'row-');
        await lastOpen.scrollIntoViewIfNeeded();
        await lastOpen.click();

        const header = await rect(page, 'header');
        await expect(page.getByTestId('identity-game')).toBeFocused();
        await expect.poll(async () => (await rect(page, '[data-testid="panel"]')).top).toBeGreaterThanOrEqual(header.bottom - 0.5);

        // In view, under the header, and not over the list.
        const panel = await rect(page, '[data-testid="panel"]');
        expect(panel.top).toBeLessThan(panel.vh);
        if (width >= 1024) {
            const queue = await rect(page, '[data-testid="queue"]');
            expect(panel.bottom).toBeLessThanOrEqual(panel.vh + 0.5);
            expect(panel.left).toBeGreaterThanOrEqual(queue.right - 0.5);

            // The page scrolls down the list and the sticky panel stays put, just under the header.
            await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
            expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
            const sticky = await rect(page, '[data-testid="panel"]');
            expect(sticky.top).toBeGreaterThanOrEqual(header.bottom - 0.5);
            expect(sticky.top).toBeLessThan(header.bottom + 16);
            expect(sticky.bottom).toBeLessThanOrEqual(sticky.vh + 0.5);
        }

        await page.getByTestId('identity-cancel').click();
        await expect(page.getByTestId(openId)).toBeFocused();
        const row = await rect(page, `[data-testid="${rowId}"]`);
        const bar = await rect(page, 'header');
        expect(row.top).toBeGreaterThanOrEqual(bar.bottom - 0.5);
        expect(row.bottom).toBeLessThanOrEqual(row.vh + 0.5);
    });
}

test('saving the last missing game of a trooper lands on the row that took their place, in view, on phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 500 });
    await page.goto(`/__e2e/login/${leader}?to=/game-identities`);

    const rows = page.locator('[data-testid^="row-"]');
    await expect(rows).toHaveCount(8);
    const lastOpen = page.locator('[data-testid^="open-"]').last();
    await lastOpen.scrollIntoViewIfNeeded();
    await lastOpen.click();

    // Link every game, each save landing before the next is typed; the last one drops the trooper from the "needs a
    // link" queue and closes the panel.
    const games = await page.getByTestId('identity-game').locator('option').count();
    for (let i = 0; i < games; i++) {
        await page.getByTestId('identity-name').fill(`Land_${i}`);
        await page.getByTestId('identity-id').fill(steamId());
        await saveAndMoveOn(page);
    }

    await expect(page.getByTestId('identity-form')).toHaveCount(0);
    await expect(rows).toHaveCount(7);
    const focused = page.locator('[data-testid^="open-"]:focus');
    await expect(focused).toHaveCount(1);
    const row = await focused.evaluate((el) => {
        const r = el.closest('li').getBoundingClientRect();

        return { top: r.top, bottom: r.bottom, vh: innerHeight, bar: document.querySelector('header').getBoundingClientRect().bottom };
    });
    expect(row.top).toBeGreaterThanOrEqual(row.bar - 0.5);
    expect(row.bottom).toBeLessThanOrEqual(row.vh + 0.5);
});

test('a panel taller than the screen scrolls inside itself and keeps Save reachable on desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 460 });
    await page.goto(`/__e2e/login/${leader}?to=/game-identities`);
    await page.getByTestId('view-everyone').click();
    await page.locator('[data-testid^="open-"]').nth(1).click();

    for (const game of ['hll', 'arma', 'wardogs']) {
        await page.getByTestId('identity-game').selectOption(game);
        await page.getByTestId('identity-name').fill(`Tall_${game}`);
        await page.getByTestId('identity-id').fill(steamId());
        await saveAndMoveOn(page);
        await expect(page.getByTestId(`identity-${game}`)).toBeVisible();
    }

    const box = await page.getByTestId('panel').evaluate((el) => ({
        scrolls: el.scrollHeight > el.clientHeight,
        overflowY: getComputedStyle(el).overflowY,
        bottom: el.getBoundingClientRect().bottom,
        vh: innerHeight,
    }));
    expect(box.scrolls).toBe(true);
    expect(box.overflowY).toBe('auto');
    expect(box.bottom).toBeLessThanOrEqual(box.vh + 0.5);

    // The first identity is reachable at the top, and Save at the bottom once the panel scrolls.
    await expect(page.getByTestId('edit-hll')).toBeInViewport();
    await page.getByTestId('identity-save').scrollIntoViewIfNeeded();
    await expect(page.getByTestId('identity-save')).toBeInViewport();
    await expect(page.getByTestId('identity-cancel')).toBeInViewport();
});
