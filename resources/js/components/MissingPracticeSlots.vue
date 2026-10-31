<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import WarningBanner from '@/components/WarningBanner.vue';

// The warning for sections that play a game and have troopers but no practice slot (#392): without one their practice
// is never captured and nobody gets an automatic make-up there. Home and the roster; each callsign opens its roll call,
// where the slot is set. Gone once every section has a slot.
const props = defineProps({
    // `[{ id, designation }]`, in tree order; empty when every section has a slot.
    sections: { type: Array, default: () => [] },
    // "of your sections" on Home; the roster speaks of the unit it shows.
    own: { type: Boolean, default: true },
});

const INLINE = 3;
const expanded = ref(false);

const shown = computed(() => (expanded.value ? props.sections : props.sections.slice(0, INLINE)));

const headline = computed(() => {
    const n = props.sections.length;
    if (n === 1) return props.own ? '1 of your sections has no practice slot set' : '1 section has no practice slot set';

    return props.own ? `${n} of your sections have no practice slot set` : `${n} sections have no practice slot set`;
});
</script>

<template>
    <WarningBanner v-if="sections.length" data-testid="missing-slots-banner">
        <p class="font-semibold">{{ headline }}</p>
        <p class="text-muted-foreground">
            <template v-for="(section, i) in shown" :key="section.id">
                <template v-if="i">, </template>
                <Link :href="`/rollcall/${section.id}`" class="font-medium text-foreground underline underline-offset-2" :data-testid="`missing-slot-${section.id}`">{{ section.designation }}</Link>
            </template>
            <button
                v-if="sections.length > INLINE"
                type="button"
                class="ml-1 font-medium text-foreground underline underline-offset-2"
                :aria-expanded="expanded"
                data-testid="missing-slots-toggle"
                @click="expanded = !expanded"
            >
                {{ expanded ? 'Show fewer' : `Show all ${sections.length}` }}
            </button>
        </p>
        <p>
            Without a slot, {{ sections.length === 1 ? "its practices aren't" : "their practices aren't" }} captured automatically and make-ups aren't given from the game
            server.
        </p>
        <template #action>
            <Button size="sm" variant="outline" class="w-full sm:w-auto" as-child>
                <Link :href="`/rollcall/${sections[0].id}`" data-testid="missing-slots-link">Set it on the section's roll call</Link>
            </Button>
        </template>
    </WarningBanner>
</template>
