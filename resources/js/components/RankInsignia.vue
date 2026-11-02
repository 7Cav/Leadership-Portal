<script setup>
import { computed } from 'vue';
import { INSIGNIA_BOX, rankInsigniaPlacement, rankInsigniaUrl } from '@/lib/rankInsignia';
import { RANK_NAMES } from '@/lib/roster';

// The small rank insignia the rollcall post prints before a name, drawn the way the post preview draws it: a fixed
// square with the insignia optically centred, so every rank carries the same weight and nothing shifts as it loads.
// Nothing at all for a rank without one.
const props = defineProps({
    rank: { type: String, default: null },
    // '' when the rank is written right next to it (the default); null to name the rank instead.
    alt: { type: String, default: '' },
});

const src = computed(() => rankInsigniaUrl(props.rank));
const place = computed(() => rankInsigniaPlacement(props.rank));
const altText = computed(() => props.alt ?? (RANK_NAMES[String(props.rank ?? '').toUpperCase()] || props.rank || ''));
</script>

<template>
    <span
        v-if="src && place"
        class="relative -top-px inline-block shrink-0 overflow-hidden align-middle"
        :style="{ width: `${INSIGNIA_BOX}px`, height: `${INSIGNIA_BOX}px` }"
        data-testid="rank-insignia"
    >
        <img
            :src="src"
            :alt="altText"
            class="absolute max-w-none"
            :style="{ width: `${place.width}px`, height: `${place.height}px`, left: `${place.left}px`, top: `${place.top}px` }"
            loading="lazy"
            decoding="async"
        />
    </span>
</template>
