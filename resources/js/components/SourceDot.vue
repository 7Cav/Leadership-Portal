<script setup>
import { computed } from 'vue';
import { SOURCE_KINDS, sourceKind } from '@/lib/attendanceSource';

// The dot that says where one attendance mark came from (Story 3.9). It only draws what it is given, the stored
// `source` and `status`, and shows nothing at all for a value that was never stored. The words are there for hover and
// for screen readers, since colour alone says nothing to someone who cannot see it.
const props = defineProps({
    source: { type: String, default: null },
    status: { type: String, default: null },
});

const kind = computed(() => sourceKind(props.source, props.status));
const info = computed(() => (kind.value ? SOURCE_KINDS[kind.value] : null));
</script>

<template>
    <span v-if="info" class="inline-flex items-center align-middle" :title="info.title" :data-source-kind="kind" data-testid="source-dot">
        <span class="size-2 rounded-full" :class="info.dot" aria-hidden="true" />
        <span class="sr-only">{{ info.title }}</span>
    </span>
</template>
