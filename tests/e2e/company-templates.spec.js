import { test, expect } from '@playwright/test';

// #375 against the shared E2E seed (Database\Seeders\ReportRollupSeeder): Alpha Company's commander (Hale.S) adds a
// template of the company's own, and a section leader under it (Vasquez.R, 2/1/A/1-7) picks it for their rollcall post.
// Other specs read Vasquez.R's post, so the pick goes back to the company default at the end.

const NAME = `E2E layout ${Date.now()}`;

test('a company commander adds a template and a section leader picks it for the rollcall post', async ({ browser }) => {
    const co = await (await browser.newContext()).newPage();
    await co.goto('/__e2e/login/Hale.S?to=/settings/templates');
    await expect(co.getByTestId('template-list')).toBeVisible();
    await co.getByTestId('new-template').click();
    await co.getByTestId('create-name').fill(NAME);
    await co.getByTestId('create-reason').fill('A layout for e2e');
    await co.getByTestId('create-confirm').click();
    await expect(co.getByTestId('open-template-name')).toHaveText(NAME);

    // Its own text, so the post shows which template it came from.
    const body = co.getByTestId('template-body');
    await body.fill(`[B]${NAME} {{unit}}[/B]`);
    await co.getByTestId('template-reason').fill('Mark it');
    await co.getByTestId('save-template').click();
    await expect(co.getByTestId('active-version')).toHaveText('Version 2');

    const leader = await (await browser.newContext()).newPage();
    await leader.goto('/__e2e/login/Vasquez.R?to=/rollcall');
    await leader.getByRole('button', { name: /^Step 3, Review and post/ }).click();
    await leader.getByTestId('template-pick-select').selectOption({ label: NAME });
    await expect(leader.getByTestId('forum-bbcode')).toContainText(`${NAME} 2/1/A/1-7`);

    // The pick persists: the next load renders from it too.
    await leader.reload();
    await leader.getByRole('button', { name: /^Step 3, Review and post/ }).click();
    await expect(leader.getByTestId('template-pick-select')).toHaveValue(/\d+/);
    await expect(leader.getByTestId('forum-bbcode')).toContainText(`${NAME} 2/1/A/1-7`);

    await leader.getByTestId('template-pick-select').selectOption({ label: 'Company default' });
    await expect(leader.getByTestId('forum-bbcode')).not.toContainText(NAME);
});

test('a section leader reads the company templates but gets no controls to change them', async ({ page }) => {
    await page.goto('/__e2e/login/Vasquez.R?to=/settings/templates');
    await expect(page.getByTestId('template-list')).toBeVisible();
    await expect(page.getByTestId('new-template')).toHaveCount(0);
    await expect(page.getByTestId('template-body')).toHaveCount(0);
});
