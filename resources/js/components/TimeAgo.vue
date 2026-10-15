<script setup>
import { computed } from 'vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useNow } from '@/composables/useNow';
import { localTime, timeAgo } from '@/lib/timeAgo';

// A portal timestamp with how long ago it was: the Zulu text the caller passes in the slot stays the
// primary reading, followed by `(35 min ago)`, and hovering or focusing it shows the same moment on
// the viewer's own clock. All instances share one once-a-minute clock (useNow).
const props = defineProps({
    // ISO-8601 string, epoch milliseconds or a Date.
    at: { type: [String, Number, Date], default: null },
    // Off when the stamp already sits inside another tooltip's trigger, which then names the local
    // time itself; a tooltip inside a tooltip trigger never opens.
    tooltip: { type: Boolean, default: true },
    // On inside a live region (`role="status"`): the relative part is hidden from screen readers
    // there, or every minute's tick would be read out again. The Zulu text stays readable.
    quiet: { type: Boolean, default: false },
});

const now = useNow();
const relative = computed(() => timeAgo(props.at, now.value));
const local = computed(() => localTime(props.at));
const iso = computed(() => (relative.value ? new Date(props.at).toISOString() : null));
</script>

<template>
    <TooltipProvider v-if="relative && tooltip" :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <time
                    :datetime="iso"
                    tabindex="0"
                    class="rounded-sm outline-none focus-visible:outline-2 focus-visible:outline-ring"
                    data-time-ago
                    ><slot />{{ $slots.default ? ' ' : '' }}<span data-relative :aria-hidden="quiet ? 'true' : undefined">({{ relative }})</span></time
                >
            </TooltipTrigger>
            <TooltipContent data-local-time>Your time: {{ local }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
    <time v-else-if="relative" :datetime="iso" data-time-ago
        ><slot />{{ $slots.default ? ' ' : '' }}<span data-relative :aria-hidden="quiet ? 'true' : undefined">({{ relative }})</span></time
    >
    <slot v-else />
</template>
