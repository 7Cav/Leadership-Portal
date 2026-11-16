<script setup>
import { PhArrowsLeftRight } from '@phosphor-icons/vue';
import { transferLabel, transferNote } from '@/lib/roster';

// A trooper's moves between units, newest first (decided 2026-10-27), on the milpac and the roster Sheet. The server
// shapes each move (`Milpac\PlacementHistory`), leaving out a round trip inside one week and marking one undone later;
// this only words it.
defineProps({
    transfers: { type: Array, required: true },
});
</script>

<template>
    <ul class="space-y-1.5" data-testid="transfers">
        <li v-for="(move, index) in transfers" :key="`${move.at}-${index}`" class="flex items-start gap-2 text-[13px]" data-testid="transfer">
            <PhArrowsLeftRight class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span>
                {{ transferLabel(move) }}
                <span v-if="transferNote(move)" class="block text-xs text-muted-foreground" data-testid="transfer-note">{{ transferNote(move) }}</span>
            </span>
        </li>
    </ul>
</template>
