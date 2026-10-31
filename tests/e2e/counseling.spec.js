import { expect, test } from '@playwright/test';

// #565: writing a Developmental Counseling Statement in the portal, and the official form filled in the browser.
// Against CounselingSeeder: Lindqvist.P leads 1/F/1-7 and Sato.K its 1st Section; their rifleman Novak.E already has a
// DCS for a missed section practice and an LOR for other conduct, written in the portal and shared 20 days ago. There
// is no forum here, so no PM can be sent: the share step asks the leader to allow PMs and shares nothing.

// The record page, its live form and the PDF filled in the browser (pdf-lib, a lazily loaded chunk) must load under the
// enforced content security policy: any violation fails the test, as in content-security-policy.spec.js.
test.beforeEach(async ({ page }, testInfo) => {
    const violations = [];
    testInfo.violations = violations;
    await page.exposeFunction('__reportCspViolation', (violation) => violations.push(violation));
    await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            window.__reportCspViolation(`${event.violatedDirective} blocked ${event.blockedURI || 'inline'}`);
        });
    });
    page.on('console', (message) => {
        if (message.type() === 'error' && /Content Security Policy/i.test(message.text())) violations.push(message.text());
    });
});

test.afterEach(async ({}, testInfo) => {
    expect(testInfo.violations).toEqual([]);
});

async function openNovak(page, leader) {
    await page.goto(`/__e2e/login/${leader}?to=/roster`);
    await page.locator('tr[data-member]').filter({ hasText: 'Novak.E' }).click();
    await page.getByTestId('trooper-sheet').getByTestId('sheet-open-trooper').click();
    await expect(page).toHaveURL(/\/troopers\/\d+$/);

    return page.getByTestId('counseling-panel');
}

test('a section leader writes a DCS draft step by step, saves it, and is asked to allow PMs before sharing', async ({ page }) => {
    const panel = await openNovak(page, 'Sato.K');

    await expect(panel.locator('[data-testid="counseling-record"][data-type="lor"]').first()).toBeVisible();

    await panel.getByTestId('counseling-start-open').click();
    const dialog = page.getByTestId('counseling-start-dialog');
    // A Section Leader may start a DCS only.
    await expect(dialog.getByTestId('counseling-start-type-lor')).toHaveCount(0);
    await dialog.getByTestId('counseling-start-kind').selectOption('missed_practice');
    await dialog.getByTestId('counseling-start-submit').click();

    await expect(page).toHaveURL(/\/counseling\/\d+$/);
    const recordUrl = page.url();

    // The type and kind are chosen, so the draft opens on 2 · What happened.
    await page.getByTestId('draft-infraction').fill('Missed section practice on 10, 17 and 24 Oct without an LOA.');
    await page.getByTestId('draft-regulation').fill('1/F/1-7 Platoon SOP, attendance.');
    await page.getByTestId('step-continue').click();
    await expect(page.getByTestId('draft-saved')).toBeVisible();

    // 3 · Meeting invite: a DCS may skip it.
    await page.getByTestId('step-continue').click();

    // 4 · The meeting.
    await page.getByTestId('draft-date').fill(new Date(Date.now() - 86_400_000).toISOString().slice(0, 10));
    await page.getByTestId('draft-discussion').fill('Shifts changed; we went through the LOA process together.');
    await page.getByTestId('draft-plan-authority').fill('Check in weekly before practice for four weeks.');
    await page.getByTestId('draft-meeting-confirmed').check();
    await page.getByTestId('draft-save').click();
    await expect(page.getByTestId('draft-saved')).toBeVisible();

    // The draft survives a reload, and the live form shows what was written.
    await page.goto(recordUrl);
    await expect(page.getByTestId('form-preview')).toContainText('Shifts changed; we went through the LOA process together.');

    // 6 · Review and share: nothing missing, but no PM can go without the forum's permission.
    // The draft reopens on its first step still to do; with everything filled in that is the share step itself.
    if (await page.getByTestId('step-edit-share').isVisible()) {
        await page.getByTestId('step-edit-share').click();
    }
    await expect(page.getByTestId('share-step')).toBeVisible();
    await expect(page.getByTestId('share-blocked').locator('li')).toHaveCount(0);
    await expect(page.getByTestId('pm-consent-link')).toBeVisible();
    await expect(page.getByTestId('share-submit')).toBeDisabled();

    // Still a draft: the trooper's milpac lists it first, to continue.
    await page.getByTestId('back-to-trooper').click();
    await expect(page.getByTestId('counseling-panel').getByTestId('counseling-record').first().getByTestId('counseling-continue')).toBeVisible();
});

test('a platoon leader downloads the seeded LOR as the official R-043, filled in the browser', async ({ page }) => {
    const panel = await openNovak(page, 'Lindqvist.P');
    const lor = panel.locator('[data-testid="counseling-record"][data-type="lor"]').first();

    const [download] = await Promise.all([page.waitForEvent('download'), lor.getByTestId('counseling-download').click()]);

    expect(download.suggestedFilename()).toMatch(/^LOR \d{4}-\d{2}-\d{2} Novak\.E\.pdf$/);
    const chunks = [];
    for await (const chunk of await download.createReadStream()) chunks.push(chunk);
    const bytes = Buffer.concat(chunks);
    expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
    expect(bytes.length).toBeGreaterThan(10_000);
});
