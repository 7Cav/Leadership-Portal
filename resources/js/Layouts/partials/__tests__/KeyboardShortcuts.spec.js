import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import KeyboardShortcuts from '../KeyboardShortcuts.vue';
import { SHORTCUT_GROUPS } from '@/lib/shortcuts';

const mockPage = reactive({ url: '/home', props: {} });

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => mockPage,
}));

const dialog = () => document.body.querySelector('[data-testid="shortcuts-dialog"]');

async function press(key, target = document.body, init = {}) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init });
    target.dispatchEvent(event);
    await flushPromises();

    return event;
}

let wrapper;
function mountList(url = '/home') {
    mockPage.url = url;
    wrapper = mount(KeyboardShortcuts, { attachTo: document.body });

    return wrapper;
}

afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
});

describe('KeyboardShortcuts', () => {
    it('opens an accessible dialog on ?', async () => {
        mountList();
        expect(dialog()).toBeNull();

        const event = await press('?', document.body, { shiftKey: true });

        expect(event.defaultPrevented).toBe(true);
        expect(dialog().getAttribute('role')).toBe('dialog');
        const title = document.getElementById(dialog().getAttribute('aria-labelledby'));
        expect(title.textContent).toBe('Keyboard shortcuts');
        expect(dialog().getAttribute('aria-describedby')).toBeTruthy();
    });

    it('lists every shortcut in the registry, grouped by page', async () => {
        mountList();
        await press('?');

        for (const group of SHORTCUT_GROUPS) {
            const section = dialog().querySelector(`[data-testid="shortcuts-group-${group.id}"]`);
            expect(section.querySelector('h3').textContent).toContain(group.title);
            expect([...section.querySelectorAll('li')].map((li) => li.dataset.testid)).toEqual(group.shortcuts.map((s) => `shortcut-${s.id}`));
        }
    });

    it('shows keys as keycaps with spoken names, and number ranges by their ends', async () => {
        mountList();
        await press('?');

        const next = dialog().querySelector('[data-testid="shortcut-rollcall.next"]');
        expect([...next.querySelectorAll('kbd [aria-hidden="true"]')].map((k) => k.textContent)).toEqual(['J', '↓']);
        expect(next.querySelector('kbd .sr-only').textContent).toBe('J');
        expect(next.textContent).toContain('or');
        const practice = dialog().querySelector('[data-testid="shortcut-rollcall.practice"]');
        expect([...practice.querySelectorAll('kbd [aria-hidden="true"]')].map((k) => k.textContent)).toEqual(['1', '4']);
        expect(practice.textContent).toContain('to');
        expect(dialog().querySelector('[data-testid="shortcut-roster.cells"]').textContent).not.toContain('or');
    });

    it('puts the page the viewer is on right after the global shortcuts', async () => {
        mountList('/rollcall/12?step=2');
        await press('?');

        const ids = [...dialog().querySelectorAll('section')].map((s) => s.dataset.testid);
        expect(ids.slice(0, 2)).toEqual(['shortcuts-group-global', 'shortcuts-group-rollcall']);
        expect(dialog().querySelector('[data-testid="shortcuts-group-rollcall"] h3').textContent).toContain('This page');
        expect(dialog().querySelector('[data-testid="shortcuts-group-roster"] h3').textContent).not.toContain('This page');
    });

    it('stays shut while the viewer types in a field', async () => {
        mountList();
        const input = document.createElement('input');
        document.body.appendChild(input);

        const event = await press('?', input);

        expect(event.defaultPrevented).toBe(false);
        expect(dialog()).toBeNull();
    });

    it('stays shut while another dialog is open', async () => {
        mountList();
        const other = document.createElement('div');
        other.setAttribute('role', 'dialog');
        document.body.appendChild(other);

        await press('?');

        expect(dialog()).toBeNull();
    });

    it('ignores ? with Ctrl, and keys other than ?', async () => {
        mountList();

        await press('?', document.body, { ctrlKey: true });
        await press('/');

        expect(dialog()).toBeNull();
    });

    it('closes on Escape', async () => {
        mountList();
        await press('?');
        expect(dialog()).not.toBeNull();

        await press('Escape', dialog());

        expect(dialog()).toBeNull();
    });

    it('stops listening once unmounted', async () => {
        mountList().unmount();
        wrapper = null;

        await press('?');

        expect(dialog()).toBeNull();
    });
});
