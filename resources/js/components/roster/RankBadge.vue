<script setup>
import { computed } from 'vue';
import { RANK_NAMES, rankTier } from '@/lib/roster';

// The rank badge (roster-s2.html `.rk`): commissioned officers solid, warrant officers and NCOs
// outlined, enlisted muted, so seniority reads at a glance down a column.
const props = defineProps({
    rank: { type: String, default: null },
    title: { type: String, default: null },
    large: { type: Boolean, default: false },
});

const tier = computed(() => rankTier(props.rank));
const label = computed(() => props.title || RANK_NAMES[props.rank] || props.rank || 'Rank unknown');
</script>

<template>
    <span
        :title="label"
        :class="[
            'inline-flex shrink-0 items-center justify-center rounded-sm border font-semibold tracking-wide tabular-nums',
            large ? 'h-9 min-w-12 px-2 text-[13px]' : 'h-[1.375rem] w-12 text-[11px]',
            tier === 'officer' && 'border-transparent bg-foreground text-background',
            (tier === 'warrant' || tier === 'nco') && 'border-foreground/30 bg-card text-foreground',
            tier === 'enlisted' && 'border-border bg-muted/60 text-foreground',
        ]"
    >
        <slot>{{ rank || '?' }}</slot>
    </span>
</template>
