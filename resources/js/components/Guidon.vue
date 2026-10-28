<script setup>
import { computed } from 'vue';
import { guidonDataUrl } from '@/lib/guidon';

// A unit's guidon (#446), drawn from its saved design next to the unit's name on the roster, the unit picker and Org
// structure, and large in the generator's preview. A unit with no guidon (`design` null) shows nothing at all. The
// flag is an <img> of the SVG lib/guidon.js draws, so the page never renders it as markup.
const props = defineProps({
    design: { type: Object, default: null },
    // The flag's height in pixels; its width follows the guidon's proportions.
    height: { type: Number, default: 14 },
    // Empty for a flag beside the unit's own name (decorative); a name for the preview.
    alt: { type: String, default: '' },
});

const src = computed(() => guidonDataUrl(props.design));
</script>

<template>
    <img
        v-if="src"
        :src="src"
        :alt="alt"
        :height="height"
        :style="{ height: `${height}px`, width: 'auto' }"
        class="inline-block shrink-0 align-middle"
        data-testid="guidon"
    />
</template>
