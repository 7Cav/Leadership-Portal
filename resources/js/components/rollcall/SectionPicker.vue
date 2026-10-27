<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { PhCheck, PhCheckCircle, PhUsers } from '@phosphor-icons/vue';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { sectionStatus } from '@/lib/rollcall';

// Choose a section for the rollcall: every section in the viewer's scope (the server only sends
// those), grouped under its platoon, each with where its rollcall stands that week. Choosing one
// opens its rollcall for the same week.
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
        byPlatoon.get(key).push({ ...section, status: sectionStatus(section.progress) });
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
                    :value="`${section.designation} ${section.name} ${section.label ?? ''}`"
                    :data-section="section.id"
                    @select="pick(section)"
                >
                    <PhUsers class="text-muted-foreground" aria-hidden="true" />
                    <span class="font-medium">{{ section.designation }}</span>
                    <span v-if="section.name !== section.designation" class="text-muted-foreground">{{ section.name }}</span>
                    <span v-if="section.label" class="rounded-sm border border-border px-1 text-[10px] font-semibold tracking-wide text-muted-foreground" data-testid="section-label">{{ section.label }}</span>
                    <span
                        v-if="section.status"
                        class="ml-auto inline-flex items-center gap-1 text-xs whitespace-nowrap"
                        :class="section.status.state === 'done' ? 'text-success' : 'text-muted-foreground'"
                        :data-status="section.status.state"
                    >
                        <PhCheckCircle v-if="section.status.state === 'done'" aria-hidden="true" />{{ section.status.text }}
                    </span>
                    <PhCheck v-if="section.id === currentId" :class="section.status ? '' : 'ml-auto'" aria-label="Current section" />
                </CommandItem>
            </CommandGroup>
        </CommandList>
    </Command>
</template>
