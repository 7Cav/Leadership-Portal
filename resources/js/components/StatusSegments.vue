<script setup>
import { cn } from '@/lib/utils';

// A row of mutually exclusive choices drawn as one segmented control, as the rollcall mockup draws RC and SP (Story 3.7).
// It only draws what it is given: the options, which one is on, and whether it may be changed. While `readonly` the
// segments are not controls at all, so a screen that cannot yet mark (or a row whose value is derived) offers nothing to
// press. `tone` colours the selected segment, and `dashed` outlines it for a suggestion rather than a saved value.
const props = defineProps({
    options: { type: Array, required: true }, // [{ value, label, title? }]
    modelValue: { type: String, default: null },
    label: { type: String, required: true },
    readonly: { type: Boolean, default: true },
    dashed: { type: Boolean, default: false },
    tones: { type: Object, default: () => ({}) }, // { value: 'tailwind classes for the selected segment' }
});

const emit = defineEmits(['update:modelValue']);

const SELECTED = 'text-foreground';

function choose(value) {
    if (!props.readonly) emit('update:modelValue', value);
}
</script>

<template>
    <div role="group" :aria-label="label" class="inline-flex overflow-hidden rounded-md border border-border bg-muted/20 text-[11px]">
        <component
            :is="readonly ? 'span' : 'button'"
            v-for="option in options"
            :key="option.value"
            :type="readonly ? undefined : 'button'"
            :title="option.title"
            :aria-pressed="readonly ? undefined : String(modelValue === option.value)"
            :aria-current="readonly && modelValue === option.value ? 'true' : undefined"
            :data-selected="modelValue === option.value ? 'true' : 'false'"
            :data-value="option.value"
            :class="
                cn(
                    'min-w-9 border-r border-border px-2 py-1 text-center font-medium text-muted-foreground last:border-r-0',
                    !readonly && 'cursor-pointer hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring',
                    modelValue === option.value && [SELECTED, tones[option.value] ?? 'bg-accent'],
                    modelValue === option.value && dashed && 'outline-dashed outline-1 -outline-offset-2 outline-current',
                )
            "
            @click="choose(option.value)"
        >
            {{ option.label }}
            <span v-if="modelValue === option.value" class="sr-only">(selected)</span>
        </component>
    </div>
</template>
