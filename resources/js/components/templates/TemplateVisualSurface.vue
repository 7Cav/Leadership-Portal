<script setup>
import { onMounted, ref, watch } from 'vue';
import { buildDoc } from '@/lib/templateDoc';
import { crossesStructure, drawDoc, hasForeignMarkup, hasLoneEdge, pointAt, readDom, editedRange, touchesStructure } from '@/lib/templateDom';

// The template editor's visual mode (#704): the template drawn as formatted text a leader types into, with each code as
// a chip that cannot be edited and the trooper loop as one block of rows (`lib/templateDom.js` draws and reads it).
//
// It is drawn from the template when the template changes from outside (the Source tab, the toolbar, the code picker,
// another version opening), not while the leader types into it: what is on the page is read back after each input, and
// drawing it again would move the cursor. It is drawn again on input, with the cursor put back, only when the page holds
// what the template no longer does: one end of a section whose other end was deleted, or formatting the browser added
// of its own (bold carried on into new typing, a formatting command).
//
// What the browser would otherwise do its own way is asked of the editor instead (`insert`, `history`), which makes it
// the same checked edit as the toolbar's: Enter and a paste go in as plain text, refused when the selection cuts through
// a section, a tag or a table, and undo and redo from a menu are the editor's own.

const props = defineProps({
    modelValue: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'insert', 'history', 'refused']);

const root = ref(null);
// The template the surface shows now, so a change that came from the surface itself is not drawn again.
let shown = null;

function draw(source) {
    root.value.replaceChildren(drawDoc(buildDoc(source), document));
    shown = source;
}

onMounted(() => draw(props.modelValue));
watch(
    () => props.modelValue,
    (source) => {
        if (source !== shown) draw(source);
    },
);

// An edit the surface refused that the browser made anyway (a phone keyboard's, which cannot be stopped): the next input
// puts the page back as the template still is.
// It holds where the selection was, to put the cursor back there.
let undoNextInput = null;

function read() {
    if (undoNextInput) {
        const { start, end } = undoNextInput;
        undoNextInput = null;
        draw(shown);
        select(start, end);

        return;
    }
    const { source } = readDom(root.value);
    const changed = source !== shown;
    if (hasLoneEdge(root.value) || hasForeignMarkup(root.value)) {
        const place = selection();
        draw(source);
        if (place) select(place.start, place.end);
    }
    if (!changed) return;
    shown = source;
    emit('update:modelValue', source);
}

/** The selection as offsets in the template, or null when it is not in the surface or cannot be placed in it. */
function selection() {
    const selected = document.getSelection();
    if (!selected?.rangeCount || !root.value?.contains(selected.anchorNode)) return null;
    const range = editedRange(selected.getRangeAt(0), root.value);
    const { offsets } = readDom(root.value, [
        { node: range.startContainer, offset: range.startOffset },
        { node: range.endContainer, offset: range.endOffset },
    ]);
    if (offsets.includes(null)) return null;

    return { start: Math.min(...offsets), end: Math.max(...offsets) };
}

/** Puts the cursor (or a selection) at offsets in the template, and the focus in the surface. */
function select(start, end = start) {
    if (!root.value) return;
    root.value.focus();
    const from = pointAt(root.value, start);
    const to = pointAt(root.value, end);
    const range = document.createRange();
    range.setStart(from.node, from.offset);
    range.setEnd(to.node, to.offset);
    const selected = document.getSelection();
    selected.removeAllRanges();
    selected.addRange(range);
}

function onKeydown(event) {
    if (event.key === 'Enter' && !event.isComposing) {
        event.preventDefault();
        emit('insert', '\n');
    }
}

// What reaches the browser's editing without a key the page sees: Enter from a phone keyboard, undo from a menu, bold
// from a touch bar. Formatting is the toolbar's to do, so the template has it too.
const HISTORY = { historyUndo: 'undo', historyRedo: 'redo' };
// Typing or deleting over a selection is the browser's, unless the selection would break the structure (`crossesStructure`).
const OVER_SELECTION = /^(insertText|insertReplacementText|insertCompositionText|delete)/;
function onBeforeinput(event) {
    const type = event.inputType ?? '';
    const selected = document.getSelection();
    const range = selected?.rangeCount ? selected.getRangeAt(0) : null;
    const overSelection = OVER_SELECTION.test(type) && range && !range.collapsed && root.value.contains(range.commonAncestorContainer);
    if (overSelection && crossesStructure(range, root.value)) {
        event.preventDefault();
        if (!event.cancelable) undoNextInput = selection() ?? { start: 0, end: 0 };
        emit('refused');
    } else if (overSelection && event.cancelable && touchesStructure(range, root.value)) {
        // A selection taking whole tables or conditions (everything, say): the browser will not delete across the parts
        // that cannot be edited, so the editor makes the change on the template instead.
        event.preventDefault();
        emit('insert', type.startsWith('insert') ? (event.data ?? '') : '');
    } else if (type.startsWith('format')) {
        event.preventDefault();
    } else if (HISTORY[type]) {
        event.preventDefault();
        emit('history', HISTORY[type]);
    } else if (type === 'insertParagraph' || type === 'insertLineBreak') {
        event.preventDefault();
        emit('insert', '\n');
    }
}

function onPaste(event) {
    event.preventDefault();
    // A paste with no text (an image, say) puts nothing in and takes nothing out.
    const text = event.clipboardData?.getData('text/plain') ?? '';
    if (text !== '') emit('insert', text);
}

// Copy and cut carry the template as written, codes and all, so a line moved by cut and paste keeps its codes rather
// than turning them into the chips' names. A cut that would break the structure copies but takes nothing out.
function onCopy(event, cutting = false) {
    const place = selection();
    if (!place || place.start === place.end || !event.clipboardData) return;
    event.preventDefault();
    event.clipboardData.setData('text/plain', shown.slice(place.start, place.end));
    if (!cutting) return;
    const range = document.getSelection().getRangeAt(0);
    if (crossesStructure(range, root.value)) emit('refused');
    else emit('insert', '');
}

function onDrop(event) {
    // A drag would move chips and markup about in ways the template cannot follow.
    event.preventDefault();
}

defineExpose({ selection, select, focus: () => root.value?.focus() });
</script>

<template>
    <div
        ref="root"
        class="template-visual min-h-72 max-w-full overflow-x-auto rounded-md border border-input bg-background px-3.5 py-3 text-[13px] leading-relaxed whitespace-pre-wrap outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-disabled:cursor-not-allowed aria-disabled:opacity-60 aria-invalid:border-destructive/60"
        role="textbox"
        aria-multiline="true"
        :contenteditable="disabled ? 'false' : 'true'"
        :aria-disabled="disabled ? 'true' : undefined"
        spellcheck="false"
        data-testid="template-visual"
        @input="read"
        @keydown="onKeydown"
        @beforeinput="onBeforeinput"
        @paste="onPaste"
        @copy="(event) => onCopy(event)"
        @cut="(event) => onCopy(event, true)"
        @drop="onDrop"
    />
</template>

<style>
/* The pieces `lib/templateDom.js` draws. Plain CSS, as the elements are made outside Vue's templates. */
.template-visual:empty::before {
    content: 'Write the template here, or start from the default.';
    color: var(--muted-foreground);
}

.template-visual .tpl-chip {
    display: inline-flex;
    align-items: center;
    margin: 0 1px;
    padding: 0 6px;
    border: 1px solid color-mix(in oklab, var(--primary) 55%, transparent);
    border-radius: 4px;
    background: color-mix(in oklab, var(--primary) 16%, transparent);
    color: var(--gold-ink);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.55;
    white-space: nowrap;
    vertical-align: baseline;
    cursor: default;
    user-select: all;
}

.template-visual .tpl-edge {
    border-style: dashed;
    background: transparent;
    font-weight: 500;
}

/* The end of a condition is only a marker: quieter than its start, which says what the condition is. */
.template-visual .tpl-edge[data-edge='close'] {
    padding: 0 4px;
    border-color: color-mix(in oklab, var(--primary) 35%, transparent);
    font-size: 10px;
    opacity: 0.8;
}

/* A code the template language does not have: the check under the editor says what is wrong with it. */
.template-visual .tpl-unknown {
    border-color: color-mix(in oklab, var(--destructive) 60%, transparent);
    background: color-mix(in oklab, var(--destructive) 10%, transparent);
    color: var(--destructive);
}

.template-visual .tpl-image::before {
    content: '';
    width: 9px;
    height: 7px;
    margin-right: 5px;
    border: 1.5px solid currentColor;
    border-radius: 1.5px;
}

.template-visual .tpl-raw {
    display: inline-block;
    margin: 0 1px;
    padding: 0 4px;
    border: 1px dashed var(--border);
    border-radius: 4px;
    background: var(--muted);
    color: var(--muted-foreground);
    font-family: var(--font-mono);
    font-size: 11.5px;
    white-space: pre;
    cursor: default;
    user-select: all;
}

.template-visual .tpl-if {
    border-bottom: 1px dashed color-mix(in oklab, var(--primary) 70%, transparent);
}

.template-visual .tpl-link {
    color: var(--info);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.template-visual blockquote {
    margin: 4px 0;
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: color-mix(in oklab, var(--muted) 50%, transparent);
}

.template-visual .tpl-table {
    width: 100%;
    margin: 4px 0;
    border-collapse: collapse;
}

.template-visual .tpl-table th,
.template-visual .tpl-table td {
    padding: 6px 10px;
    border: 1px solid var(--border);
    text-align: left;
    vertical-align: middle;
}

.template-visual .tpl-table th {
    background: color-mix(in oklab, var(--muted) 70%, transparent);
    font-weight: 600;
}

/* A cell shown only under a condition says so above its content. */
.template-visual .tpl-table [data-condition]::before {
    content: attr(data-condition);
    display: block;
    margin-bottom: 2px;
    color: var(--gold-ink);
    font-size: 10.5px;
    font-weight: 500;
    white-space: nowrap;
}

/* The trooper loop: one band of rows, labelled as repeating. */
.template-visual .tpl-loop {
    background: color-mix(in oklab, var(--primary) 6%, transparent);
}

.template-visual .tpl-loop-label td {
    padding: 3px 10px;
    border-color: color-mix(in oklab, var(--primary) 45%, transparent);
    background: color-mix(in oklab, var(--primary) 14%, transparent);
    color: var(--gold-ink);
    font-size: 11px;
    font-weight: 600;
    user-select: none;
}

.template-visual .tpl-loop-label td::before {
    content: '↻ ';
}

.template-visual .tpl-loop[data-inverted] .tpl-loop-label td::before {
    content: '∅ ';
}
</style>
