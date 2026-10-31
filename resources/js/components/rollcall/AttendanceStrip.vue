<script setup>
import { computed } from 'vue';
import WeekCell from '@/components/roster/WeekCell.vue';
import { SP_STATES, weekCell, weekLabel } from '@/lib/roster';

// A trooper's Section Practice over the last completed Cav weeks, oldest first, as small squares
// coloured like the roster's attendance matrix (the same `weekCell` states and `WeekCell` swatches),
// so a leader marking this week sees the pattern behind it. `weeks` is the controller's `history`:
// `[{ week, status, held, loa }]`; `joined` the trooper's Cav join date.
const props = defineProps({
    weeks: { type: Array, required: true },
    joined: { type: String, default: null },
});

const cells = computed(() =>
    props.weeks.map((entry) => {
        const { sp } = weekCell(entry.loa ? { ...entry, status: 'loa' } : entry, entry.week, { joined: props.joined });

        return { week: entry.week, state: sp, label: SP_STATES[sp] };
    }),
);

// "present", "no SP held", "LOA": the state names as they read inside a sentence.
const spoken = (cell) => (cell.state === 'loa' ? 'LOA' : cell.label.charAt(0).toLowerCase() + cell.label.slice(1));

const label = computed(() => `Last ${cells.value.length} weeks: ${cells.value.map(spoken).join(', ')}`);
</script>

<template>
    <span role="img" :aria-label="label" class="inline-flex flex-none items-center gap-[3px]" data-testid="attendance-strip">
        <span
            v-for="cell in cells"
            :key="cell.week"
            class="inline-flex"
            :title="`Week Ending ${weekLabel(cell.week)}: ${cell.label}`"
            :data-state="cell.state"
        >
            <WeekCell :sp="cell.state" size="xs" />
        </span>
    </span>
</template>
