import { test, expect } from '@playwright/test';
import { freshSection } from './fresh-section.js';

// Stories 3.7–3.10 happy path, against the shared E2E seed (Database\Seeders\ReportRollupSeeder):
// Vasquez.R leads 2/1/A/1-7, whose troopers carry four weeks of rollcalls and practice marks.

const saved = (page) =>
    page.waitForResponse((response) => response.request().method() === 'POST' && /\/rollcall\/\d+$/.test(response.url()), { timeout: 15_000 });

test('a section leader lands on their section\'s rollcall', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/rollcall');

    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Roll call, week of');
    await expect(page.getByText('2/1/A/1-7 weekly cycle')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Signatures' })).toBeVisible();

    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();
    await expect(page).toHaveURL(/step=2/);
    await expect(page.getByTestId('prefill-banner')).toBeVisible();
});

// It writes marks, so it runs on a section of its own (GameIdentitySectionSeeder): the seeded section is shared with
// the tests beside it, which mark it posted or read it in parallel, and a retry would start from the marks the first
// attempt left there.
test('a section leader signs and marks practice, and finds both saved after a reload', async ({ page }) => {
    const leader = freshSection();
    await page.goto(`/__e2e/login/${leader}?to=/rollcall`);
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await page.getByRole('button', { name: /^Step 1, Signatures/ }).click();

    const id = await page.locator('[data-trooper]').first().getAttribute('data-trooper');
    const card = () => page.locator(`[data-trooper="${id}"]`);

    const signed = saved(page);
    await card().getByRole('button', { name: /^Will not attend/ }).click();
    expect((await signed).status()).toBe(200);
    await expect(page.getByTestId('save-state')).toContainText('Saved');

    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();
    const marked = saved(page);
    await card().getByRole('button', { name: /^Excused/ }).click();
    expect((await marked).status()).toBe(200);

    await page.reload();
    await expect(card().getByRole('button', { name: /^Excused/ })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: /^Step 1, Signatures/ }).click();
    await expect(card().getByRole('button', { name: /^Will not attend/ })).toHaveAttribute('aria-pressed', 'true');
});

test('a section leader copies the forum post and marks the rollcall posted', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto('/__e2e/login/Vasquez.R?to=/rollcall');
    await page.getByRole('button', { name: /^Step 3, Review and post/ }).click();

    await expect(page.getByTestId('forum-bbcode')).toContainText('Rollcall, week of');
    await page.getByTestId('copy-bbcode').click();
    await expect(page.getByTestId('copy-bbcode')).toHaveText('Copy again');
    const copied = await page.evaluate(() => navigator.clipboard.readText());
    expect(copied).toMatch(/^\[b\]2\/1\/A\/1-7 Rollcall, week of \d{2}[A-Z][a-z]{2}\d{2}\[\/b\]/);
    expect(copied).toContain('[table]');
    expect(copied).toContain('[tr]');

    await page.getByTestId('mark-posted').click();
    await expect(page.getByTestId('posted-state')).toContainText('Roll call posted');
    await page.reload();
    await expect(page.getByTestId('posted-state')).toContainText('by Vasquez.R');
});

test('trooper cards keep the name visible when the sidebar and summary leave them narrow', async ({ page }) => {
    // At 1024px the sidebar and the week summary leave each card about 390px wide, less than the
    // buttons' fixed width, so the card has to stack instead of squeezing the name to nothing.
    await page.setViewportSize({ width: 1024, height: 800 });
    await page.goto('/__e2e/login/Vasquez.R?to=/rollcall');
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);

    const checkCards = async () => {
        const cards = page.locator('[data-trooper]');
        await expect(cards.first()).toBeVisible();
        for (const card of await cards.all()) {
            const box = await card.boundingBox();
            const name = await card.locator('[data-card-face] p').first().boundingBox();
            expect(name.width).toBeGreaterThan(140);
            for (const button of await card.getByRole('button').all()) {
                const edge = await button.boundingBox();
                expect(edge.x + edge.width).toBeLessThanOrEqual(box.x + box.width);
            }
        }
    };

    // A week with no work left opens on the review step, which has no cards, so start from the signatures step.
    await page.getByRole('button', { name: /^Step 1,/ }).click();
    await expect(page).toHaveURL(/step=1/);
    await checkCards();
    await page.getByRole('button', { name: /^Step 2, Section practice/ }).click();
    await expect(page).toHaveURL(/step=2/);
    await checkCards();
});
