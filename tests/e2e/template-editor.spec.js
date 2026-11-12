import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// #704 in a real browser: the template editor's visual mode, against the shared E2E seed's Admin (Pike.S). The unit tests
// read the visual surface in happy-dom; this proves the same in Chromium, where the browser's own editing runs. Nothing
// here saves, so the seeded templates other specs read stay as they are.

const cases = JSON.parse(readFileSync(new URL('../Fixtures/bbcode-templates.json', import.meta.url), 'utf8'));
const TEMPLATES = [...cases.valid, ...cases.invalid].map((c) => c.template);

async function open(page) {
    await page.goto('/__e2e/login/Pike.S?to=/settings/templates');
    await expect(page.getByTestId('template-editor')).toBeVisible();
}

async function write(page, template) {
    await page.getByTestId('template-mode-source').click();
    await page.getByTestId('template-body').fill(template);
    await page.getByTestId('template-mode-visual').click();
}

// Selects text in the visual surface, as a mouse would: in the first text that holds `text`, from `from` characters into
// it, `length` characters long (0 for a cursor).
async function selectText(page, text, { from = 0, length = text.length } = {}) {
    await page.getByTestId('template-visual').evaluate(
        (root, [wanted, skip, count]) => {
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                const at = node.data.indexOf(wanted);
                if (at === -1) continue;
                root.focus();
                const range = document.createRange();
                range.setStart(node, at + skip);
                range.setEnd(node, at + skip + count);
                document.getSelection().removeAllRanges();
                document.getSelection().addRange(range);

                return;
            }
            throw new Error(`No text "${wanted}" in the editor`);
        },
        [text, from, length],
    );
}

// The cursor at the very end of the visual surface (Ctrl+End is not the end of the text on every platform).
async function caretAtEnd(page) {
    await page.getByTestId('template-visual').evaluate((root) => {
        root.focus();
        const range = document.createRange();
        range.selectNodeContents(root);
        range.collapse(false);
        document.getSelection().removeAllRanges();
        document.getSelection().addRange(range);
    });
}

test('every shared template goes Source, Visual, typed into and back, and Source again unchanged', async ({ page }) => {
    test.setTimeout(120_000);
    await open(page);

    for (const template of TEMPLATES) {
        await write(page, template);
        await caretAtEnd(page);
        await page.keyboard.type('x');
        await page.keyboard.press('Backspace');
        await page.getByTestId('template-mode-source').click();

        await expect(page.getByTestId('template-body'), template).toHaveValue(template);
    }
});

test('a leader makes a word bold with the keyboard, undoes it, and deletes a code whole', async ({ page }) => {
    await open(page);
    await write(page, 'Roll call {{unit}}');
    await expect(page.getByTestId('template-visual').getByRole('img', { name: 'Callsign, template code {{unit}}' })).toBeVisible();

    await selectText(page, 'Roll');
    await page.keyboard.press('ControlOrMeta+b');
    await expect(page.getByTestId('template-visual').locator('strong')).toHaveText('Roll');

    await page.keyboard.press('ControlOrMeta+z');
    await expect(page.getByTestId('template-visual').locator('strong')).toHaveCount(0);

    await caretAtEnd(page);
    await page.keyboard.press('Backspace');
    await page.getByTestId('template-mode-source').click();
    await expect(page.getByTestId('template-body')).toHaveValue('Roll call ');
});

test('a leader types into a table cell and adds a column without writing BBCode', async ({ page }) => {
    await open(page);
    await write(page, '[TABLE][TR][TH]#[/TH][TH]Name[/TH][/TR]\n{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{display_name}}[/TD][/TR]\n{{/troopers}}[/TABLE]');
    const surface = page.getByTestId('template-visual');
    await expect(surface.getByText('Repeats for each trooper')).toBeVisible();

    await selectText(page, 'Name', { length: 0 });
    await page.keyboard.type('Trooper ');
    await page.getByTestId('tool-column-add').click();
    await page.keyboard.type('Signed');

    await page.getByTestId('template-mode-source').click();
    await expect(page.getByTestId('template-body')).toHaveValue(
        '[TABLE][TR][TH]#[/TH][TH]Trooper Name[/TH][TH]Signed[/TH][/TR]\n{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{display_name}}[/TD][TD][/TD][/TR]\n{{/troopers}}[/TABLE]',
    );
    await expect(page.getByTestId('template-problem')).toHaveCount(0);
});

test('the code picker offers a trooper\'s codes only inside the loop, and puts one in as a chip', async ({ page }) => {
    await open(page);
    await write(page, 'Top {{#troopers}}Row {{/troopers}}');

    await selectText(page, 'Top', { length: 0 });
    await page.getByTestId('tool-code').click();
    await expect(page.getByTestId('code-pick-troopers')).toBeVisible();
    await expect(page.getByTestId('code-pick-name')).toHaveCount(0);
    await page.keyboard.press('Escape');

    await selectText(page, 'Row ', { from: 4, length: 0 });
    await page.getByTestId('tool-code').click();
    await page.getByTestId('code-picker-search').fill('trooper name');
    await page.getByTestId('code-pick-name').click();
    await expect(page.getByTestId('template-visual').getByRole('img', { name: 'Trooper name, template code {{name}}' })).toBeVisible();

    await page.getByTestId('template-mode-source').click();
    await expect(page.getByTestId('template-body')).toHaveValue('Top {{#troopers}}Row {{name}}{{/troopers}}');
});

test('deleting one end of a condition takes the condition away whole and keeps what it showed', async ({ page }) => {
    await open(page);
    await write(page, 'a {{#rank_icon}}x{{/rank_icon}}');
    const surface = page.getByTestId('template-visual');

    await caretAtEnd(page);
    await page.keyboard.press('Backspace');
    await expect(surface.getByRole('img', { name: /If Rank insignia/ })).toHaveCount(0);
    await expect(surface).toHaveText('a x');

    await page.getByTestId('template-mode-source').click();
    await expect(page.getByTestId('template-body')).toHaveValue('a x');
});

test('typing over a selection that runs from text into the trooper loop is refused, and the template stays whole', async ({ page }) => {
    const template = 'Intro text\n[TABLE][TR][TH]Name[/TH][/TR]\n{{#troopers}}[TR][TD]{{name}} here[/TD][/TR]\n{{/troopers}}[/TABLE]';
    await open(page);
    await write(page, template);

    await page.getByTestId('template-visual').evaluate((root) => {
        root.focus();
        const cell = [...root.querySelectorAll('td')].find((td) => td.textContent.includes('here'));
        const range = document.createRange();
        range.setStart(root.firstChild, 2);
        range.setEnd(cell.lastChild, 3);
        document.getSelection().removeAllRanges();
        document.getSelection().addRange(range);
    });
    await page.keyboard.type('x');
    await expect(page.getByTestId('template-refusal')).toContainText('into another cell');
    await page.keyboard.press('Delete');

    await page.getByTestId('template-mode-source').click();
    await expect(page.getByTestId('template-body')).toHaveValue(template);
});

test('a leader formats text with the keyboard alone, through the toolbar and its menu', async ({ page }) => {
    await open(page);
    await write(page, 'Roll call');
    await selectText(page, 'Roll');

    // From the surface back to the toolbar's size button, by Shift+Tab alone.
    for (let presses = 0; presses < 20; presses++) {
        if ((await page.evaluate(() => document.activeElement?.dataset.testid)) === 'tool-size') break;
        await page.keyboard.press('Shift+Tab');
    }
    await expect(page.getByTestId('tool-size')).toBeFocused();
    await page.keyboard.press('Enter');
    for (let presses = 0; presses < 10; presses++) {
        if ((await page.evaluate(() => document.activeElement?.dataset.testid)) === 'tool-size-5') break;
        await page.keyboard.press('Tab');
    }
    await expect(page.getByTestId('tool-size-5')).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page.getByTestId('template-visual').locator('[data-open="[SIZE=5]"]')).toHaveText('Roll');
    await page.getByTestId('template-mode-source').click();
    await expect(page.getByTestId('template-body')).toHaveValue('[SIZE=5]Roll[/SIZE] call');
});

test('in the Source tab the toolbar edits through the browser, so Ctrl+Z takes it back', async ({ page }) => {
    await open(page);
    await page.getByTestId('template-mode-source').click();
    const body = page.getByTestId('template-body');
    await body.fill('aa bb aa\nsecond line');
    await body.evaluate((field) => {
        field.focus();
        field.setSelectionRange(6, 8);
        field.dispatchEvent(new Event('select'));
    });

    await page.getByTestId('tool-bold').click();
    await expect(body).toHaveValue('aa bb [B]aa[/B]\nsecond line');
    await expect(body).toBeFocused();

    await page.keyboard.press('ControlOrMeta+z');
    await expect(body).toHaveValue('aa bb aa\nsecond line');
});

test('a line cut and pasted in the visual mode keeps its codes', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await open(page);
    await write(page, 'First {{unit}} line\nSecond line');

    await page.getByTestId('template-visual').evaluate((root) => {
        root.focus();
        const range = document.createRange();
        range.setStart(root.firstChild, 0);
        range.setEnd(root.lastChild, root.lastChild.data.indexOf('Second'));
        document.getSelection().removeAllRanges();
        document.getSelection().addRange(range);
    });
    await page.keyboard.press('ControlOrMeta+x');
    await caretAtEnd(page);
    await page.keyboard.press('Enter');
    await page.keyboard.press('ControlOrMeta+v');

    await expect(page.getByTestId('template-visual').getByRole('img', { name: 'Callsign, template code {{unit}}' })).toBeVisible();
    await page.getByTestId('template-mode-source').click();
    await expect(page.getByTestId('template-body')).toHaveValue('Second line\nFirst {{unit}} line\n');
});
