import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import cases from '../../../../../tests/Fixtures/bbcode-templates.json';
import TemplateEditor from '../TemplateEditor.vue';
import { readDom } from '@/lib/templateDom';
import { STARTER } from '@/lib/__tests__/templateStarter';

// The rollcall template editor (#704): a visual mode, a Source tab, a toolbar that works in both, and the syntax checked
// as the leader types.

const EVERY = [...cases.valid.map((c) => [c.name, c.template]), ...cases.invalid.map((c) => [c.name, c.template]), ['the starter', STARTER]];

let wrapper = null;

function editor(body, props = {}) {
    wrapper = mount(TemplateEditor, {
        props: {
            modelValue: body,
            maxLength: 20000,
            'onUpdate:modelValue': (value) => wrapper.setProps({ modelValue: value }),
            ...props,
        },
        attachTo: document.body,
    });

    return wrapper;
}

const surface = () => wrapper.get('[data-testid="template-visual"]').element;
const source = () => wrapper.get('[data-testid="template-body"]').element;
const changes = () => wrapper.emitted('update:modelValue') ?? [];

async function openMode(name) {
    await wrapper.get(`[data-testid="template-mode-${name}"]`).trigger('mousedown', { button: 0 });
    await flushPromises();
}

// Selects text in the visual surface by offsets in the template, as the leader would with the mouse.
async function selectVisual(start, end = start) {
    wrapper.findComponent({ name: 'TemplateVisualSurface' }).vm.select(start, end);
    document.dispatchEvent(new Event('selectionchange'));
    await nextTick();
}

beforeEach(() => {
    window.localStorage.removeItem('templates.editor-mode');
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('opening a template', () => {
    it('opens in the visual mode, with codes as chips and formatting as formatting', () => {
        editor('[B]{{unit}} ROLL CALL[/B]');

        expect(wrapper.get('[data-testid="template-mode-visual"]').attributes('data-state')).toBe('active');
        expect(surface().querySelector('strong')).not.toBeNull();
        expect(surface().querySelector('[data-raw="{{unit}}"]').textContent).toBe('Callsign');
        expect(surface().textContent).not.toContain('[B]');
    });

    it.each(EVERY)('changes nothing when nothing is edited: %s', async (_name, template) => {
        editor(template);
        await flushPromises();

        expect(changes()).toHaveLength(0);
        expect(readDom(surface()).source).toBe(template);
    });

    it.each(EVERY)('leaves the template unchanged going Visual, Source, Visual: %s', async (_name, template) => {
        editor(template);
        await openMode('source');
        expect(source().value).toBe(template);
        await openMode('visual');

        expect(changes()).toHaveLength(0);
        expect(readDom(surface()).source).toBe(template);
    });

    it('remembers the mode a leader last used', async () => {
        editor('x');
        await openMode('source');
        wrapper.unmount();

        editor('x');

        expect(wrapper.get('[data-testid="template-mode-source"]').attributes('data-state')).toBe('active');
    });
});

describe('editing in the visual mode', () => {
    it('reads what the leader types back into the template', async () => {
        editor('[B]Roll call[/B] {{unit}}');
        surface().querySelector('strong').firstChild.data = 'Roll call accountability';
        surface().dispatchEvent(new Event('input'));
        await nextTick();

        expect(changes().at(-1)).toEqual(['[B]Roll call accountability[/B] {{unit}}']);
    });

    it('drops a deleted code whole', async () => {
        editor('a {{unit}} b');
        surface().querySelector('[data-raw]').remove();
        surface().dispatchEvent(new Event('input'));
        await nextTick();

        expect(changes().at(-1)).toEqual(['a  b']);
    });

    it('puts a line end in for Enter, as text and not as markup', async () => {
        editor('ab');
        await selectVisual(1);
        surface().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        await nextTick();

        expect(changes().at(-1)).toEqual(['a\nb']);
    });

    it('pastes plain text only', async () => {
        editor('ab');
        await selectVisual(1);
        const paste = new Event('paste', { bubbles: true, cancelable: true });
        paste.clipboardData = { getData: (type) => (type === 'text/plain' ? 'XY' : '<b>XY</b>') };
        surface().dispatchEvent(paste);
        await nextTick();

        expect(changes().at(-1)).toEqual(['aXYb']);
    });

    it('takes a change back with Ctrl+Z, and puts it back with Ctrl+Shift+Z', async () => {
        editor('a bold b');
        await selectVisual(2, 6);
        await wrapper.get('[data-testid="tool-bold"]').trigger('click');
        await flushPromises();
        expect(wrapper.props('modelValue')).toBe('a [B]bold[/B] b');

        surface().dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true }));
        await flushPromises();
        expect(wrapper.props('modelValue')).toBe('a bold b');

        surface().dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, shiftKey: true, bubbles: true, cancelable: true }));
        await flushPromises();
        expect(wrapper.props('modelValue')).toBe('a [B]bold[/B] b');
    });
});

describe('the toolbar', () => {
    it('makes the selection bold in the visual mode, and draws it bold', async () => {
        editor('a bold b');
        await selectVisual(2, 6);
        await wrapper.get('[data-testid="tool-bold"]').trigger('click');
        await flushPromises();

        expect(wrapper.props('modelValue')).toBe('a [B]bold[/B] b');
        expect(surface().querySelector('strong').textContent).toBe('bold');
    });

    it('makes the selection bold in the Source tab too', async () => {
        editor('a bold b');
        await openMode('source');
        source().focus();
        source().setSelectionRange(2, 6);
        source().dispatchEvent(new Event('select'));
        await wrapper.get('[data-testid="tool-bold"]').trigger('click');
        await flushPromises();

        expect(wrapper.props('modelValue')).toBe('a [B]bold[/B] b');
    });

    it('takes Ctrl+B, Ctrl+I and Ctrl+U as the buttons', async () => {
        editor('word');
        await selectVisual(0, 4);
        surface().dispatchEvent(new KeyboardEvent('keydown', { key: 'i', ctrlKey: true, bubbles: true, cancelable: true }));
        await flushPromises();

        expect(wrapper.props('modelValue')).toBe('[I]word[/I]');
    });

    it('says why, and changes nothing, when formatting would cross into another cell', async () => {
        editor('[TABLE][TR][TD]a[/TD][TD]b[/TD][/TR][/TABLE]');
        await selectVisual(15, 25);
        await wrapper.get('[data-testid="tool-bold"]').trigger('click');
        await flushPromises();

        expect(changes()).toHaveLength(0);
        expect(wrapper.get('[data-testid="template-refusal"]').text()).toContain('cannot cross');
    });

    it('sets a size and a colour from their menus', async () => {
        editor('big');
        await selectVisual(0, 3);
        await wrapper.get('[data-testid="tool-size"]').trigger('click');
        await flushPromises();
        document.querySelector('[data-testid="tool-size-5"]').click();
        await flushPromises();
        expect(wrapper.props('modelValue')).toBe('[SIZE=5]big[/SIZE]');

        await selectVisual(8, 11);
        await wrapper.get('[data-testid="tool-color"]').trigger('click');
        await flushPromises();
        document.querySelector('[data-testid="tool-color-red"]').click();
        await flushPromises();
        expect(wrapper.props('modelValue')).toBe('[SIZE=5][COLOR=#c0392b]big[/COLOR][/SIZE]');
    });

    it('adds a column to the trooper table from a cell, in the header and the trooper row', async () => {
        const body = '[TABLE][TR][TH]#[/TH][TH]Name[/TH][/TR]{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{name}}[/TD][/TR]{{/troopers}}[/TABLE]';
        editor(body);
        await selectVisual(body.indexOf('Name') + 1);
        await wrapper.get('[data-testid="tool-column-add"]').trigger('click');
        await flushPromises();

        expect(wrapper.props('modelValue')).toContain('[TH]Name[/TH][TH]Column[/TH]');
        expect(wrapper.props('modelValue')).toContain('[TD]{{name}}[/TD][TD][/TD]');
        expect(surface().querySelectorAll('th')).toHaveLength(3);
    });

    it('labels every button for a screen reader and groups them as a toolbar', () => {
        editor('x');
        const toolbar = wrapper.get('[role="toolbar"]');

        expect(toolbar.attributes('aria-label')).toBe('Format the template');
        for (const button of toolbar.findAll('button')) expect(button.attributes('aria-label') ?? button.text(), button.html()).not.toBe('');
    });
});

describe('the code picker', () => {
    async function openPicker(body, place) {
        editor(body);
        await selectVisual(place);
        await wrapper.get('[data-testid="tool-code"]').trigger('click');
        await flushPromises();

        return document.querySelector('[data-testid="code-picker"]');
    }

    it('offers the trooper loop outside it, and no trooper codes', async () => {
        const picker = await openPicker('ab', 1);

        expect(picker.querySelector('[data-testid="code-pick-troopers"]')).not.toBeNull();
        expect(picker.querySelector('[data-testid="code-pick-name"]')).toBeNull();
        expect(picker.querySelector('[data-testid="code-pick-unit"]')).not.toBeNull();
    });

    it('offers a trooper\'s codes inside the loop, and not the loop again', async () => {
        const body = '{{#troopers}}x{{/troopers}}';
        const picker = await openPicker(body, 13);

        expect(picker.querySelector('[data-testid="code-pick-name"]')).not.toBeNull();
        expect(picker.querySelector('[data-testid="code-pick-troopers"]')).toBeNull();
        expect(picker.querySelector('[data-testid="code-picker-where"]').textContent).toContain('inside the trooper loop');
    });

    it('puts the picked code in at the cursor, drawn as a chip', async () => {
        const body = '{{#troopers}}x{{/troopers}}';
        const picker = await openPicker(body, 14);
        picker.querySelector('[data-testid="code-pick-name"]').click();
        await flushPromises();

        expect(wrapper.props('modelValue')).toBe('{{#troopers}}x{{name}}{{/troopers}}');
        expect(surface().querySelector('[data-raw="{{name}}"]').textContent).toBe('Trooper name');
    });
});

describe('checking the template as it is typed', () => {
    it('says nothing while the template is valid', () => {
        editor(STARTER);

        expect(wrapper.find('[data-testid="template-problem"]').exists()).toBe(false);
    });

    it.each(cases.invalid.map((c) => [c.name, c.template, c.message]))('shows the server\'s own message before a save: %s', (_name, template, message) => {
        editor(template);

        expect(wrapper.get('[data-testid="template-problem-message"]').text()).toBe(message);
    });

    it('says where the problem is, and opens the Source tab on it', async () => {
        editor('Line one\nok {{colour}} x');

        expect(wrapper.get('[data-testid="template-problem"]').text()).toContain('Line 2, column 4.');
        await wrapper.get('[data-testid="template-problem-show"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-testid="template-mode-source"]').attributes('data-state')).toBe('active');
        expect([source().selectionStart, source().selectionEnd]).toEqual([12, 22]);
    });

    it('says a template is too long in the server\'s words', () => {
        editor('x'.repeat(11), { maxLength: 10 });

        expect(wrapper.get('[data-testid="template-problem-message"]').text()).toBe('A template can be at most 10 characters.');
    });

    it('ties the message to the editor for a screen reader', () => {
        editor('{{colour}}');

        expect(surface().getAttribute('aria-describedby')).toBe('template-problem');
        expect(surface().getAttribute('aria-invalid')).toBe('true');
    });
});

describe('what the browser would do its own way', () => {
    const before = (inputType) => new InputEvent('beforeinput', { inputType, bubbles: true, cancelable: true });

    it('refuses Enter over a selection that cuts through a condition, and says why', async () => {
        editor('{{#rc_yes}}Yes{{/rc_yes}} done');
        await selectVisual(12, 28);
        surface().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        await flushPromises();

        expect(changes()).toHaveLength(0);
        expect(wrapper.get('[data-testid="template-refusal"]').text()).toContain('reaches over a condition');
    });

    it('puts nothing in, and takes nothing out, for a paste with no text', async () => {
        editor('a word b');
        await selectVisual(2, 6);
        const paste = new Event('paste', { bubbles: true, cancelable: true });
        paste.clipboardData = { getData: () => '' };
        surface().dispatchEvent(paste);
        await flushPromises();

        expect(changes()).toHaveLength(0);
    });

    it('takes a new line from a phone keyboard as a line end in the template', async () => {
        editor('ab');
        await selectVisual(1);
        const event = before('insertParagraph');
        surface().dispatchEvent(event);
        await flushPromises();

        expect(event.defaultPrevented).toBe(true);
        expect(wrapper.props('modelValue')).toBe('a\nb');
    });

    it('refuses deleting a selection that runs from one cell into the next, and leaves one inside a cell to the browser', async () => {
        editor('[TABLE][TR][TD]one[/TD][TD]two[/TD][/TR][/TABLE]');
        await selectVisual(16, 27);
        const across = before('deleteContentBackward');
        surface().dispatchEvent(across);
        await nextTick();

        expect(across.defaultPrevented).toBe(true);
        expect(wrapper.get('[data-testid="template-refusal"]').text()).toContain('into another cell');

        await selectVisual(15, 17);
        const inside = before('deleteContentBackward');
        surface().dispatchEvent(inside);

        expect(inside.defaultPrevented).toBe(false);
    });

    it('leaves deleting everything to the browser, loop and conditions included', async () => {
        editor(STARTER);
        await selectVisual(0, STARTER.length);
        const all = before('deleteContentBackward');
        surface().dispatchEvent(all);

        expect(all.defaultPrevented).toBe(false);
    });

    it('puts the page back when a phone keyboard makes an edit the surface refused and could not stop', async () => {
        editor('[TABLE][TR][TD]one[/TD][TD]two[/TD][/TR][/TABLE]');
        await selectVisual(16, 27);
        surface().dispatchEvent(new InputEvent('beforeinput', { inputType: 'insertCompositionText', bubbles: true, cancelable: false }));
        surface().querySelector('td').firstChild.data = 'oX';
        surface().querySelectorAll('td')[1].firstChild.data = '';
        surface().dispatchEvent(new Event('input'));
        await nextTick();

        expect(changes()).toHaveLength(0);
        expect([...surface().querySelectorAll('td')].map((cell) => cell.textContent)).toEqual(['one', 'two']);
        expect(wrapper.get('[data-testid="template-refusal"]').text()).toContain('into another cell');
    });

    it('copies the template as written, so the codes survive a cut and paste', async () => {
        editor('a {{#rc_yes}}Y{{/rc_yes}} {{name}} b');
        await selectVisual(2, 33);
        const stored = {};
        const copy = new Event('copy', { bubbles: true, cancelable: true });
        copy.clipboardData = { setData: (type, value) => (stored[type] = value) };
        surface().dispatchEvent(copy);

        expect(copy.defaultPrevented).toBe(true);
        expect(stored['text/plain']).toBe('{{#rc_yes}}Y{{/rc_yes}} {{name}}');
    });

    it('cuts the selection out as the template has it', async () => {
        editor('a {{name}} b');
        await selectVisual(2, 10);
        const cut = new Event('cut', { bubbles: true, cancelable: true });
        cut.clipboardData = { setData: () => {} };
        surface().dispatchEvent(cut);
        await flushPromises();

        expect(wrapper.props('modelValue')).toBe('a  b');
    });

    it('does not put Enter in at an old place when it cannot read the cursor', async () => {
        editor('ab');
        document.getSelection().removeAllRanges();
        surface().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        await flushPromises();

        expect(changes()).toHaveLength(0);
        expect(wrapper.get('[data-testid="template-refusal"]').text()).toContain('Click where the text should go');
    });

    it('does not let the browser format text the template would not have', () => {
        editor('ab');
        const event = before('formatBold');
        surface().dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });

    it('makes undo from a menu its own', async () => {
        editor('a bold b');
        await selectVisual(2, 6);
        await wrapper.get('[data-testid="tool-bold"]').trigger('click');
        await flushPromises();
        const event = before('historyUndo');
        surface().dispatchEvent(event);
        await flushPromises();

        expect(event.defaultPrevented).toBe(true);
        expect(wrapper.props('modelValue')).toBe('a bold b');
    });

    it('reads bold the browser carried on into typing as plain text, and stops showing it', async () => {
        editor('x');
        const bold = document.createElement('b');
        bold.textContent = 'typed';
        surface().append(bold);
        surface().dispatchEvent(new Event('input'));
        await nextTick();

        expect(changes().at(-1)).toEqual(['xtyped']);
        expect(surface().querySelector('b')).toBeNull();
    });
});

describe('undo', () => {
    const undo = async () => {
        surface().dispatchEvent(new KeyboardEvent('keydown', { key: 'z', ctrlKey: true, bubbles: true, cancelable: true }));
        await flushPromises();
    };

    it('does not reach back into the template open before, however its text arrives', async () => {
        editor('TEMPLATE A', { historyKey: 'a' });
        await wrapper.setProps({ historyKey: 'b', modelValue: 'TEMPLATE B' });
        await undo();
        expect(wrapper.props('modelValue')).toBe('TEMPLATE B');

        await wrapper.setProps({ historyKey: 'c' });
        await wrapper.setProps({ modelValue: 'TEMPLATE C' });
        await undo();
        expect(wrapper.props('modelValue')).toBe('TEMPLATE C');
    });

    it('passes over a step that would change nothing, and keeps recording after it', async () => {
        editor('X');
        await wrapper.setProps({ modelValue: 'Xa' });
        await wrapper.setProps({ modelValue: 'X' });
        await undo();
        expect(wrapper.props('modelValue')).toBe('X');

        await wrapper.setProps({ modelValue: 'Xb' });
        await undo();
        expect(wrapper.props('modelValue')).toBe('X');
    });
});

describe('Windows line ends', () => {
    const body = 'one\r\ntwo\r\n{{#troopers}}x{{/troopers}}\r\nafter';

    it('offers the codes for where the Source tab\'s cursor really is', async () => {
        editor(body);
        await openMode('source');
        const place = 'one\ntwo\n{{#troopers}}x{{/troopers}}\na'.length;
        source().focus();
        source().setSelectionRange(place, place);
        source().dispatchEvent(new Event('select'));
        await wrapper.get('[data-testid="tool-code"]').trigger('click');
        await flushPromises();

        expect(document.querySelector('[data-testid="code-pick-troopers"]')).not.toBeNull();
        expect(document.querySelector('[data-testid="code-pick-name"]')).toBeNull();
    });

    it('carries a selection from the visual mode to the same text in the Source tab', async () => {
        editor(body);
        await selectVisual(body.indexOf('two'), body.indexOf('two') + 3);
        await openMode('source');

        expect(source().value.slice(source().selectionStart, source().selectionEnd)).toBe('two');
    });
});

describe('opening another template', () => {
    it('puts the cursor at its end, not where it was in the one before', async () => {
        editor('a long first template', { historyKey: 'a' });
        await selectVisual(2, 6);
        await wrapper.setProps({ historyKey: 'b', modelValue: 'b' });
        await wrapper.get('[data-testid="tool-table"]').trigger('click');
        await flushPromises();

        expect(wrapper.props('modelValue').startsWith('b[TABLE]')).toBe(true);
    });
});

describe('the menus', () => {
    it('links the selection, and says what is wrong with an address it will not use, in the open menu', async () => {
        editor('see here');
        await selectVisual(4, 8);
        await wrapper.get('[data-testid="tool-link"]').trigger('click');
        await flushPromises();
        const address = document.querySelector('[data-testid="tool-link-address"]');

        address.value = 'https://7cav.us/a]b';
        address.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector('[data-testid="tool-link-add"]').click();
        await flushPromises();
        expect(changes()).toHaveLength(0);
        expect(document.querySelector('[data-testid="tool-link-refusal"]').textContent).toContain('no [ ] or {{ }}');

        address.value = 'https://7cav.us';
        address.dispatchEvent(new Event('input'));
        await flushPromises();
        document.querySelector('[data-testid="tool-link-add"]').click();
        await flushPromises();
        expect(wrapper.props('modelValue')).toBe('see [URL=https://7cav.us]here[/URL]');
    });

    it('puts the live roll call image in', async () => {
        editor('');
        await wrapper.get('[data-testid="tool-image"]').trigger('click');
        await flushPromises();
        document.querySelector('[data-testid="tool-image-rc"]').click();
        await flushPromises();

        expect(wrapper.props('modelValue')).toBe('[IMG]{{rc_image}}[/IMG]');
        expect(surface().querySelector('.tpl-image').textContent).toBe('Image: Signed RC image');
    });

    it('says a colour is not one the forum takes only when that is the reason', async () => {
        editor('[TABLE][TR][TD]a[/TD][TD]b[/TD][/TR][/TABLE]');
        await selectVisual(15, 25);
        await wrapper.get('[data-testid="tool-color"]').trigger('click');
        await flushPromises();
        document.querySelector('[data-testid="tool-color-red"]').click();
        await flushPromises();

        expect(document.querySelector('[data-testid="tool-color-refusal"]').textContent).toContain('cannot cross');
    });
});

describe('the length limit', () => {
    it('refuses an edit that would take the template past it, in the server\'s words', async () => {
        editor('123456789', { maxLength: 10 });
        await selectVisual(0, 9);
        await wrapper.get('[data-testid="tool-bold"]').trigger('click');
        await flushPromises();

        expect(changes()).toHaveLength(0);
        expect(wrapper.get('[data-testid="template-refusal"]').text()).toBe('A template can be at most 10 characters.');
    });

    it('counts characters as the server does, so an emoji is one', () => {
        editor('😀'.repeat(10), { maxLength: 10 });

        expect(wrapper.find('[data-testid="template-problem"]').exists()).toBe(false);
    });
});

describe('a save the server refused', () => {
    it('marks the editor invalid and describes it by the page\'s message', () => {
        editor('fine', { errorId: 'template-server-error' });

        expect(surface().getAttribute('aria-invalid')).toBe('true');
        expect(surface().getAttribute('aria-describedby')).toBe('template-server-error');
        expect(source().getAttribute('aria-describedby')).toBe('template-server-error');
    });
});

describe('while read-only', () => {
    it('cannot be typed into, and its buttons are off', () => {
        editor('x', { disabled: true });

        expect(surface().getAttribute('contenteditable')).toBe('false');
        expect(wrapper.get('[data-testid="tool-bold"]').attributes('disabled')).toBeDefined();
    });
});
