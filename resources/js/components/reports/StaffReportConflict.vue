<script setup>
import { computed } from 'vue';
import { PhWarning } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { conflictLines } from '@/lib/staffReport';

// "Conflicting information": a sent staff report claims a section practice that the section marked as not held. It
// only flags the report; what the report says and whether it met the rule stand as sent. `compact` is the overview
// row's badge, which says the same on hover.
const props = defineProps({
    // Who sent the report.
    name: { type: String, required: true },
    // The section labels whose practice conflicts, or null.
    sections: { type: Array, default: null },
    compact: { type: Boolean, default: false },
});

const lines = computed(() => conflictLines(props.name, props.sections));
</script>

<template>
    <template v-if="lines.length">
        <Badge v-if="compact" variant="warning" class="font-normal" :title="lines.join(' ')" data-testid="staff-report-conflict">
            Conflicting information<span class="sr-only">. {{ lines.join(' ') }}</span>
        </Badge>
        <div v-else class="flex items-start gap-2.5 rounded-lg border border-warning/35 bg-warning/8 px-3.5 py-2 text-[13px]" data-testid="staff-report-conflict">
            <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <p class="font-semibold">Conflicting information</p>
                <p v-for="line in lines" :key="line" class="text-muted-foreground">{{ line }}</p>
            </div>
        </div>
    </template>
</template>
