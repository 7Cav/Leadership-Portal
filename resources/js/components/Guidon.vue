<script setup>
import { computed } from 'vue';
import { guidonDataUrl, TEXTLESS_BELOW } from '@/lib/guidon';

// A unit's guidon (#446), drawn from its saved design: 12px in lists (the roster's unit picker, the sidebar's unit list,
// Org structure, Staff reports), 16px beside a unit name in a page header, 32px where it stands for the unit without
// its name (the sidebar's crest spot fits it into a 32px square, Home), and large in the generator's preview.
// Below TEXTLESS_BELOW pixels tall (the 12px lists and 16px headers) the flag is drawn plain, colors and split only;
// `plain` overrides that either way.
// A unit with no guidon (`design` null) shows nothing at all. The flag is an <img> of the SVG lib/guidon.js draws, so the
// page never renders it as markup.
const props = defineProps({
    design: { type: Object, default: null },
    // The flag's height in pixels; its width follows the guidon's proportions.
    height: { type: Number, default: 14 },
    // Empty for a flag beside the unit's own name (decorative); a name for the preview.
    alt: { type: String, default: '' },
    // True or false to force the plain flag on or off; left out, it follows the height.
    plain: { type: Boolean, default: undefined },
});

const src = computed(() => guidonDataUrl(props.design, { plain: props.plain ?? props.height < TEXTLESS_BELOW }));
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
