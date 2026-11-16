<script setup>
import { computed } from 'vue';
import { PhInfo, PhWarning, PhWarningOctagon } from '@phosphor-icons/vue';

// The loud, top-of-page warning for something that quietly breaks the week until a leader fixes it (a section with no
// practice slot, a trooper with no game identity). Not dismissible: the page stops sending it once the cause is fixed.
// The message goes in the default slot, the fix (a button or link) in `action`.
//
// `level` says how urgent it is (16NOV26), in solid, bright colours so it is seen:
// - `critical` (red): it blocks the weekly report, or something is already late;
// - `high` (orange): something breaks until fixed, such as attendance not read for a trooper, but nothing is blocked;
// - `medium` (yellow): a heads-up.
const props = defineProps({
    level: { type: String, default: 'high', validator: (value) => ['critical', 'high', 'medium'].includes(value) },
});

const LOOKS = {
    critical: { box: 'border-alert-critical bg-alert-critical text-alert-critical-foreground', icon: PhWarningOctagon },
    high: { box: 'border-alert-high bg-alert-high text-alert-high-foreground', icon: PhWarning },
    medium: { box: 'border-alert-medium bg-alert-medium text-alert-medium-foreground', icon: PhInfo },
};
const look = computed(() => LOOKS[props.level] ?? LOOKS.high);
</script>

<template>
    <div
        role="alert"
        class="warning-banner flex flex-wrap items-start gap-x-3 gap-y-2 rounded-lg border px-4 py-3 text-[13px] shadow-sm"
        :class="look.box"
        :data-level="level"
    >
        <component :is="look.icon" class="mt-0.5 size-5 shrink-0" weight="fill" aria-hidden="true" />
        <div class="min-w-0 flex-1 basis-[14rem] space-y-1.5">
            <slot />
        </div>
        <slot name="action" />
    </div>
</template>

<style scoped>
/* On a solid colour, muted text and links take the banner's own colour, so nothing inside fades out. */
.warning-banner :deep(.text-muted-foreground) {
    color: inherit;
    opacity: 0.85;
}

.warning-banner :deep(a:not([data-slot='button'])),
.warning-banner :deep(.text-foreground),
.warning-banner :deep(button.underline) {
    color: inherit;
}
</style>
