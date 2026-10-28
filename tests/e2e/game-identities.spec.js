import { expect, test } from '@playwright/test';

// Every test here links identities on Vasquez.R's one seeded section, and the first counts them,
// so they share state and run in order on one worker.
test.describe.configure({ mode: 'serial' });

// Story 7.2 happy path. Vasquez.R commands one seeded section of eight troopers
// (ReportRollupSeeder), none of them linked to a game yet.

test('a section leader links their troopers, hits a collision, then edits and removes an identity', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/home');
    await page.getByTestId('nav-Game identities').click();

    await expect(page).toHaveURL(/\/game-identities$/);
    await expect(page.getByRole('heading', { name: 'Game identities' })).toBeVisible();
    await expect(page.getByTestId('coverage-linked')).toHaveText('0');
    await expect(page.getByTestId('coverage-slots')).toHaveText('32');
    await expect(page.getByTestId('view-needs')).toHaveAttribute('aria-pressed', 'true');

    const rows = page.locator('[data-testid^="row-"]');
    await expect(rows).toHaveCount(8);
    const firstName = (await page.getByTestId(/^row-/).first().locator('p').first().textContent()).trim();

    // Link all four games for the first trooper: the queue then drops them.
    await rows.first().getByRole('button', { name: 'Add identity' }).click();
    await expect(page.getByTestId('identity-game')).toHaveValue('hll');
    await page.getByTestId('identity-name').fill('QueueOne');
    await page.getByTestId('identity-id').fill('76561198000000001');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('1');
    await expect(page.getByTestId('identity-game')).toHaveValue('arma');
    await page.getByTestId('identity-name').fill('QueueOne_Arma');
    await page.getByTestId('identity-id').fill('arma-0001');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('2');
    await expect(page.getByTestId('identity-game')).toHaveValue('wardogs');
    await page.getByTestId('identity-name').fill('QueueOne_Wardogs');
    await page.getByTestId('identity-id').fill('76561198000000002');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('3');
    // Still queued: Hell Let Loose: Vietnam is its own game, and the same ID there as on WW2 is no collision (#431).
    await expect(page.getByTestId('identity-game')).toHaveValue('hll-vietnam');
    await page.getByTestId('identity-name').fill('QueueOne_Vietnam');
    await page.getByTestId('identity-id').fill('76561198000000001');
    await page.getByTestId('identity-save').click();
    await expect(page.getByTestId('coverage-linked')).toHaveText('4');
    await expect(rows).toHaveCount(7);

    // The same Steam ID on another trooper is a readable error on the field, not a 500.
    await rows.first().getByRole('button', { name: 'Add identity' }).click();
    await page.getByTestId('identity-name').fill('Copycat');
    await page.getByTestId('identity-id').fill('76561198000000001');
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
        const longId = 'e'.repeat(32);
        const longName = `=7Cav=PFC.${'Austin'.repeat(7)}`; // unbroken, longer than the panel is wide
        await page.setViewportSize({ width, height });
        await page.goto('/__e2e/login/Vasquez.R?to=/game-identities');

        await page.locator('[data-testid^="open-"]').last().click();
        await page.getByTestId('identity-game').selectOption('hll');
        await page.getByTestId('identity-name').fill(longName);
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
// on purpose, so the eight seeded troopers overflow the screen the way a long real list does.
const rect = (page, selector) =>
    page.evaluate((s) => {
        const r = document.querySelector(s).getBoundingClientRect();

        return { top: r.top, bottom: r.bottom, left: r.left, right: r.right, vh: window.innerHeight };
    }, selector);

for (const [label, width, height] of [['phone', 375, 500], ['tablet', 820, 600], ['desktop', 1440, 520]]) {
    test(`picking a trooper down the list brings the panel into view, focuses its first field and returns to the row on ${label}`, async ({ page }) => {
        await page.setViewportSize({ width, height });
        await page.goto('/__e2e/login/Vasquez.R?to=/game-identities');
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
    await page.goto('/__e2e/login/Vasquez.R?to=/game-identities');

    const lastOpen = page.locator('[data-testid^="open-"]').last();
    const before = await page.locator('[data-testid^="row-"]').count();
    await lastOpen.scrollIntoViewIfNeeded();
    await lastOpen.click();

    // Link every game still missing; the last save drops the trooper from the "needs a link" queue.
    const stamp = Date.now();
    for (let i = 0; i < 3 && (await page.getByTestId('identity-form').count()); i++) {
        await page.getByTestId('identity-name').fill(`Land_${stamp}_${i}`);
        await page.getByTestId('identity-id').fill(`land-${stamp}-${i}`);
        await page.getByTestId('identity-save').click();
        await page.waitForTimeout(400);
    }

    await expect(page.locator('[data-testid^="row-"]')).toHaveCount(before - 1);
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
    await page.goto('/__e2e/login/Vasquez.R?to=/game-identities');
    await page.getByTestId('view-everyone').click();
    await page.locator('[data-testid^="open-"]').nth(1).click();

    const stamp = Date.now();
    for (const [game, id] of [['hll', `76561${stamp}`], ['arma', `arma-${stamp}`], ['wardogs', `76562${stamp}`]]) {
        await page.getByTestId('identity-game').selectOption(game);
        await page.getByTestId('identity-name').fill(`Tall_${game}`);
        await page.getByTestId('identity-id').fill(id);
        await page.getByTestId('identity-save').click();
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
