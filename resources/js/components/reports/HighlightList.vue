<script setup>
import {
    PhCalendarCheck,
    PhClockCounterClockwise,
    PhMinus,
    PhQuestion,
    PhSignature,
    PhStack,
    PhTarget,
    PhTrendDown,
    PhTrendUp,
    PhUserMinus,
    PhWarning,
} from '@phosphor-icons/vue';

// Highlights written from the week's data (P3) — see `highlights()` in lib/report.js.
defineProps({
    items: { type: Array, required: true },
});

const ICONS = {
    up: PhTrendUp,
    down: PhTrendDown,
    minus: PhMinus,
    warning: PhWarning,
    miss: PhUserMinus,
    question: PhQuestion,
    signature: PhSignature,
    excused: PhCalendarCheck,
    makeup: PhClockCounterClockwise,
    stack: PhStack,
    target: PhTarget,
};
const TONES = { good: 'text-success', bad: 'text-destructive', warn: 'text-warning', neutral: 'text-muted-foreground' };
</script>

<template>
    <ul class="space-y-0.5" data-testid="highlights">
        <li v-for="item in items" :key="item.id" class="grid grid-cols-[1.25rem_minmax(0,1fr)] items-start gap-2.5 py-1.5">
            <component :is="ICONS[item.icon] ?? PhMinus" class="mt-px size-4" :class="TONES[item.tone]" aria-hidden="true" />
            <p class="text-[13px] leading-snug">{{ item.text }}</p>
        </li>
    </ul>
</template>
