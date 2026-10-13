import { test, expect } from '@playwright/test';

// Story 3.11 happy path, against the shared E2E seed (Database\Seeders\ReportRollupSeeder): an Admin (Pike.S) edits Alpha
// Company's rollcall template, and a section leader under it (Vasquez.R, 2/1/A/1-7) sees the new standard in the forum
// post on the next load, with no change of their own. Other specs read this post too, so the edit keeps the seed's header
// and only adds a line.

const MARKER = `Standard changed by e2e ${Date.now()}`;

test('an Admin edits the company template and the section\'s rollcall post follows on its next load', async ({ browser }) => {
    const admin = await (await browser.newContext()).newPage();
    await admin.goto('/__e2e/login/Pike.S?to=/settings/templates');
    await expect(admin.getByTestId('active-version')).toBeVisible();

    const body = admin.getByTestId('template-body');
    await body.fill(`${await body.inputValue()}\n${MARKER} {{unit}}`);
    await admin.getByTestId('template-reason').fill('The standard changed once');
    await admin.getByTestId('save-template').click();
    await expect(admin.getByTestId('active-version')).toContainText(/Version [2-9]/);

    const leader = await (await browser.newContext()).newPage();
    await leader.goto('/__e2e/login/Vasquez.R?to=/rollcall');
    await leader.getByRole('button', { name: /^Step 3, Review and post/ }).click();

    await expect(leader.getByTestId('template-version')).toContainText(/Template version [2-9]/);
    await expect(leader.getByTestId('forum-bbcode')).toContainText(`${MARKER} 2/1/A/1-7`);

    // The leader has no way to edit it from here.
    await expect(leader.getByTestId('template-body')).toHaveCount(0);
});

test('an Admin is refused a template with a typo, so it never reaches a section', async ({ page }) => {
    await page.goto('/__e2e/login/Pike.S?to=/settings/templates');
    await page.getByTestId('template-body').fill('{{#troopers}}{{nme}}{{/troopers}}');
    await page.getByTestId('template-reason').fill('A typo');
    await page.getByTestId('save-template').click();

    await expect(page.getByTestId('error-body')).toContainText('Unknown name "nme"');
});

test('a section leader cannot open the template settings to edit', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/settings/templates');
    await expect(page.getByTestId('template-body')).toHaveCount(0);
    await expect(page.getByTestId('template-readonly')).toBeVisible();
});
