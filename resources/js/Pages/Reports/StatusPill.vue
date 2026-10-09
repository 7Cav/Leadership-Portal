<script setup>
import { computed } from 'vue';
import { PhCheckCircle, PhCircleDashed, PhPauseCircle, PhPencilSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { STATUS } from '@/lib/reportRollup';

// A report's send status as a pill (report-p2.html `.pill`): sent, draft, holding (waiting on the
// chain below it) or not started. Text and icon both carry the state, never colour alone.
const props = defineProps({
    status: { type: String, required: true },
});

const ICONS = { sent: PhCheckCircle, draft: PhPencilSimple, holding: PhPauseCircle, not_started: PhCircleDashed };
const meta = computed(() => STATUS[props.status]);
</script>

<template>
    <Badge
        :variant="meta.variant"
        class="h-[1.375rem] rounded-full px-2"
        :class="{ 'border-dashed': status === 'not_started' }"
        :data-status="status"
    >
        <component :is="ICONS[status]" aria-hidden="true" />{{ meta.label }}
    </Badge>
</template>
