<script setup>
import { LEGEND_ORDER, SOURCE_KINDS } from '@/lib/attendanceSource';

// The shared legend for attendance sources (Story 3.9): auto, manual and makeup, each with the dot `SourceDot` draws. Every
// screen that shows the dots shows this beside them.
</script>

<template>
    <span class="flex shrink-0 items-center gap-3 text-xs text-muted-foreground" data-testid="source-legend" aria-label="Source legend">
        <span v-for="kind in LEGEND_ORDER" :key="kind" class="flex items-center gap-1" :title="SOURCE_KINDS[kind].title" :data-legend="kind">
            <span class="size-2 rounded-full" :class="SOURCE_KINDS[kind].dot" aria-hidden="true" />{{ SOURCE_KINDS[kind].label }}
        </span>
    </span>
</template>
