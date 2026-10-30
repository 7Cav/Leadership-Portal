<script setup>
import { computed } from 'vue';
import { PhWarningCircle } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { presenceText } from '@/lib/staffReport';

// The game server check frozen on a sent section practice report: a red badge when it conflicts with the capture,
// a red alert when there was no capture to compare (the claim can't be checked), nothing when it agreed.
const props = defineProps({
    // `{ check: 'conflict' | 'confirmed' | 'no_data', source: 'CRCON' }` or null.
    presence: { type: Object, default: null },
});

const text = computed(() => presenceText(props.presence));
</script>

<template>
    <Badge v-if="presence?.check === 'conflict'" variant="danger" class="font-normal" data-testid="staff-report-presence">{{ text }}</Badge>
    <span v-else-if="text" class="inline-flex items-center gap-1 text-xs text-destructive" data-testid="staff-report-presence">
        <PhWarningCircle class="size-3.5 shrink-0" weight="fill" aria-hidden="true" />{{ text }}
    </span>
</template>
