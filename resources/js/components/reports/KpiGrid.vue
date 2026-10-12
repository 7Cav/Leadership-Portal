<script setup>
import { KPIS, formatKpi, kpiChange } from '@/lib/report';
import DeltaBadge from './DeltaBadge.vue';

// The six canonical KPIs (AD-21) as a 2×3 / 3×2 grid, each with its change vs the baseline
// report when there is one (5.4) and a one-line hint underneath.
defineProps({
    kpis: { type: Object, required: true },
    delta: { type: Object, default: null },
    hints: { type: Object, default: () => ({}) },
});
</script>

<template>
    <dl class="grid grid-cols-2 gap-px bg-border sm:grid-cols-3" data-testid="kpi-grid">
        <div v-for="kpi in KPIS" :key="kpi.key" class="bg-card p-3.5" :data-kpi="kpi.key">
            <dt class="text-xs text-muted-foreground">{{ kpi.label }}</dt>
            <dd class="mt-0.5 flex items-baseline gap-2">
                <span class="text-xl font-semibold tracking-tight tabular-nums">{{ formatKpi(kpis, kpi) }}</span>
                <DeltaBadge :change="kpiChange(delta, kpi)" />
            </dd>
            <dd class="mt-0.5 truncate text-[11px] text-muted-foreground">{{ hints[kpi.key] || ' ' }}</dd>
        </div>
    </dl>
</template>
