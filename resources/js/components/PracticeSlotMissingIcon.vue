<script setup>
import { PhWarning } from '@phosphor-icons/vue';

// A small warning beside a section with no practice slot set (#392). A native title, not a tooltip component, because it
// sits inside row buttons on the roster.
defineProps({
    missing: { type: Boolean, default: false },
});
</script>

<template>
    <span v-if="missing" role="img" class="inline-flex shrink-0 items-center text-warning" title="No practice slot set" aria-label="No practice slot set" data-testid="practice-slot-missing">
        <PhWarning class="size-3.5" weight="fill" aria-hidden="true" />
    </span>
</template>
