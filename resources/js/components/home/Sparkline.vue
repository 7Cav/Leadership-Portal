<script setup>
import { computed } from 'vue';

// A KPI's trailing weeks as a bare trend line (Story 5.9): sent weeks solid, the live value as a
// hollow end marker, and a break wherever a week has no sent snapshot. No axis, because the tile
// carries the number; a screen-reader label lists every value instead.
const props = defineProps({
    // `[{ week, value, live }]`, oldest first; `value` is null for a gap.
    series: { type: Array, required: true },
    label: { type: String, required: true },
    // How a value reads in the screen-reader label, e.g. `82%`.
    format: { type: Function, default: (value) => String(value) },
});

const W = 96;
const H = 28;
const PAD = 3;

const known = computed(() => props.series.filter((point) => point.value !== null).map((point) => point.value));
const min = computed(() => Math.min(...known.value));
const max = computed(() => Math.max(...known.value));

const x = (index) => PAD + (index * (W - PAD * 2)) / Math.max(props.series.length - 1, 1);
// A flat series sits mid-height instead of dividing by zero.
const y = (value) => (max.value === min.value ? H / 2 : PAD + ((max.value - value) / (max.value - min.value)) * (H - PAD * 2));

const path = computed(() => {
    let d = '';
    let pen = false;
    props.series.forEach((point, index) => {
        if (point.value === null) {
            pen = false;

            return;
        }
        d += `${pen ? 'L' : 'M'}${x(index).toFixed(1)},${y(point.value).toFixed(1)}`;
        pen = true;
    });

    return d;
});

const description = computed(() =>
    `${props.label} over the last ${props.series.length - 1} weeks: ${props.series
        .map((point) => (point.value === null ? 'no sent report' : props.format(point.value)))
        .join(', ')}`,
);
const last = computed(() => props.series.length - 1);
</script>

<template>
    <svg :width="W" :height="H" :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="description" class="block shrink-0 overflow-visible" data-testid="sparkline">
        <path :d="path" fill="none" stroke="var(--chart-2)" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" />
        <template v-for="(point, index) in series" :key="index">
            <circle
                v-if="point.value !== null && !point.live"
                :cx="x(index)"
                :cy="y(point.value)"
                r="2"
                fill="var(--chart-2)"
            />
        </template>
        <circle
            v-if="series[last].value !== null"
            :cx="x(last)"
            :cy="y(series[last].value)"
            r="3"
            fill="var(--card)"
            stroke="var(--chart-1)"
            stroke-width="2"
        />
    </svg>
</template>
