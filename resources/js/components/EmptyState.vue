<script setup>
import { cn } from '@/lib/utils';

// A designed empty state (shell-f.html `emptyState()`): an icon tile, what is missing, and in the
// default slot, what will appear here and what makes it appear. Says what to expect; never a
// placeholder number.
const props = defineProps({
    icon: { type: [Object, Function], required: true },
    title: { type: String, required: true },
    class: { type: [String, Array, Object], default: '' },
});
</script>

<template>
    <div data-slot="empty-state" :class="cn('flex flex-col items-center px-6 text-center', props.class)">
        <span class="mb-3 grid size-10 place-items-center rounded-lg border border-border text-xl text-muted-foreground" aria-hidden="true">
            <component :is="icon" />
        </span>
        <p class="text-sm font-medium">{{ title }}</p>
        <p class="mt-1 max-w-[42ch] text-xs text-muted-foreground"><slot /></p>
    </div>
</template>
