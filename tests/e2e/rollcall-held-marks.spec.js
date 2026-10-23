import { test, expect } from '@playwright/test';

// "Mark all present" and "Mark the rest missed" hold their saves for the Undo window. Leaving the step or the page
// sends them at once instead of dropping them with the hold's timer. Against RollcallHeldMarksSeeder: each leader's
// section has three troopers nobody has marked this week, and every test has its own section, since a saved practice
// mark cannot be cleared again.

const practiceStep = (page) => page.getByRole('button', { name: /^Step 2, Section practice/ });
const isSave = (request) => request.method() === 'POST' && /\/rollcall\/\d+$/.test(request.url());
// The next save's answer. A hold lasts 6 s, so a save that arrives within 3 s was sent by leaving, not by the timer.
const nextSave = (page, timeout = 15_000) => page.waitForResponse((response) => isSave(response.request()), { timeout });

async function openPractice(page, leader) {
    await page.goto(`/__e2e/login/${leader}?to=/rollcall`);
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await practiceStep(page).click();
    await expect(page).toHaveURL(/step=2/);
    await expect(practiceStep(page)).toHaveAttribute('aria-label', /3 left to mark/);
}

async function expectAllMarkedAfterReload(page) {
    await page.reload();
    await expect(practiceStep(page)).toHaveAttribute('aria-label', /All marked/);
}

test('moving to the next step saves the held marks at once and closes their Undo', async ({ page }) => {
    await openPractice(page, 'Novak.B');

    await page.getByTestId('mark-rest-missed').click();
    await expect(page.getByText('Marked 3 as missed')).toBeVisible();
    const save = nextSave(page, 3_000);
    await page.getByRole('button', { name: /^Step 3,/ }).click();

    const response = await save;
    expect(response.status()).toBe(200);
    expect(response.request().postDataJSON().marks).toHaveLength(3);
    await expect(page.getByText('Marked 3 as missed')).toBeHidden();
    await expectAllMarkedAfterReload(page);
});

test('held marks survive the next step and then browser Back', async ({ page }) => {
    const saves = [];
    page.on('request', (request) => isSave(request) && saves.push(request));
    await openPractice(page, 'Quinn.A');

    await page.getByTestId('mark-all-present').click();
    const save = nextSave(page);
    await page.getByRole('button', { name: /^Step 3,/ }).click();
    await expect(page).toHaveURL(/step=3/);
    await page.goBack();
    await expect(page).toHaveURL(/step=2/);

    expect((await save).status()).toBe(200);
    // Nothing is left to send once the hold would have run out.
    await page.waitForTimeout(7_000);
    expect(saves).toHaveLength(1);
    await expectAllMarkedAfterReload(page);
});

test('leaving for another week saves the held marks once the leader agrees to leave', async ({ page }) => {
    await openPractice(page, 'Ortiz.C');

    await page.getByTestId('mark-all-present').click();
    const asked = page.waitForEvent('dialog');
    const save = nextSave(page);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('previous-week').click();

    expect((await asked).message()).toBe('Some rollcall changes are not saved yet. Leave anyway?');
    expect((await save).status()).toBe(200);
    await expect(page).toHaveURL(/week=/);
    await page.goto(new URL(page.url()).pathname + '?step=2');
    await expect(practiceStep(page)).toHaveAttribute('aria-label', /All marked/);
});

test('closing the tab right after "Mark all present" still saves the marks', async ({ context }) => {
    const page = await context.newPage();
    await openPractice(page, 'Patel.D');
    const url = new URL(page.url()).pathname;

    await page.getByTestId('mark-all-present').click();
    page.on('dialog', (dialog) => dialog.accept());
    await page.close({ runBeforeUnload: true });
    await expect.poll(() => page.isClosed()).toBe(true);

    const again = await context.newPage();
    await expect(async () => {
        await again.goto(`${url}?step=2`);
        await expect(practiceStep(again)).toHaveAttribute('aria-label', /All marked/, { timeout: 1_000 });
    }).toPass({ timeout: 15_000 });
});
