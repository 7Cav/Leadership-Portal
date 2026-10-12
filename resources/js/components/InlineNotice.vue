<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';

// An inline notice: an icon beside a short message, in a bordered tile. `warning` is for something the viewer
// should weigh (stale data, an at-risk flag); `muted` is for a plain state (departed, on LOA). Pass `role`
// ("status") when it appears with the page rather than being part of its reading order. The default slot is the
// message, and may hold its own title and body paragraphs.
const props = defineProps({
    icon: { type: [Object, Function], required: true },
    tone: { type: String, default: 'muted', validator: (value) => ['muted', 'warning'].includes(value) },
    role: { type: String, default: null },
    class: { type: [String, Array, Object], default: '' },
});

const tile = computed(() => (props.tone === 'warning' ? 'border-warning/40 bg-warning/8' : 'border-border bg-muted/50'));
const glyph = computed(() => (props.tone === 'warning' ? 'text-warning' : 'text-muted-foreground'));
</script>

<template>
    <div data-slot="inline-notice" :role="role" :class="cn('flex items-start gap-2.5 rounded-md border p-3 text-[13px]', tile, props.class)">
        <component :is="icon" :class="cn('mt-0.5 size-4 shrink-0', glyph)" aria-hidden="true" />
        <div class="min-w-0"><slot /></div>
    </div>
</template>
