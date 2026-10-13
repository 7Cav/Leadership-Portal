<script setup>
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { SOURCES, provenanceOf } from './attendanceSource';

// One row's attendance provenance (Story 3.9): an icon and a short label, the full meaning in a
// tooltip. LOA-derived rows show the LOA badge instead of a source.
const props = defineProps({
    status: { type: String, default: null },
    source: { type: String, default: null },
});

const kind = computed(() => provenanceOf(props));
const meta = computed(() => SOURCES[kind.value]);
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <Badge v-if="kind === 'loa'" variant="outline" tabindex="0" :data-provenance="kind" :aria-label="`${meta.label}: ${meta.detail}`">
                    <component :is="meta.icon" aria-hidden="true" />{{ meta.label }}
                </Badge>
                <span
                    v-else
                    tabindex="0"
                    class="inline-flex items-center gap-1 rounded-sm outline-none focus-visible:outline-2 focus-visible:outline-ring"
                    :data-provenance="kind"
                    :aria-label="`${meta.label}: ${meta.detail}`"
                >
                    <component :is="meta.icon" :class="['size-3.5', meta.tone]" aria-hidden="true" />{{ meta.label }}
                </span>
            </TooltipTrigger>
            <TooltipContent>{{ meta.detail }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
