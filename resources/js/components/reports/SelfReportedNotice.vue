<script setup>
import { PhInfo } from '@phosphor-icons/vue';
import { selfReportedText } from '@/lib/staffReport';

// One quiet notice for a game whose attendance can never be read automatically (its source is "None (impossible)"):
// it stands in for the "No game server data" alert each staff report would otherwise show. Shown once per page, on the
// staff report pages and on the unit weekly report and its roll-up panel.
defineProps({
    // The game's name.
    game: { type: String, required: true },
});
</script>

<template>
    <p role="status" class="flex items-start gap-2 rounded-lg border border-warning/35 bg-warning/8 px-3 py-2 text-xs" data-testid="self-reported-notice">
        <PhInfo class="mt-px size-3.5 shrink-0 text-warning" weight="fill" aria-hidden="true" />{{ selfReportedText(game) }}
    </p>
</template>
