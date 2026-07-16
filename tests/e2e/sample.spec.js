import { test, expect } from '@playwright/test';

// Playwright smoke test (Story 1.3): proves the browser E2E seam works — the app
// boots, serves the sample page, and Vue renders it client-side end-to-end. The
// heading text lives only in the Vue component (not the server HTML payload), so
// its visibility proves hydration ran without fatal console errors.
test('sample page boots and renders end-to-end', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { name: 'Skeleton is alive.' })).toBeVisible();
    await expect(page.getByText('Laravel 13')).toBeVisible();
});
