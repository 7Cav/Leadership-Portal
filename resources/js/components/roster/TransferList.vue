<script setup>
import { PhArrowsLeftRight } from '@phosphor-icons/vue';
import { transferLabel } from '@/lib/roster';

// A trooper's moves between units, newest first (decided 2026-10-27), on the milpac and the roster Sheet. The server
// shapes each move (`Milpac\PlacementHistory`); this only words it.
defineProps({
    transfers: { type: Array, required: true },
});
</script>

<template>
    <ul class="space-y-1.5" data-testid="transfers">
        <li v-for="(move, index) in transfers" :key="`${move.at}-${index}`" class="flex items-start gap-2 text-[13px]" data-testid="transfer">
            <PhArrowsLeftRight class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span>{{ transferLabel(move) }}</span>
        </li>
    </ul>
</template>
