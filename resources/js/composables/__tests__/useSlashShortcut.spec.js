import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';
import { handleSlash, isSlashShortcut, slashClaimed, useSlashClaim } from '../useSlashShortcut';

// A keydown as the browser would fire it on `target`, captured so the test can hand it to the helpers.
function pressOn(target, init = {}) {
    let fired;
    const capture = (event) => (fired = event);
    target.addEventListener('keydown', capture);
    target.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true, ...init }));
    target.removeEventListener('keydown', capture);

    return fired;
}

function append(html) {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    document.body.append(holder);

    return holder.firstElementChild;
}

const Claimer = (action) => defineComponent({ setup: () => (useSlashClaim(action), () => h('div')) });

describe('the / shortcut', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('fires on a plain / anywhere on the page', () => {
        expect(isSlashShortcut(pressOn(document.body))).toBe(true);
        expect(isSlashShortcut(pressOn(append('<button>Go</button>')))).toBe(true);
    });

    it('stays out of the way while typing in a field', () => {
        for (const html of ['<input>', '<textarea></textarea>', '<select><option>a</option></select>', '<div contenteditable="true"><span>x</span></div>']) {
            const field = append(html);
            expect(isSlashShortcut(pressOn(field.querySelector('span') ?? field)), html).toBe(false);
        }
    });

    it('stays out of the way while a dialog is open, even with focus outside it', () => {
        append('<div role="dialog"><button>Inside</button></div>');
        const outside = append('<button>Outside</button>');

        expect(isSlashShortcut(pressOn(document.querySelector('[role="dialog"] button')))).toBe(false);
        expect(isSlashShortcut(pressOn(outside))).toBe(false);
    });

    it('ignores other keys and / with a modifier', () => {
        expect(isSlashShortcut(pressOn(document.body, { key: 'k' }))).toBe(false);
        expect(isSlashShortcut(pressOn(document.body, { ctrlKey: true }))).toBe(false);
        expect(isSlashShortcut(pressOn(document.body, { metaKey: true }))).toBe(false);
        expect(isSlashShortcut(pressOn(document.body, { altKey: true }))).toBe(false);
    });

    it('runs the fallback and keeps the / out of the page when nothing claims it', () => {
        const fallback = vi.fn();
        const event = pressOn(document.body);

        handleSlash(event, fallback);

        expect(fallback).toHaveBeenCalledOnce();
        expect(event.defaultPrevented).toBe(true);
        expect(slashClaimed.value).toBe(false);
    });

    it('hands / to a mounted claim instead, the newest first, and gives it back on unmount', () => {
        const fallback = vi.fn();
        const first = vi.fn();
        const second = vi.fn();
        const a = mount(Claimer(first));
        const b = mount(Claimer(second));

        expect(slashClaimed.value).toBe(true);
        handleSlash(pressOn(document.body), fallback);
        expect(second).toHaveBeenCalledOnce();

        b.unmount();
        handleSlash(pressOn(document.body), fallback);
        expect(first).toHaveBeenCalledOnce();

        a.unmount();
        handleSlash(pressOn(document.body), fallback);
        expect(fallback).toHaveBeenCalledOnce();
        expect(slashClaimed.value).toBe(false);
        expect(second).toHaveBeenCalledOnce();
    });

    it('does nothing for a / typed into a field, claim or not', () => {
        const fallback = vi.fn();
        const event = pressOn(append('<input>'));

        handleSlash(event, fallback);

        expect(fallback).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });
});
