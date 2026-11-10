<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

// What the companies, platoons and sections under a battalion count now, read only on the org structure page. Each
// links to its roster's Admin tab, where it is changed.
const props = defineProps({
    // `[{ id, label, type, options, source }]` from `AccountabilityRules::battalions()`.
    units: { type: Array, required: true },
    // The battalion's tick list, for the option labels.
    choices: { type: Array, required: true },
});

const GROUPS = [
    { type: 'company', title: 'Company HQ' },
    { type: 'platoon', title: 'Platoon HQ' },
    { type: 'section', title: 'Section makeups' },
];

const groups = computed(() =>
    GROUPS.map((group) => ({ ...group, units: props.units.filter((unit) => unit.type === group.type) })).filter((group) => group.units.length),
);
const labelOf = (key) => props.choices.find((choice) => choice.key === key)?.label ?? key;
</script>

<template>
    <div v-if="groups.length" class="mt-3 grid gap-2 text-xs text-muted-foreground" data-testid="accountability-under">
        <details v-for="group in groups" :key="group.type" :open="group.units.length <= 6" :data-testid="`accountability-under-${group.type}`">
            <summary class="cursor-pointer font-medium">{{ group.title }} ({{ group.units.length }})</summary>
            <ul class="mt-1 grid gap-1 pl-3">
                <li v-for="unit in group.units" :key="unit.id" :data-testid="`accountability-under-unit-${unit.id}`">
                    <Link :href="`/roster/${unit.id}?view=admin`" class="underline underline-offset-2 hover:text-foreground">{{ unit.label }}</Link>
                    <span>{{ unit.source === 'own' ? ' (set)' : ' (default)' }}: </span>
                    <span>{{ unit.options.map(labelOf).join(', ') || 'Nothing' }}</span>
                </li>
            </ul>
        </details>
    </div>
</template>
