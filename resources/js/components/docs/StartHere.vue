<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { PhCaretDown, PhCheckCircle } from '@phosphor-icons/vue';
import { Checkbox } from '@/components/ui/checkbox';
import { useStartHere } from '@/composables/useStartHere';
import { roleLabel } from '@/lib/docs';

// The "Start here" card (#412, agreed design): the few things to do in a first week for the viewer's role, ticked off
// by hand. Sits at the top of the right rail on docs home and every guide. Progress is kept by useStartHere.
const props = defineProps({
    // `[{ id, label, url }]`: already only the items for this viewer's audience.
    checklist: { type: Array, required: true },
    audience: { type: String, required: true },
    // The open guide's URL, to mark its item.
    current: { type: String, default: null },
});

const page = usePage();
const startHere = useStartHere(
    () => props.checklist,
    () => ({ userId: page.props.auth?.user?.id ?? null, previewing: page.props.viewAs != null }),
);

const percent = computed(() => (startHere.total.value ? Math.round((startHere.done.value / startHere.total.value) * 100) : 0));
</script>

<template>
    <section
        v-if="checklist.length"
        class="rounded-lg border border-border bg-card px-3.5 pt-3.5"
        :class="startHere.collapsed.value ? 'pb-3' : 'pb-1.5'"
        aria-labelledby="start-here-title"
        data-testid="start-here"
    >
        <div class="flex items-center gap-2.5">
            <h2 id="start-here-title" class="text-sm font-semibold tracking-tight">Start here</h2>
            <span class="ml-auto text-[12.5px] text-muted-foreground tabular-nums" data-testid="start-here-count">
                {{ startHere.done.value }} of {{ startHere.total.value }}
            </span>
            <button
                type="button"
                class="grid size-6.5 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                :aria-expanded="!startHere.collapsed.value"
                aria-controls="start-here-body"
                :aria-label="startHere.collapsed.value ? 'Expand checklist' : 'Collapse checklist'"
                data-testid="start-here-toggle"
                @click="startHere.toggleCollapsed()"
            >
                <PhCaretDown weight="bold" class="transition-transform" :class="startHere.collapsed.value ? '-rotate-90' : ''" />
            </button>
        </div>

        <div v-show="!startHere.collapsed.value" id="start-here-body">
            <p class="mt-0.5 text-[12.5px] text-muted-foreground">
                First week as {{ roleLabel(audience) }}.
                <template v-if="page.props.viewAs">Ticks are not saved during a preview.</template>
                <template v-else>Saved on this device.</template>
            </p>
            <div
                class="mt-2.5 mb-1.5 h-[5px] overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="startHere.done.value"
                aria-valuemin="0"
                :aria-valuemax="startHere.total.value"
                :aria-valuetext="`${startHere.done.value} of ${startHere.total.value} done`"
                aria-label="Start here progress"
            >
                <span class="block h-full bg-primary transition-[width] duration-300" :style="{ width: `${percent}%` }" />
            </div>
            <p v-if="startHere.complete.value" class="mt-0.5 mb-2.5 flex items-center gap-1.5 text-[13px] font-semibold text-success" role="status">
                <PhCheckCircle weight="fill" aria-hidden="true" />Every item is done.
            </p>
            <ul data-testid="start-here-items">
                <li v-for="item in startHere.items.value" :key="item.id" class="flex items-start gap-2.5 border-t border-border py-2">
                    <Checkbox
                        :id="`start-here-${item.id}`"
                        class="mt-0.5 size-[18px]"
                        :model-value="startHere.isDone(item.id)"
                        :aria-label="`Done: ${item.label}`"
                        @update:model-value="(value) => startHere.setDone(item.id, value === true)"
                    />
                    <Link
                        :href="item.url"
                        class="flex-1 text-[13px] leading-snug hover:underline"
                        :class="[startHere.isDone(item.id) ? 'text-muted-foreground line-through' : '', item.url === current ? 'font-semibold' : '']"
                        :aria-current="item.url === current ? 'page' : undefined"
                    >
                        {{ item.label }}<span v-if="startHere.isDone(item.id)" class="sr-only"> (done)</span>
                    </Link>
                </li>
            </ul>
        </div>
    </section>
</template>
