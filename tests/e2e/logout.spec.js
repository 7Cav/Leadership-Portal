import { test, expect } from '@playwright/test';

// Story 1.5 AC #2 in a real browser. Inertia keeps every visited page object in history state and
// repaints it on popstate without a request, so only a browser can prove that "back" after sign-out
// does not bring the last authenticated screen back. The feature tests pin the two server halves
// (encryptHistory on authenticated pages, clearHistory on logout); this checks they add up.

test('sign out then go Back shows the login page', async ({ page }) => {
    await page.goto('/__e2e/login/Hale.S?to=/reports');
    await expect(page.getByRole('heading', { name: /^Week Ending \d{2}[A-Z]{3}\d{2} \(/ })).toBeVisible();

    await page.getByTestId('user-menu').click();
    await page.getByTestId('sign-out').click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    // The history entry before /login is the roll-up page. Its stored copy was encrypted under a
    // key that logout discarded, so the client cannot repaint it and has to ask the server, which
    // bounces the signed-out browser to login.
    await page.goBack();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /^Week Ending \d{2}[A-Z]{3}\d{2} \(/ })).toHaveCount(0);
    await expect(page.getByRole('treegrid')).toHaveCount(0);
});
