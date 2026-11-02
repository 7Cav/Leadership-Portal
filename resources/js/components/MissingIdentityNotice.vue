<script setup>
import { computed } from 'vue';
import MissingIdentities from '@/components/home/MissingIdentities.vue';

// The roll call's and roster's top-of-page warning, built from the page's own rows: each row with `missingGame` (a game
// name, or null) also carries the warning icon; `missingGameAutomatic` says whether that game's server is read. Nothing when everyone is linked.
const props = defineProps({
    troopers: { type: Array, default: () => [] },
});

const nameOf = (t) => t.displayName ?? [t.rankShort, t.username].filter(Boolean).join(' ');

const groups = computed(() => {
    const byGame = new Map();
    for (const trooper of props.troopers) {
        if (!trooper.missingGame) continue;
        if (!byGame.has(trooper.missingGame)) byGame.set(trooper.missingGame, { automatic: false, troopers: [] });
        const group = byGame.get(trooper.missingGame);
        group.troopers.push({ id: trooper.id, name: nameOf(trooper) });
        // `missingGameAutomatic`: whether the game's server is read at all; one game has one answer.
        group.automatic ||= trooper.missingGameAutomatic !== false;
    }

    return [...byGame].map(([game, { automatic, troopers }]) => ({ game, count: troopers.length, automatic, troopers }));
});
</script>

<template>
    <MissingIdentities :groups="groups" :own="false" class="mt-3" />
</template>
