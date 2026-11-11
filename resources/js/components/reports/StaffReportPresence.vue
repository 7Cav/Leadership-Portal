<script setup>
import { computed } from 'vue';
import { PhCheckCircle, PhWarningCircle } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { confirmedText, presenceText } from '@/lib/staffReport';

// A game server check frozen on a sent report (the section practice, or the public server hour): a red badge when it conflicts with the capture,
// a red alert when there was no capture to compare (the claim can't be checked), and what the servers showed when it agreed.
const props = defineProps({
    // `{ check: 'conflict' | 'confirmed' | 'no_data', source: 'CRCON', minutes?, reason? }` or null.
    presence: { type: Object, default: null },
    testid: { type: String, default: 'staff-report-presence' },
    // The trooper's name, for a public server check that says why there is no data.
    name: { type: String, default: null },
});

const text = computed(() => presenceText(props.presence, props.name));
const confirmed = computed(() => confirmedText(props.presence));
</script>

<template>
    <Badge v-if="presence?.check === 'conflict'" variant="danger" class="font-normal" :data-testid="testid">{{ text }}</Badge>
    <span v-else-if="text" class="inline-flex items-center gap-1 text-xs text-destructive" :data-testid="testid">
        <PhWarningCircle class="size-3.5 shrink-0" weight="fill" aria-hidden="true" />{{ text }}
    </span>
    <span v-else-if="confirmed" class="inline-flex items-center gap-1 text-xs text-success" :data-testid="testid">
        <PhCheckCircle class="size-3.5 shrink-0" weight="fill" aria-hidden="true" />{{ confirmed }}
    </span>
</template>
