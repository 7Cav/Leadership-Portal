<script setup>
import { computed, ref } from 'vue';
import { PhCaretUpDown, PhCheck } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { focusNextAfter, isPlainTab } from '@/lib/listboxTab';

// The site's dropdown for a long or open-ended list: a button that opens a search box over the options, filtering as
// you type. The search ignores case, and a Cav name's dot also matches a space ("pike s" finds Pike.S). Enter or Tab
// in the search box picks the top row; Tab then moves on to the next field.
//
// `options` are `{ value, label, keywords?, group?, depth?, disabled? }`, listed in the order given (rows sharing a
// `group` sit under that heading; `depth` indents a row under its parent, for a unit tree). `anyLabel` adds a first row that sets `anyValue` (a filter's "Any user", a form's "No
// game"). With `name`, a hidden input carries the value for a plain form submit.
defineOptions({ inheritAttrs: false });

const props = defineProps({
    options: { type: Array, required: true },
    anyLabel: { type: String, default: null },
    anyValue: { default: null },
    placeholder: { type: String, default: 'Choose one' },
    searchPlaceholder: { type: String, default: 'Search' },
    emptyText: { type: String, default: 'Nothing matches that search.' },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: undefined },
    name: { type: String, default: undefined },
    testid: { type: String, default: undefined },
    contentTestid: { type: String, default: undefined },
    ariaLabel: { type: String, default: undefined },
    ariaDescribedby: { type: String, default: undefined },
    title: { type: String, default: undefined },
    buttonClass: { type: [String, Array, Object], default: '' },
    contentClass: { type: [String, Array, Object], default: 'w-72' },
});

const model = defineModel({ default: null });
const emit = defineEmits(['change']);

const open = ref(false);
const trigger = ref(null);
let tabbing = false;

// Values from the server and from a form can differ in type (1 and "1"), so they compare as text.
const same = (a, b) => a === b || (a !== null && a !== undefined && b !== null && b !== undefined && String(a) === String(b));

const selected = computed(() => props.options.find((option) => same(option.value, model.value)) ?? null);
const isAny = computed(() => props.anyLabel !== null && !selected.value);
const shown = computed(() => selected.value?.label ?? props.anyLabel ?? props.placeholder);

const groups = computed(() => {
    const out = [];
    for (const option of props.options) {
        const heading = option.group ?? null;
        let group = out.find((g) => g.heading === heading);
        if (!group) out.push((group = { heading, options: [] }));
        group.options.push(option);
    }
    return out;
});

// Search text a row carries besides its label, hidden: its keywords, and its label with dots as spaces.
function extra(option) {
    const words = [...(option.keywords ?? [])];
    if (String(option.label).includes('.')) words.push(String(option.label).replace(/\./g, ' '));
    return words.join(' ');
}

const triggerEl = () => trigger.value?.$el ?? trigger.value;

function pick(value) {
    const changed = !same(value, model.value);
    model.value = value;
    open.value = false;
    if (changed) emit('change', value);
}

// Tab in the search box: CommandInput has already picked the top row (if any). Close, and move focus on from the
// button rather than back to it.
function onKeydown(event) {
    if (!isPlainTab(event)) return;
    event.preventDefault();
    tabbing = true;
    open.value = false;
}

function onCloseAutoFocus(event) {
    if (!tabbing) return;
    tabbing = false;
    event.preventDefault();
    focusNextAfter(triggerEl());
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                ref="trigger"
                type="button"
                variant="outline"
                role="combobox"
                :disabled="disabled"
                :aria-expanded="open"
                :aria-invalid="invalid || undefined"
                :aria-label="ariaLabel"
                :aria-describedby="ariaDescribedby"
                :title="title"
                :class="[
                    'h-8 w-full justify-between bg-card px-2.5 text-[13px] font-normal aria-invalid:border-destructive aria-invalid:ring-destructive/20',
                    buttonClass,
                ]"
                :data-testid="testid"
                :data-value="model ?? ''"
                v-bind="$attrs"
            >
                <span class="truncate" :class="selected ? '' : 'text-muted-foreground'">{{ shown }}</span>
                <PhCaretUpDown class="opacity-50" aria-hidden="true" />
            </Button>
        </PopoverTrigger>
        <input v-if="name" type="hidden" :name="name" :value="model ?? ''" />
        <PopoverContent :class="['p-0', contentClass]" align="start" :data-testid="contentTestid" @close-auto-focus="onCloseAutoFocus">
            <Command @keydown="onKeydown">
                <CommandInput :placeholder="searchPlaceholder" :aria-label="searchPlaceholder" />
                <CommandList class="max-h-[min(320px,50vh)]">
                    <CommandEmpty>{{ emptyText }}</CommandEmpty>
                    <CommandGroup v-if="anyLabel !== null">
                        <CommandItem :value="anyLabel" data-option="" data-any @select="pick(anyValue)">
                            <span class="text-muted-foreground" data-name>{{ anyLabel }}</span>
                            <PhCheck v-if="isAny" class="ml-auto" aria-label="Selected" />
                        </CommandItem>
                    </CommandGroup>
                    <CommandGroup v-for="group in groups" :key="group.heading ?? ''" :heading="group.heading ?? undefined">
                        <CommandItem
                            v-for="option in group.options"
                            :key="String(option.value)"
                            :value="String(option.value)"
                            :disabled="option.disabled"
                            :data-option="String(option.value)"
                            :style="option.depth ? { paddingLeft: `${0.5 + option.depth * 0.875}rem` } : undefined"
                            @select="pick(option.value)"
                        >
                            <span data-name>{{ option.label }}</span>
                            <span v-if="extra(option)" hidden aria-hidden="true">{{ extra(option) }}</span>
                            <PhCheck v-if="selected && same(option.value, selected.value)" class="ml-auto" aria-label="Selected" />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
