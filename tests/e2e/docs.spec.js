import { test, expect } from '@playwright/test';

// #410: a section leader opens the docs, opens a guide from docs home and moves on to the next one. The e2e server
// reads the fixed test guides in tests/Fixtures/docs (env.js), with the docs switched on. Mercer.J leads a section
// (ReportScreenSeeder), so the leader guides are there and the Admin one is not.
test('a section leader opens a guide from docs home and moves to the next one', async ({ page }) => {
    await page.goto('/__e2e/login/Mercer.J?to=/docs');

    await expect(page.getByRole('heading', { level: 1 })).toHaveText('What do you need to do?');
    await expect(page.getByTestId('docs-role')).toHaveText('Leadership billet');
    await expect(page.getByTestId('docs-home')).not.toContainText('Games');

    await page.getByTestId('docs-group-everyone').getByRole('link', { name: 'Sign in with your 7cav.us account' }).click();
    await expect(page).toHaveURL(/\/docs\/basics\/sign-in$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Sign in with your 7cav.us account');
    await expect(page.getByTestId('docs-tree').getByRole('link', { name: 'Sign in with your 7cav.us account' })).toHaveAttribute('aria-current', 'page');

    await page.getByTestId('next-guide').click();
    await expect(page).toHaveURL(/\/docs\/weekly-rollcall\/run-rollcall$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText("Run this week's rollcall");
    await expect(page.getByTestId('guide-body')).toContainText('Why this matters.');
});

test('a guide for another audience is not found', async ({ page }) => {
    const response = await page.goto('/__e2e/login/Mercer.J?to=/docs/admin/games');

    expect(response.status()).toBe(404);
});

// #411: the "?" in the top bar opens the guide for the page the leader is on, and the guide offers the way back.
test('Help on the rollcall opens the rollcall guide, which leads back to the rollcall', async ({ page }) => {
    await page.goto('/__e2e/login/Mercer.J?to=/rollcall');

    await page.getByRole('link', { name: 'Help for this page' }).click();
    await expect(page).toHaveURL(/\/docs\/weekly-rollcall\/run-rollcall\?from=%2Frollcall/);
    await expect(page.getByTestId('breadcrumb-current')).toHaveText("Run this week's rollcall");
    await expect(page.getByRole('navigation', { name: 'Primary' }).getByRole('link', { name: 'Docs' })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('came-from')).toContainText('You opened this from Rollcall.');

    await page.getByTestId('came-from').getByRole('link', { name: 'Back to Rollcall' }).click();
    await expect(page).toHaveURL(/\/rollcall/);
});

// #413: Ctrl K opens the search on any docs page; a typo still finds the guide, and Enter opens it.
test('a leader finds a guide with Ctrl K, despite a typo, and opens it with Enter', async ({ page }) => {
    await page.goto('/__e2e/login/Mercer.J?to=/docs');

    await page.keyboard.press('Control+k');
    await page.getByTestId('docs-search-input').fill('rolcall');
    await expect(page.getByTestId('docs-search-results')).toContainText("Run this week's rollcall");

    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/docs\/weekly-rollcall\/run-rollcall$/);
});
