<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { PhCheck, PhUsers } from '@phosphor-icons/vue';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';

// Choose a section for the rollcall: every section in the viewer's scope (the server only sends
// those), grouped under its platoon. Choosing one opens its rollcall for the same week.
const props = defineProps({
    sections: { type: Array, required: true },
    currentId: { type: Number, default: null },
    // `YYYY-MM-DD` of the week being viewed; omitted for the current week.
    week: { type: String, default: null },
});

const emit = defineEmits(['picked']);

const groups = computed(() => {
    const byPlatoon = new Map();
    for (const section of props.sections) {
        const key = section.platoon ?? 'No platoon';
        if (!byPlatoon.has(key)) byPlatoon.set(key, []);
        byPlatoon.get(key).push(section);
    }

    return [...byPlatoon].map(([platoon, sections]) => ({ platoon, sections }));
});

function pick(section) {
    emit('picked', section);
    router.visit(`/rollcall/${section.id}${props.week ? `?week=${props.week}` : ''}`);
}
</script>

<template>
    <Command class="rounded-lg border border-border bg-card">
        <CommandInput placeholder="Find a section" aria-label="Find a section" />
        <CommandList class="max-h-[22rem]">
            <CommandEmpty>No section in your scope matches.</CommandEmpty>
            <CommandGroup v-for="group in groups" :key="group.platoon" :heading="group.platoon">
                <CommandItem
                    v-for="section in group.sections"
                    :key="section.id"
                    :value="`${section.designation} ${section.name}`"
                    :data-section="section.id"
                    @select="pick(section)"
                >
                    <PhUsers class="text-muted-foreground" aria-hidden="true" />
                    <span class="font-medium">{{ section.designation }}</span>
                    <span v-if="section.name !== section.designation" class="text-muted-foreground">{{ section.name }}</span>
                    <PhCheck v-if="section.id === currentId" class="ml-auto" aria-label="Current section" />
                </CommandItem>
            </CommandGroup>
        </CommandList>
    </Command>
</template>
