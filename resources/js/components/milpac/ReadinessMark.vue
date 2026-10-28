<script setup>
import { computed } from 'vue';
import { PhCheck } from '@phosphor-icons/vue';
import { cn } from '@/lib/utils';

// One cell mark of the training-readiness matrix: the same glyph in the grid and in its legend, so the legend can
// never drift from the cells. An unknown status reads as not held, never as a broken cell. In the grid the words
// go to screen readers beside the glyph; in the legend (`decorative`) the visible text already carries them, so the
// glyph is hidden from assistive tech and nothing is read twice.
const props = defineProps({
    status: { type: String, required: true },
    size: { type: String, default: 'md', validator: (value) => ['md', 'sm'].includes(value) },
    decorative: { type: Boolean, default: false },
});

const MARKS = {
    qualified: { label: 'Qualified', tone: 'border-success/40 bg-success/15 text-success' },
    in_progress: { label: 'In progress', tone: 'border-info/40 bg-info/15 text-info' },
    required: { label: 'Required, not held', tone: 'border-destructive/40 bg-destructive/15 text-destructive' },
};

const known = computed(() => MARKS[props.status] ?? null);
const kind = computed(() => (known.value ? props.status : 'none'));
const box = computed(() => (props.size === 'sm' ? 'size-5' : 'size-6'));
</script>

<template>
    <span v-if="known" :class="cn('inline-flex items-center justify-center rounded-md border text-xs font-semibold', box, known.tone)" :data-status="kind">
        <PhCheck v-if="kind === 'qualified'" :class="size === 'sm' ? 'size-3' : 'size-3.5'" weight="bold" aria-hidden="true" />
        <span v-else-if="kind === 'in_progress'" :class="cn('rounded-full bg-current', size === 'sm' ? 'size-1.5' : 'size-2')" aria-hidden="true" />
        <span v-else aria-hidden="true">!</span>
        <span v-if="!decorative" class="sr-only">{{ known.label }}</span>
    </span>
    <span v-else :class="cn('inline-flex items-center justify-center text-muted-foreground', box)" data-status="none">
        <span aria-hidden="true">—</span>
        <span v-if="!decorative" class="sr-only">Not held</span>
    </span>
</template>
