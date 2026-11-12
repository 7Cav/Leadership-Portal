<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    PhBracketsCurly,
    PhCode,
    PhColumns,
    PhColumnsPlusRight,
    PhImage,
    PhLink,
    PhPalette,
    PhPencilSimple,
    PhTable,
    PhTextAa,
    PhTextAlignCenter,
    PhTextB,
    PhTextItalic,
    PhTextUnderline,
    PhWarningCircle,
} from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import TemplateVisualSurface from '@/components/templates/TemplateVisualSurface.vue';
import { parse, templateLegend } from '@/lib/bbcode';
import { SIZES, bbcodeElement } from '@/lib/bbcodeRender';
import { contextAt } from '@/lib/templateDoc';
import { addColumn, insertCode, insertImage, insertLink, insertTable, insertText, removeColumn, setOption, toggleTag, usableAddress } from '@/lib/templateEdits';

// The rollcall template editor (#704): a visual mode where a leader formats text and builds the trooper table without
// writing BBCode or codes, and a Source tab with the template as written, for whoever prefers it. Both edit the same
// text (`v-model`), so switching between them changes nothing. The toolbar and the code picker work the same in both
// (`lib/templateEdits.js`): each takes the template and the selection, and refuses, saying why, an edit that would break
// it. A syntax error shows under the editor as the leader types, in the words the server would refuse the save with
// (`parse()`), with where it is.

const props = defineProps({
    modelValue: { type: String, required: true },
    maxLength: { type: Number, required: true },
    disabled: { type: Boolean, default: false },
    // Changes when another template or version opens, so undo does not reach back into the one before.
    historyKey: { type: [String, Number], default: '' },
    // The id of the page's message when the server refused the last save, so the field is marked invalid and described by
    // it too, as it was before the editor checked anything itself.
    errorId: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

// Which mode is open is remembered per browser: whoever prefers the source keeps it.
const MODE_KEY = 'templates.editor-mode';
function storedMode() {
    try {
        return window.localStorage.getItem(MODE_KEY) === 'source' ? 'source' : 'visual';
    } catch {
        return 'visual';
    }
}
const mode = ref(storedMode());
watch(mode, (value) => {
    try {
        window.localStorage.setItem(MODE_KEY, value);
    } catch {
        // A browser that keeps nothing opens the visual mode next time.
    }
});

const surface = ref(null);
const sourceField = ref(null);
const field = () => sourceField.value?.$el ?? null;

// The last selection in either mode, as offsets in the template. A toolbar button or a popover takes the focus, so the
// selection is kept as it changes rather than read when the button is used.
const saved = ref({ start: props.modelValue.length, end: props.modelValue.length });
function remember() {
    if (mode.value === 'visual') {
        const place = surface.value?.selection();
        if (place) saved.value = place;
    } else if (document.activeElement === field()) {
        saved.value = { start: field().selectionStart, end: field().selectionEnd };
    }
}
onMounted(() => document.addEventListener('selectionchange', remember));
onBeforeUnmount(() => document.removeEventListener('selectionchange', remember));

const clamp = ({ start, end }) => {
    const length = props.modelValue.length;

    return { start: Math.min(start, length), end: Math.min(end, length) };
};

// Undo in the visual mode. The Source tab keeps the browser's own, which the toolbar's edits join there; the visual mode
// is drawn again after an edit from outside, which the browser's undo cannot follow.
const past = [];
const future = [];
let lastChange = 0;
// The text an undo or redo just put back, so its own change is not recorded as a new step.
let restored = null;
// One watcher for both, so another template opening (its key and its text change together, or the text a moment later)
// clears the history rather than recording the template before as a step to undo back to.
let key = props.historyKey;
let opening = false;
watch(
    () => [props.historyKey, props.modelValue],
    ([nowKey, now], [, before]) => {
        if (nowKey !== key) {
            key = nowKey;
            past.length = 0;
            future.length = 0;
            restored = null;
            // Text that arrives a moment after the key is the template opening, not an edit.
            opening = true;
            setTimeout(() => {
                opening = false;
            }, 0);

            return;
        }
        if (opening) return;
        if (now === restored) {
            restored = null;

            return;
        }
        // Typing within a second of the last change is one step, as in a text field.
        const stamp = Date.now();
        if (stamp - lastChange > 1000 || past.length === 0) past.push(before);
        if (past.length > 200) past.shift();
        future.length = 0;
        lastChange = stamp;
    },
);

function firstDifference(a, b) {
    let index = 0;
    while (index < a.length && index < b.length && a[index] === b[index]) index++;

    return index;
}

async function step(from, to) {
    // A step that would change nothing (typing that was then deleted within the same second) is passed over.
    while (from.length && from.at(-1) === props.modelValue) from.pop();
    if (!from.length) return;
    const target = from.pop();
    to.push(props.modelValue);
    restored = target;
    lastChange = 0;
    const caret = firstDifference(props.modelValue, target);
    emit('update:modelValue', target);
    await nextTick();
    surface.value?.select(caret);
}

// A sentence saying why the last toolbar action did nothing, until the next one.
const refusal = ref(null);
const tooLong = `A template can be at most ${props.maxLength} characters.`;

// The server counts a template's length in characters (`mb_strlen`), so an emoji is one, not two.
const lengthOf = (text) => [...text].length;

// The text an edit works on. The Source tab's own text, as the browser gives it: a textarea reads a Windows line end as
// one character, so its selection is counted that way, and the edit is made on the same text.
const editedText = () => (mode.value === 'source' && field() ? field().value : props.modelValue);

// Puts an edit's result in the open mode, with its selection.
async function put({ source, start, end }) {
    refusal.value = null;
    if (mode.value === 'source' && field()) {
        // The changed part goes in through the browser's own editing, so Ctrl+Z in the Source tab takes it back.
        const element = field();
        const before = element.value;
        const from = firstDifference(before, source);
        let tail = 0;
        while (tail < before.length - from && tail < source.length - from && before.at(-1 - tail) === source.at(-1 - tail)) tail++;
        element.focus();
        element.setSelectionRange(from, before.length - tail);
        // `execCommand('insertText')` is deprecated, but it is still the only way to edit a textarea through the browser's
        // own editing. It fires an `input` event, which updates the template through v-model like typing does. Where it
        // is missing, refuses (happy-dom in the tests) or leaves other text than the edit's, the text is set directly
        // instead, which works the same except for undo.
        const native = typeof document.execCommand === 'function' && document.execCommand('insertText', false, source.slice(from, source.length - tail));
        if (!native || element.value !== source) emit('update:modelValue', source);
        await nextTick();
        element.focus();
        element.setSelectionRange(start, end);
    } else {
        lastChange = 0;
        emit('update:modelValue', source);
        await nextTick();
        surface.value?.select(start, end);
    }
    saved.value = { start, end };
}

/**
 * Runs one edit (`lib/templateEdits.js`) on the template and the kept selection.
 *
 * @returns {boolean} whether it was made
 */
function run(edit, refused) {
    if (props.disabled) return false;
    const text = editedText();
    const start = Math.min(saved.value.start, text.length);
    const end = Math.min(saved.value.end, text.length);
    const result = edit(text, start, end);
    if (!result) {
        refusal.value = typeof refused === 'function' ? refused(text, start, end) : refused;

        return false;
    }
    if (lengthOf(result.source) > props.maxLength) {
        refusal.value = tooLong;

        return false;
    }
    put(result);

    return true;
}

const CROSSING = 'Select text inside one line, cell or code block: formatting cannot cross into another one.';
const bold = () => run((source, start, end) => toggleTag(source, start, end, 'b'), CROSSING);
const italic = () => run((source, start, end) => toggleTag(source, start, end, 'i'), CROSSING);
const underline = () => run((source, start, end) => toggleTag(source, start, end, 'u'), CROSSING);
const centre = () => run((source, start, end) => toggleTag(source, start, end, 'center'), CROSSING);

// Text sizes as the forum numbers them, each shown at its size.
const sizes = Object.entries(SIZES).map(([size, px]) => ({ size, px }));
const sizeOpen = ref(false);
function size(value) {
    sizeOpen.value = false;
    run((source, start, end) => setOption(source, start, end, 'size', value), CROSSING);
}

// The forum's own palette, and any other colour by name or #rrggbb.
const COLORS = [
    { name: 'Red', value: '#c0392b' },
    { name: 'Orange', value: '#d35400' },
    { name: 'Gold', value: '#b7950b' },
    { name: 'Green', value: '#1e8449' },
    { name: 'Blue', value: '#2471a3' },
    { name: 'Purple', value: '#7d3c98' },
    { name: 'Grey', value: '#707b7c' },
];
const colorOpen = ref(false);
const customColor = ref('');
function color(value) {
    const refused = bbcodeElement('color', String(value).trim()) ? CROSSING : 'That colour is not one the forum takes. Use a name such as red, or #rrggbb.';
    if (run((source, start, end) => setOption(source, start, end, 'color', value), refused)) {
        colorOpen.value = false;
        customColor.value = '';
    }
}

// An address the editor will not use, or the selection, whichever is the reason an edit was refused.
const BAD_ADDRESS = 'Use a full address starting https:// or http://, with no [ ] or {{ }} in it.';
const CUT = 'Put the cursor in plain text, not in the middle of a code, a tag or a table.';

const linkOpen = ref(false);
const linkAddress = ref('');
function link() {
    if (run((source, start, end) => insertLink(source, start, end, linkAddress.value), usableAddress(linkAddress.value) ? CROSSING : BAD_ADDRESS)) {
        linkOpen.value = false;
        linkAddress.value = '';
    }
}

const imageOpen = ref(false);
const imageAddress = ref('');
function image(address) {
    const isCode = /^\{\{[a-z_]+\}\}$/.test(address);
    if (run((source, start, end) => insertImage(source, start, end, address), isCode || usableAddress(address) ? CUT : BAD_ADDRESS)) {
        imageOpen.value = false;
        imageAddress.value = '';
    }
}

const table = () =>
    run(insertTable, (source, start) => (contextAt(source, start).inRow ? 'A table cannot go inside the trooper loop. Put the cursor outside it first.' : CUT));

// A menu opens without the last refusal in it: that was about another action.
watch([colorOpen, linkOpen, imageOpen], (open) => {
    if (open.some(Boolean)) refusal.value = null;
});

// Enter and a paste in the visual mode, made as checked edits like the toolbar's.
const SELECTION_CUTS = 'That selection reaches over a condition\'s end or into another cell. Select inside one line or cell, or change it in Source.';
const typed = (text) => {
    remember();
    run((source, start, end) => insertText(source, start, end, text), SELECTION_CUTS);
};
// Typing or deleting over such a selection, which the surface refused before the browser made it.
const refuseCut = () => {
    refusal.value = SELECTION_CUTS;
};
const history = (direction) => (direction === 'undo' ? step(past, future) : step(future, past));
const NOT_IN_CELL = 'Put the cursor in a cell of a trooper table first.';
const columnAdd = () => run((source, start) => addColumn(source, start), NOT_IN_CELL);
const columnRemove = () => run((source, start) => removeColumn(source, start), `${NOT_IN_CELL} A table keeps at least one column.`);

// The code picker offers only the codes that may go at the cursor: the post's own values and flags anywhere, a trooper's
// own codes as well inside the trooper loop, and the loop itself as well outside it. A filter is written by hand in the
// Source tab.
const legend = templateLegend();
const pickerOpen = ref(false);
const pickerSearch = ref('');
const inRow = computed(() => contextAt(props.modelValue, clamp(saved.value).start).inRow);
const pickable = computed(() => {
    const keys = inRow.value ? ['values', 'topFlags', 'rowValues', 'rowFlags'] : ['values', 'topFlags', 'lists'];
    const term = pickerSearch.value.trim().toLowerCase();

    return legend
        .filter((group) => keys.includes(group.key))
        .map((group) => ({
            ...group,
            entries: group.entries.filter((entry) => term === '' || `${entry.label} ${entry.name} ${entry.meaning}`.toLowerCase().includes(term)),
        }))
        .filter((group) => group.entries.length > 0);
});
function pick(entry) {
    pickerOpen.value = false;
    pickerSearch.value = '';
    run((source, start, end) => insertCode(source, start, end, entry), 'A code cannot go in the middle of another code or tag.');
}
watch(pickerOpen, (open) => {
    if (!open) pickerSearch.value = '';
});

/** Puts a code from the page's legend in at the cursor, as the picker does. */
function insert(entry) {
    run((source, start, end) => insertCode(source, start, end, entry), 'A code cannot go in the middle of another code or tag.');
}

const SHORTCUTS = { b: bold, i: italic, u: underline };
function onKeydown(event) {
    const mod = event.metaKey || event.ctrlKey;
    if (!mod || event.altKey) return;
    const key = event.key.toLowerCase();
    if (SHORTCUTS[key] && !event.shiftKey) {
        event.preventDefault();
        remember();
        SHORTCUTS[key]();
    } else if (mode.value === 'visual' && ((key === 'z' && event.shiftKey) || key === 'y')) {
        event.preventDefault();
        step(future, past);
    } else if (mode.value === 'visual' && key === 'z') {
        event.preventDefault();
        step(past, future);
    }
}

// The template as typed, checked as the server will check it on save.
const problem = computed(() => {
    if (lengthOf(props.modelValue) > props.maxLength) return { error: tooLong, at: props.modelValue.length, length: 0 };
    const result = parse(props.modelValue);

    return result.ok ? null : result;
});
const invalid = computed(() => (problem.value || props.errorId ? 'true' : undefined));
const describedBy = computed(() => [problem.value ? 'template-problem' : null, props.errorId].filter(Boolean).join(' ') || undefined);

const where = computed(() => {
    if (!problem.value) return '';
    const before = props.modelValue.slice(0, problem.value.at).split('\n');

    return `Line ${before.length}, column ${before.at(-1).length + 1}.`;
});

// Opens the Source tab with the problem selected.
async function showProblem() {
    const { at, length } = problem.value;
    saved.value = { start: at, end: at + length };
    mode.value = 'source';
    await nextTick();
    await nextTick();
    field()?.focus();
    field()?.setSelectionRange(at, at + length);
}

// Switching to the Source tab carries the selection across without taking the focus from the tabs, so a keyboard user
// can go on between them. The visual mode keeps the kept selection for the toolbar until the leader clicks in it.
watch(mode, async (value) => {
    if (value !== 'source') return;
    const { start, end } = clamp(saved.value);
    await nextTick();
    field()?.setSelectionRange(start, end);
});

defineExpose({ insert });
</script>

<template>
    <Tabs v-model="mode" :unmount-on-hide="false" class="min-w-0 gap-0" data-testid="template-editor">
        <div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-2 border-b border-border">
            <TabsList aria-label="Template editor mode" class="border-b-0">
                <TabsTrigger value="visual" data-testid="template-mode-visual"><PhPencilSimple aria-hidden="true" />Visual</TabsTrigger>
                <TabsTrigger value="source" data-testid="template-mode-source"><PhCode aria-hidden="true" />Source</TabsTrigger>
            </TabsList>
        </div>

        <div
            role="toolbar"
            aria-label="Format the template"
            aria-controls="template-body-visual template-body"
            class="flex flex-wrap items-center gap-0.5 border-b border-border py-1.5"
            data-testid="template-toolbar"
        >
            <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Bold" title="Bold (Ctrl+B)" :disabled="disabled" data-testid="tool-bold" @mousedown.prevent @click="bold">
                <PhTextB weight="bold" aria-hidden="true" />
            </Button>
            <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Italic" title="Italic (Ctrl+I)" :disabled="disabled" data-testid="tool-italic" @mousedown.prevent @click="italic">
                <PhTextItalic aria-hidden="true" />
            </Button>
            <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Underline" title="Underline (Ctrl+U)" :disabled="disabled" data-testid="tool-underline" @mousedown.prevent @click="underline">
                <PhTextUnderline aria-hidden="true" />
            </Button>

            <Popover v-model:open="sizeOpen">
                <PopoverTrigger as-child>
                    <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Text size" title="Text size" :disabled="disabled" data-testid="tool-size" @mousedown.prevent>
                        <PhTextAa aria-hidden="true" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-44 p-1" data-testid="tool-size-menu">
                    <button
                        v-for="option in sizes"
                        :key="option.size"
                        type="button"
                        class="flex w-full items-baseline justify-between rounded-sm px-2 py-1 text-left outline-none hover:bg-accent focus-visible:bg-accent"
                        :aria-label="`Size ${option.size}, ${option.px} pixels`"
                        :data-testid="`tool-size-${option.size}`"
                        @click="size(option.size)"
                    >
                        <span :style="{ fontSize: `${option.px}px` }">Size {{ option.size }}</span>
                        <span class="text-[11px] text-muted-foreground">{{ option.px }}px</span>
                    </button>
                </PopoverContent>
            </Popover>

            <Popover v-model:open="colorOpen">
                <PopoverTrigger as-child>
                    <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Text colour" title="Text colour" :disabled="disabled" data-testid="tool-color" @mousedown.prevent>
                        <PhPalette aria-hidden="true" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-56 space-y-2.5 p-3" data-testid="tool-color-menu">
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="option in COLORS"
                            :key="option.value"
                            type="button"
                            class="size-7 rounded-sm border border-border outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                            :style="{ backgroundColor: option.value }"
                            :aria-label="option.name"
                            :title="option.name"
                            :data-testid="`tool-color-${option.name.toLowerCase()}`"
                            @click="color(option.value)"
                        />
                    </div>
                    <form class="flex gap-1.5" @submit.prevent="color(customColor)">
                        <Input v-model="customColor" aria-label="Another colour, by name or #rrggbb" placeholder="#rrggbb or a name" class="h-8 text-xs" data-testid="tool-color-custom" />
                        <Button type="submit" size="sm" variant="outline" class="h-8">Use</Button>
                    </form>
                    <p v-if="refusal" class="text-[11px] text-destructive" role="status" data-testid="tool-color-refusal">{{ refusal }}</p>
                </PopoverContent>
            </Popover>

            <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Centre" title="Centre" :disabled="disabled" data-testid="tool-center" @mousedown.prevent @click="centre">
                <PhTextAlignCenter aria-hidden="true" />
            </Button>

            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />

            <Popover v-model:open="linkOpen">
                <PopoverTrigger as-child>
                    <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Link" title="Link" :disabled="disabled" data-testid="tool-link" @mousedown.prevent>
                        <PhLink aria-hidden="true" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-72 p-3" data-testid="tool-link-menu">
                    <form class="grid gap-2" @submit.prevent="link">
                        <label for="template-link-address" class="text-xs font-medium">Link address</label>
                        <div class="flex gap-1.5">
                            <Input id="template-link-address" v-model="linkAddress" type="url" placeholder="https://7cav.us/..." class="h-8 text-xs" data-testid="tool-link-address" />
                            <Button type="submit" size="sm" class="h-8" data-testid="tool-link-add">Add link</Button>
                        </div>
                        <p class="text-[11px] text-muted-foreground">Links the selected text, or adds the address as a link.</p>
                        <p v-if="refusal" class="text-[11px] text-destructive" role="status" data-testid="tool-link-refusal">{{ refusal }}</p>
                    </form>
                </PopoverContent>
            </Popover>

            <Popover v-model:open="imageOpen">
                <PopoverTrigger as-child>
                    <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Image" title="Image" :disabled="disabled" data-testid="tool-image" @mousedown.prevent>
                        <PhImage aria-hidden="true" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-72 space-y-3 p-3" data-testid="tool-image-menu">
                    <div class="grid gap-1.5">
                        <p class="text-xs font-medium">Live result image</p>
                        <div class="flex flex-wrap gap-1.5">
                            <Button type="button" size="sm" variant="outline" class="h-8" data-testid="tool-image-rc" @click="image('{{rc_image}}')">Signed RC</Button>
                            <Button type="button" size="sm" variant="outline" class="h-8" data-testid="tool-image-sp" @click="image('{{sp_image}}')">Attended SP</Button>
                        </div>
                    </div>
                    <form class="grid gap-1.5" @submit.prevent="image(imageAddress)">
                        <label for="template-image-address" class="text-xs font-medium">Or an image address</label>
                        <div class="flex gap-1.5">
                            <Input id="template-image-address" v-model="imageAddress" type="url" placeholder="https://7cav.us/..." class="h-8 text-xs" data-testid="tool-image-address" />
                            <Button type="submit" size="sm" class="h-8">Add image</Button>
                        </div>
                    </form>
                    <p v-if="refusal" class="text-[11px] text-destructive" role="status" data-testid="tool-image-refusal">{{ refusal }}</p>
                </PopoverContent>
            </Popover>

            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />

            <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Add a trooper table" title="Add a trooper table" :disabled="disabled" data-testid="tool-table" @mousedown.prevent @click="table">
                <PhTable aria-hidden="true" />
            </Button>
            <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Add a column after the cursor's" title="Add a column after the cursor's" :disabled="disabled" data-testid="tool-column-add" @mousedown.prevent @click="columnAdd">
                <PhColumnsPlusRight aria-hidden="true" />
            </Button>
            <Button type="button" variant="ghost" size="icon" class="size-8" aria-label="Remove the cursor's column" title="Remove the cursor's column" :disabled="disabled" data-testid="tool-column-remove" @mousedown.prevent @click="columnRemove">
                <PhColumns aria-hidden="true" />
            </Button>

            <span class="mx-1 h-5 w-px bg-border" aria-hidden="true" />

            <Popover v-model:open="pickerOpen">
                <PopoverTrigger as-child>
                    <Button type="button" variant="outline" size="sm" class="h-8 gap-1.5" :disabled="disabled" data-testid="tool-code" @mousedown.prevent>
                        <PhBracketsCurly aria-hidden="true" />Insert code
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-80 p-0" data-testid="code-picker">
                    <div class="border-b border-border p-2">
                        <Input v-model="pickerSearch" aria-label="Find a code" placeholder="Find a code" class="h-8 text-xs" data-testid="code-picker-search" />
                        <p class="mt-1.5 px-0.5 text-[11px] text-muted-foreground" data-testid="code-picker-where">
                            {{ inRow ? 'The cursor is inside the trooper loop: a trooper\'s own codes can go here.' : 'The cursor is outside the trooper loop.' }}
                        </p>
                    </div>
                    <div class="max-h-80 overflow-y-auto p-1">
                        <section v-for="group in pickable" :key="group.key" :aria-label="group.title" class="py-1" :data-testid="`code-picker-${group.key}`">
                            <h3 class="px-2 pt-1 pb-0.5 text-[11px] font-medium text-muted-foreground">{{ group.title }}</h3>
                            <button
                                v-for="entry in group.entries"
                                :key="entry.name"
                                type="button"
                                class="flex w-full items-baseline justify-between gap-3 rounded-sm px-2 py-1.5 text-left text-[13px] outline-none hover:bg-accent focus-visible:bg-accent"
                                :title="entry.meaning"
                                :data-testid="`code-pick-${entry.name}`"
                                @click="pick(entry)"
                            >
                                <span>{{ entry.label }}</span>
                                <code class="font-mono text-[11px] text-muted-foreground">{{ entry.code }}</code>
                            </button>
                        </section>
                        <p v-if="!pickable.length" class="px-2 py-3 text-xs text-muted-foreground">No code matches that search.</p>
                    </div>
                </PopoverContent>
            </Popover>
        </div>

        <p v-if="refusal" class="mt-2 text-xs text-muted-foreground" role="status" data-testid="template-refusal">{{ refusal }}</p>

        <TabsContent value="visual" class="min-w-0 pt-2.5">
            <TemplateVisualSurface
                id="template-body-visual"
                ref="surface"
                :model-value="modelValue"
                :disabled="disabled"
                aria-labelledby="template-body-label"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                @update:model-value="(value) => emit('update:modelValue', value)"
                @keydown="onKeydown"
                @insert="typed"
                @history="history"
                @refused="refuseCut"
            />
        </TabsContent>
        <TabsContent value="source" class="min-w-0 pt-2.5">
            <Textarea
                id="template-body"
                ref="sourceField"
                :model-value="modelValue"
                data-testid="template-body"
                rows="16"
                class="font-mono text-xs"
                spellcheck="false"
                :maxlength="maxLength"
                :disabled="disabled"
                aria-labelledby="template-body-label"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                @update:model-value="(value) => emit('update:modelValue', value)"
                @keydown="onKeydown"
                @select="remember"
                @keyup="remember"
                @click="remember"
            />
        </TabsContent>

        <div
            v-if="problem"
            id="template-problem"
            class="mt-2.5 flex items-start gap-2.5 rounded-md border border-destructive/35 bg-destructive/6 p-3 text-[13px]"
            role="status"
            data-testid="template-problem"
        >
            <PhWarningCircle class="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <p class="font-medium text-foreground" data-testid="template-problem-message">{{ problem.error }}</p>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ where }} The save is refused until this is fixed.</p>
            </div>
            <Button type="button" variant="outline" size="sm" class="h-7 shrink-0 text-xs" data-testid="template-problem-show" @click="showProblem">Show in Source</Button>
        </div>
    </Tabs>
</template>
