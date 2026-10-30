<script setup>
import { computed } from 'vue';
import { PhWarning } from '@phosphor-icons/vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { discrepancyLines } from '@/lib/staffReport';

// A small warning beside a sent staff report when the game server's practice capture disagrees with it: a captured
// practice they reported did not see them, or one they did not report did. Readers only; the server never sends it
// to the staff member about their own report. A practice with no capture is never a disagreement.
const props = defineProps({
    // `{ notSeen: [label], seenElsewhere: [label] }` or null.
    discrepancy: { type: Object, default: null },
});

const lines = computed(() => discrepancyLines(props.discrepancy));
</script>

<template>
    <TooltipProvider v-if="lines.length" :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    tabindex="0"
                    class="inline-flex shrink-0 items-center rounded-sm text-warning outline-none focus-visible:outline-2 focus-visible:outline-ring"
                    :aria-label="`Differs from the game server. ${lines.join(' ')}`"
                    data-testid="staff-report-discrepancy"
                >
                    <PhWarning class="size-4" weight="fill" aria-hidden="true" />
                </span>
            </TooltipTrigger>
            <TooltipContent class="max-w-72">
                <p v-for="line in lines" :key="line">{{ line }}</p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
