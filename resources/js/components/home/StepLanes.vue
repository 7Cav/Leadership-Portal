<script setup>
import { computed } from 'vue';
import { PhClockCountdown } from '@phosphor-icons/vue';
import { laneTotals } from '@/lib/home';
import EmptyState from '@/components/EmptyState.vue';

// The week bar's step lanes (shell-f.html): each step added up across the sections in scope, drawn
// across the Saturday to Saturday grid with the day columns it sits under. The fill is how much of
// the step is done; the lane never claims a deadline the portal does not define.
const props = defineProps({
    steps: { type: Array, required: true },
});

const lanes = computed(() => laneTotals(props.steps));
</script>

<template>
    <EmptyState v-if="!steps.length" :icon="PhClockCountdown" title="Step tracking starts with roll calls" class="border-t border-border py-6">
        Each section's steps for the week show across these days once roll calls run in the portal.
    </EmptyState>

    <template v-else>
        <!-- Wider screens: one lane per step across the day grid. -->
        <ul class="hidden border-t border-border pb-2 sm:block" data-testid="step-lanes">
            <li v-for="lane in lanes" :key="lane.label" class="grid grid-cols-[9.5rem_minmax(0,1fr)] items-center" :data-lane="lane.label">
                <span class="px-5 text-xs text-muted-foreground">{{ lane.label }}</span>
                <span
                    class="relative my-1 mr-2 flex h-7 items-center overflow-hidden rounded-md border px-2 text-xs font-medium tabular-nums"
                    :class="lane.complete ? 'border-border bg-muted text-muted-foreground' : 'border-primary/60 bg-primary/10'"
                    :title="`${lane.label}: ${lane.text}`"
                >
                    <span class="absolute inset-y-0 left-0" :class="lane.complete ? 'bg-foreground/9' : 'bg-primary/28'" :style="{ width: `${lane.fill * 100}%` }" aria-hidden="true" />
                    <span class="relative truncate">{{ lane.text }}</span>
                </span>
            </li>
        </ul>

        <!-- Phone: the same steps as a compact list. -->
        <ul class="space-y-2.5 border-t border-border px-4 py-3 sm:hidden" data-testid="step-lanes-mobile">
            <li v-for="lane in lanes" :key="lane.label">
                <p class="text-xs text-muted-foreground">{{ lane.label }}</p>
                <div class="mt-1 flex items-center gap-2">
                    <span class="h-1 w-16 flex-none overflow-hidden rounded-full bg-muted" aria-hidden="true">
                        <span class="block h-full rounded-full" :class="lane.complete ? 'bg-muted-foreground' : 'bg-primary'" :style="{ width: `${lane.fill * 100}%` }" />
                    </span>
                    <span class="text-[13px] font-medium tabular-nums">{{ lane.text }}</span>
                </div>
            </li>
        </ul>
    </template>
</template>
