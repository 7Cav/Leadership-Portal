<script setup>
import { SOURCES } from './attendanceSource';

// The shared source legend (Story 3.9): what each provenance mark means, in the same icons the
// rows use. Reused by every screen that shows attendance marks.
const ITEMS = ['auto', 'manual', 'makeup'].map((key) => ({ key, ...SOURCES[key] }));
</script>

<template>
    <ul class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground" aria-label="Attendance source legend">
        <li v-for="item in ITEMS" :key="item.key" class="inline-flex items-center gap-1" :title="item.detail">
            <component :is="item.icon" :class="['size-3.5', item.tone]" aria-hidden="true" />{{ item.label }}
        </li>
    </ul>
</template>
