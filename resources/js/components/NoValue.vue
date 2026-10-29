<script setup>
// The "no value" glyph in a table or a figure: a dash for the eye and words for a screen reader, which would otherwise
// read the bare dash or nothing at all (the same pattern as milpac/ReadinessMark.vue).
defineProps({
    label: { type: String, default: 'Not available' },
});
</script>

<template>
    <span aria-hidden="true">—</span><span class="sr-only">{{ label }}</span>
</template>
