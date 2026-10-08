import { test, expect } from '@playwright/test';

// Story 1.15 browser checks. Signing in needs the real 7cav.us OAuth round-trip, so these stay on
// the guest side: the `/` doorway, and the pre-paint theme script in app.blade.php, which only a
// real browser can prove (it runs before any Vue code).

test('`/` sends a guest to the login screen, which renders end-to-end', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Continue with 7Cav' })).toBeVisible();
});

test('a saved dark theme is applied before the page paints', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('portal-theme', 'dark'));
    await page.goto('/login');

    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
});

test('with no saved choice, the theme follows the operating system', async ({ browser }) => {
    const context = await browser.newContext({ colorScheme: 'dark' });
    const page = await context.newPage();
    await page.goto('/login');

    await expect(page.locator('html')).toHaveClass(/\bdark\b/);

    await page.emulateMedia({ colorScheme: 'light' });
    await page.reload();
    await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
    await context.close();
});
