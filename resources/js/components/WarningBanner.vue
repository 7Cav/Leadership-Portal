<script setup>
import { PhWarning } from '@phosphor-icons/vue';

// The loud, top-of-page warning for something that quietly breaks the week until a leader fixes it (a section with no
// practice slot, a trooper with no game identity). Not dismissible: the page stops sending it once the cause is fixed.
// The message goes in the default slot, the fix (a button or link) in `action`.
</script>

<template>
    <div role="alert" class="flex flex-wrap items-start gap-x-3 gap-y-2 rounded-lg border border-warning/60 bg-warning/12 px-4 py-3 text-[13px]">
        <PhWarning class="mt-0.5 size-5 shrink-0 text-warning" weight="fill" aria-hidden="true" />
        <div class="min-w-0 flex-1 basis-[14rem] space-y-1.5">
            <slot />
        </div>
        <slot name="action" />
    </div>
</template>
