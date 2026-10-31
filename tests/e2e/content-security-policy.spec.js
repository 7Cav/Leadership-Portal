import { test, expect } from '@playwright/test';

// #485: the content security policy (SetSecurityHeaders, config/csp.php) lets every page load what it needs. A source
// the policy leaves out would show as a `securitypolicyviolation` event and a "Refused to ..." console error, so this
// walks the main pages, signed in as the seeded leaders who see them (ReportRollupSeeder, ReportScreenSeeder), and
// fails on either. The listener is added before any page script runs, and the binding outlives full page loads.
test('the main pages load with no content security policy violation', async ({ page }) => {
    const violations = [];
    await page.exposeFunction('__reportCspViolation', (violation) => violations.push(violation));
    await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            window.__reportCspViolation(`${event.violatedDirective} blocked ${event.blockedURI || 'inline'} (${event.sourceFile}:${event.lineNumber})`);
        });
    });
    page.on('console', (message) => {
        if (message.type() === 'error' && /Content Security Policy/i.test(message.text())) violations.push(message.text());
    });

    // The policy is enforced, not report-only, in the browser suite.
    const login = await page.goto('/login');
    expect(login.headers()['content-security-policy']).toContain("script-src 'self' 'nonce-");
    await expect(page.getByRole('link', { name: 'Continue with 7Cav' })).toBeVisible();

    // Home, the roster, and the guidon generator with both downloads (an SVG data URL drawn into an image and a canvas).
    await page.goto('/__e2e/login/Hale.S?to=/home');
    await expect(page.getByTestId('kpi-tiles')).toBeVisible();

    await page.goto('/roster');
    await page.getByTestId('roster-guidon').click();
    const generator = page.getByTestId('guidon-generator');
    const preview = generator.getByTestId('guidon');
    await expect(preview).toBeVisible();
    await expect.poll(() => preview.evaluate((image) => image.complete && image.naturalWidth > 0)).toBe(true);
    for (const button of ['guidon-download-svg', 'guidon-download-png']) {
        const download = page.waitForEvent('download');
        await generator.getByTestId(button).click();
        await download;
    }
    await page.keyboard.press('Escape');

    // The rollcall.
    await page.goto('/__e2e/login/Vasquez.R?to=/rollcall');
    await expect(page).toHaveURL(/\/rollcall\/\d+$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Roll call, week ending');

    // A weekly report, then the docs: docs home and a guide, whose body is server-rendered markdown.
    await page.goto('/__e2e/login/Mercer.J?to=/reports');
    await expect(page).toHaveURL(/\/reports\/\d+\/\d{4}-\d{2}-\d{2}$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Week Ending');

    await page.goto('/docs');
    await expect(page.getByTestId('docs-home')).toBeVisible();
    await page.goto('/docs/weekly-rollcall/run-rollcall');
    await expect(page.getByTestId('guide-body')).toContainText('Why this matters.');

    expect(violations).toEqual([]);
});
