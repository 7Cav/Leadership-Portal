<script setup>
import { computed } from 'vue';
import { weekEnding, weekLabel } from '@/lib/forumDate';

// The long week form inside a heading: "Week Ending 30OCT26" at the heading's size, then the range in smaller muted
// text on the same line, wrapping under it on a phone. Its text reads the same as `weekTitle()` (or `weekPhrase()`
// with `phrase`), so plain-text uses (document titles, toasts, aria labels) keep calling those helpers.
const props = defineProps({
    week: { type: String, required: true },
    // Lower case, for a heading that names the week mid-sentence: "Roll call, week ending 30OCT26".
    phrase: { type: Boolean, default: false },
});

const lead = computed(() => `${props.phrase ? 'week ending' : 'Week Ending'} ${weekEnding(props.week)}`);
const range = computed(() => `(${weekLabel(props.week)})`);
</script>

<template>
    <span>{{ lead }} <span class="inline-block text-sm font-normal text-muted-foreground sm:text-base">{{ range }}</span></span>
</template>
