<script setup>
import { computed } from 'vue';
import { PhLockSimple, PhSignOut, PhUserPlus, PhWarning } from '@phosphor-icons/vue';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { FILTERS } from '@/lib/roster';

// The roster's flag filter (roster-s2.html `#flt`), with live counts. "Departed" only shows when
// the subtree has departed troopers, which All leaves out.
const props = defineProps({
    troopers: { type: Array, required: true },
    currentWeek: { type: String, required: true },
});
const model = defineModel({ type: String, default: 'all' });

const chips = computed(() =>
    [
        { key: 'all', label: 'All' },
        { key: 'risk', label: 'At-risk', icon: PhWarning, tone: 'text-destructive' },
        { key: 'loa', label: 'On LOA', icon: PhLockSimple },
        { key: 'new', label: 'New recruits', icon: PhUserPlus },
        { key: 'departed', label: 'Departed', icon: PhSignOut },
    ]
        .map((chip) => ({ ...chip, count: props.troopers.filter((t) => FILTERS[chip.key](t, props.currentWeek)).length }))
        .filter((chip) => chip.key !== 'departed' || chip.count > 0),
);

function select(value) {
    // A single-select group emits an empty value when the active chip is pressed again.
    model.value = value || 'all';
}
</script>

<template>
    <div class="max-w-full overflow-x-auto">
        <ToggleGroup type="single" variant="segment" :model-value="model" aria-label="Show troopers" @update:model-value="select">
            <ToggleGroupItem v-for="chip in chips" :key="chip.key" :value="chip.key" :data-testid="`filter-${chip.key}`">
                <component :is="chip.icon" v-if="chip.icon" :class="chip.tone" />
                {{ chip.label }}
                <span class="text-[11px] text-muted-foreground tabular-nums">{{ chip.count }}</span>
            </ToggleGroupItem>
        </ToggleGroup>
    </div>
</template>
