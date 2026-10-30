import { afterEach, describe, expect, it } from 'vitest';
import { PRACTICE, SIGNATURES } from '../rollcall';
import { NAV_GROUPS } from '@/Layouts/navigation';
import { displayKeys, isShortcut, isTypingTarget, keyLabel, keyName, overlayOpen, SHORTCUT_GROUPS, SHORTCUTS, shortcutIndex } from '../shortcuts';

const key = (k, extra = {}) => new KeyboardEvent('keydown', { key: k, ...extra });

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the shortcut registry', () => {
    it('gives every shortcut a unique id, at least one key and a label', () => {
        const all = SHORTCUT_GROUPS.flatMap((group) => group.shortcuts);

        expect(new Set(all.map((s) => s.id)).size).toBe(all.length);
        for (const shortcut of all) {
            expect(shortcut.keys.length).toBeGreaterThan(0);
            expect(shortcut.label).toBeTruthy();
        }
        expect(Object.keys(SHORTCUTS)).toHaveLength(all.length);
    });

    it('puts the global group first and ties each page group to a real nav item', () => {
        const hrefs = NAV_GROUPS.flatMap((group) => group.items.map((item) => item.href));

        expect(SHORTCUT_GROUPS[0]).toMatchObject({ id: 'global', href: null });
        for (const group of SHORTCUT_GROUPS.slice(1)) {
            expect(hrefs).toContain(group.href);
        }
    });

    it('lists ? for this list, / for the trooper search everywhere, and / for the roster search on the roster', () => {
        expect(SHORTCUTS.help.keys).toEqual(['?']);
        expect(SHORTCUT_GROUPS[0].shortcuts).toContain(SHORTCUTS.search);
        expect(SHORTCUTS.search).toMatchObject({ keys: ['/'], label: 'Find a trooper' });
        expect(SHORTCUTS['roster.search'].keys).toEqual(['/']);
        expect(SHORTCUTS['roster.search'].label).toContain('in place of Find a trooper');
    });

    it("follows the rollcall card's buttons: one number per signature and per practice mark", () => {
        expect(SHORTCUTS['rollcall.signature'].keys).toEqual(SIGNATURES.map((_, i) => String(i + 1)));
        expect(SHORTCUTS['rollcall.practice'].keys).toEqual(PRACTICE.map((_, i) => String(i + 1)));
        expect(SHORTCUTS['rollcall.practice'].label).toContain(PRACTICE.map((o) => o.label).join(', '));
    });
});

describe('isShortcut', () => {
    it('matches any of the keys', () => {
        expect(isShortcut(key('j'), 'rollcall.next')).toBe(true);
        expect(isShortcut(key('ArrowDown'), 'rollcall.next')).toBe(true);
        expect(isShortcut(key('k'), 'rollcall.next')).toBe(false);
    });

    it('allows Shift, which ? needs, but not Ctrl, Alt or Cmd', () => {
        expect(isShortcut(key('?', { shiftKey: true }), 'help')).toBe(true);
        expect(isShortcut(key('?', { ctrlKey: true }), 'help')).toBe(false);
        expect(isShortcut(key('?', { altKey: true }), 'help')).toBe(false);
        expect(isShortcut(key('?', { metaKey: true }), 'help')).toBe(false);
    });

    it('ignores a key typed while an input method is composing', () => {
        expect(isShortcut(key('?', { isComposing: true }), 'help')).toBe(false);
    });
});

describe('shortcutIndex', () => {
    it('gives the position of the number pressed, or -1', () => {
        expect(shortcutIndex(key('1'), 'rollcall.practice')).toBe(0);
        expect(shortcutIndex(key('4'), 'rollcall.practice')).toBe(3);
        expect(shortcutIndex(key('5'), 'rollcall.practice')).toBe(-1);
        // Five signatures since 2026-10-30: will attend, will not attend, make-up requested, excused, unsigned.
        expect(shortcutIndex(key('5'), 'rollcall.signature')).toBe(4);
        expect(shortcutIndex(key('6'), 'rollcall.signature')).toBe(-1);
        expect(shortcutIndex(key('2', { ctrlKey: true }), 'rollcall.signature')).toBe(-1);
    });
});

describe('key labels', () => {
    it('shows letters as keycaps and arrows as arrows', () => {
        expect(keyLabel('j')).toBe('J');
        expect(keyLabel('ArrowDown')).toBe('↓');
        expect(keyLabel('Escape')).toBe('Esc');
        expect(keyLabel(' ')).toBe('Space');
        expect(keyLabel('Home')).toBe('Home');
    });

    it('names keys for a screen reader', () => {
        expect(keyName('ArrowUp')).toBe('Up arrow');
        expect(keyName('?')).toBe('Question mark');
        expect(keyName('j')).toBe('J');
    });

    it('shows a number range by its ends', () => {
        expect(displayKeys(SHORTCUTS['rollcall.practice'])).toEqual(['1', '4']);
        expect(displayKeys(SHORTCUTS['rollcall.signature'])).toEqual(['1', '5']);
        expect(SHORTCUTS['rollcall.practice'].label).toBe('Set the practice mark (Attended, Not attended, Excused, Make-up complete)');
        expect(SHORTCUTS['rollcall.signature'].label).toBe('Set the signature (Will attend, Will not attend, Make-up requested, Excused, Unsigned)');
        expect(displayKeys(SHORTCUTS['rollcall.next'])).toEqual(['j', 'ArrowDown']);
    });
});

describe('isTypingTarget', () => {
    it('is true inside a field or editable text, false elsewhere', () => {
        document.body.innerHTML = `
            <input id="i"><textarea id="t"></textarea><select id="s"></select>
            <div contenteditable="true"><span id="e">x</span></div>
            <div contenteditable="false" id="ne"></div>
            <button id="b">b</button>`;
        const el = (id) => document.getElementById(id);

        expect(['i', 't', 's', 'e'].map((id) => isTypingTarget(el(id)))).toEqual([true, true, true, true]);
        expect(isTypingTarget(el('ne'))).toBe(false);
        expect(isTypingTarget(el('b'))).toBe(false);
        expect(isTypingTarget(document.body)).toBe(false);
        expect(isTypingTarget(null)).toBe(false);
    });
});

describe('overlayOpen', () => {
    it('sees an open dialog, alert dialog or menu', () => {
        expect(overlayOpen()).toBe(false);

        for (const role of ['dialog', 'alertdialog', 'menu']) {
            document.body.innerHTML = `<div role="${role}"></div>`;
            expect(overlayOpen()).toBe(true);
        }
    });
});
