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
