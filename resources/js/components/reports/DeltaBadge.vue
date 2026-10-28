<script setup>
import { computed } from 'vue';
import { PhTrendDown, PhTrendUp } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';

// A KPI's signed change vs the baseline report (5.4): green when the move is good for that KPI,
// red when it needs attention, neutral at zero. Direction is carried by the arrow and the sign
// too, never by colour alone.
const props = defineProps({
    change: { type: Object, default: null },
});

const variant = computed(() => ({ good: 'success', bad: 'danger' })[props.change?.tone] ?? 'outline');
const icon = computed(() => ({ up: PhTrendUp, down: PhTrendDown })[props.change?.direction] ?? null);
</script>

<template>
    <Badge v-if="change" :variant="variant" class="tabular-nums" data-testid="delta">
        <component :is="icon" v-if="icon" aria-hidden="true" />{{ change.text }}<span class="sr-only">{{ change.unit ? ` ${change.unit}` : '' }} compared with the last sent report</span>
    </Badge>
</template>
