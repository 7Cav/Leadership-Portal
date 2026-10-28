<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { PhCheck, PhCheckCircle } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { useStartHere } from '@/composables/useStartHere';

// "Done with this guide?" at the foot of a guide that is on the reader's Start here list (#412). Ticks the same item
// the card in the right rail shows, through the same store.
const props = defineProps({
    // The guide's Start here item id (`section/slug`).
    id: { type: String, required: true },
    checklist: { type: Array, required: true },
});

const page = usePage();
const startHere = useStartHere(
    () => props.checklist,
    () => ({ userId: page.props.auth?.user?.id ?? null, previewing: page.props.viewAs != null }),
);

const done = computed(() => startHere.isDone(props.id));
</script>

<template>
    <div class="mt-7 flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card px-4 py-3.5" data-testid="tick-it-off">
        <PhCheckCircle class="size-[22px] text-success" aria-hidden="true" />
        <span class="flex-1 text-[13.5px]"><b class="font-semibold">Done with this guide?</b> Tick it off your Start here list.</span>
        <Button
            variant="outline"
            :aria-pressed="done"
            :class="done ? 'border-success bg-success/14 text-success hover:bg-success/20 hover:text-success' : ''"
            @click="startHere.setDone(id, !done)"
        >
            <template v-if="done"><PhCheck weight="bold" />Ticked off</template>
            <template v-else>Tick it off</template>
        </Button>
    </div>
</template>
