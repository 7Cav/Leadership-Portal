<script setup>
import { computed } from 'vue';
import { PhInfo, PhWarning, PhWarningOctagon } from '@phosphor-icons/vue';

// The loud, top-of-page warning for something that quietly breaks the week until a leader fixes it (a section with no
// practice slot, a trooper with no game identity). Not dismissible: the page stops sending it once the cause is fixed.
// The message goes in the default slot, the fix (a button or link) in `action`.
//
// `level` says how urgent it is (16NOV26): the outline and the icon take a bright colour, the fill stays a muted tint so
// the text reads as on any card:
// - `critical` (red): it blocks the weekly report, or something is already late;
// - `high` (orange): something breaks until fixed, such as attendance not read for a trooper, but nothing is blocked;
// - `medium` (yellow): a heads-up.
const props = defineProps({
    level: { type: String, default: 'high', validator: (value) => ['critical', 'high', 'medium'].includes(value) },
});

const LOOKS = {
    critical: { box: 'border-alert-critical', glyph: 'text-alert-critical', icon: PhWarningOctagon },
    high: { box: 'border-alert-high', glyph: 'text-alert-high', icon: PhWarning },
    medium: { box: 'border-alert-medium', glyph: 'text-alert-medium', icon: PhInfo },
};
const look = computed(() => LOOKS[props.level] ?? LOOKS.high);
</script>

<template>
    <div
        role="alert"
        class="flex flex-wrap items-start gap-x-3 gap-y-2 rounded-lg border-[1.5px] bg-warning/10 px-4 py-3 text-[13px]"
        :class="look.box"
        :data-level="level"
    >
        <component :is="look.icon" class="mt-0.5 size-5 shrink-0" :class="look.glyph" weight="fill" aria-hidden="true" />
        <div class="min-w-0 flex-1 basis-[14rem] space-y-1.5">
            <slot />
        </div>
        <slot name="action" />
    </div>
</template>
