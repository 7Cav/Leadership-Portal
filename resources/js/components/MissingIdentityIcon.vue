<script setup>
import { computed } from 'vue';
import { PhWarning } from '@phosphor-icons/vue';

// A small warning beside a trooper who has no identity linked for the game their unit plays, so the game server's
// capture cannot see them. A native title, not a tooltip component, because it sits inside row buttons on the roster.
const props = defineProps({
    // The game's name, or null when nothing is missing.
    game: { type: String, default: null },
});

const label = computed(() => `No ${props.game} identity linked, so the game server can't see them`);
</script>

<template>
    <span
        v-if="game"
        role="img"
        class="inline-flex shrink-0 items-center text-warning"
        :title="label"
        :aria-label="label"
        data-testid="missing-identity"
    >
        <PhWarning class="size-3.5" weight="fill" aria-hidden="true" />
    </span>
</template>
