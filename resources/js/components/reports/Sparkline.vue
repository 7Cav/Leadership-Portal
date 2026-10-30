<script setup>
import { computed } from 'vue';

// A word-sized trend line: one series, no axes, the last point marked. A null value is a gap, never a zero. The values
// are for the eye only; the caller says them in words (`label`) for a screen reader.
const props = defineProps({
    values: { type: Array, required: true },
    label: { type: String, required: true },
    // A fixed scale (`[0, 100]` for a percentage), else the values' own range.
    domain: { type: Array, default: null },
    color: { type: String, default: 'var(--chart-1)' },
});

const W = 96;
const H = 24;
const PAD = 3;

const range = computed(() => {
    if (props.domain) return props.domain;
    const known = props.values.filter((v) => v !== null && v !== undefined);
    if (!known.length) return [0, 1];
    const lo = Math.min(...known);
    const hi = Math.max(...known);

    return lo === hi ? [lo - 1, hi + 1] : [lo, hi];
});

const x = (i) => PAD + (i * (W - 2 * PAD)) / Math.max(props.values.length - 1, 1);
const y = (v) => PAD + (1 - (v - range.value[0]) / (range.value[1] - range.value[0] || 1)) * (H - 2 * PAD);

const path = computed(() => {
    let d = '';
    let pen = false;
    props.values.forEach((v, i) => {
        if (v === null || v === undefined) {
            pen = false;

            return;
        }
        d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`;
        pen = true;
    });

    return d;
});

const lastIndex = computed(() => props.values.length - 1);
const last = computed(() => props.values[lastIndex.value]);
</script>

<template>
    <svg :width="W" :height="H" :viewBox="`0 0 ${W} ${H}`" class="block overflow-visible" role="img" :aria-label="label" data-testid="sparkline">
        <path :d="path" fill="none" :style="{ stroke: color }" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
        <circle v-if="last !== null && last !== undefined" :cx="x(lastIndex)" :cy="y(last)" r="2.5" :style="{ fill: color }" />
    </svg>
</template>
