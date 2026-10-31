<script setup>
import { computed } from 'vue';
import MissingIdentities from '@/components/home/MissingIdentities.vue';

// The roll call's and roster's top-of-page warning, built from the page's own rows: each row with `missingGame` (a game
// name, or null) also carries the warning icon. Nothing when everyone is linked.
const props = defineProps({
    troopers: { type: Array, default: () => [] },
});

const nameOf = (t) => t.displayName ?? [t.rankShort, t.username].filter(Boolean).join(' ');

const groups = computed(() => {
    const byGame = new Map();
    for (const trooper of props.troopers) {
        if (!trooper.missingGame) continue;
        if (!byGame.has(trooper.missingGame)) byGame.set(trooper.missingGame, []);
        byGame.get(trooper.missingGame).push({ id: trooper.id, name: nameOf(trooper) });
    }

    return [...byGame].map(([game, troopers]) => ({ game, count: troopers.length, troopers }));
});
</script>

<template>
    <MissingIdentities :groups="groups" :own="false" class="mt-3" />
</template>
