<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhArrowLeft, PhEnvelopeSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { COUNSELING_TYPES, recordBadge } from '@/lib/counseling';

// The top of a counseling record's page (#565): the way back to the trooper, what the record is and where it stands,
// and the page's actions on the right (the `actions` slot).
const props = defineProps({
    record: { type: Object, required: true },
    trooper: { type: Object, required: true },
    // A line above the title ("Shared with you 29 Oct 2214Z"), or null.
    eyebrow: { type: String, default: null },
    // Replaces the trooper line under the title, as the trooper's own view words it.
    subtitle: { type: String, default: null },
    // Off on the trooper's own view: their page is not theirs to go back to from here.
    back: { type: Boolean, default: true },
});

const badge = computed(() => recordBadge(props.record));
const form = computed(() => COUNSELING_TYPES[props.record.type]?.form);
const invited = computed(() => ['draft', 'invited'].includes(props.record.state) && props.record.meeting?.invitedAt);
const line = computed(
    () => props.subtitle ?? [[props.trooper.rank, props.trooper.name].filter(Boolean).join(' '), props.trooper.billet, props.record.kindLabel, form.value].filter(Boolean).join(' · '),
);
</script>

<template>
    <header class="flex flex-wrap items-end justify-between gap-3" data-testid="counseling-header">
        <div class="min-w-0">
            <Link v-if="back" :href="`/troopers/${trooper.id}`" class="mb-2 inline-flex items-center gap-1 text-[13px] text-muted-foreground hover:text-foreground" data-testid="back-to-trooper">
                <PhArrowLeft class="size-3.5" aria-hidden="true" />Back to {{ trooper.name }}
            </Link>
            <p v-if="eyebrow" class="text-xs text-muted-foreground" data-testid="counseling-eyebrow">{{ eyebrow }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight">{{ record.typeLabel }}</h1>
                <Badge :variant="badge.tone" data-testid="counseling-state">{{ badge.label }}</Badge>
                <Badge v-if="invited" variant="info" data-testid="counseling-invited"><PhEnvelopeSimple aria-hidden="true" />Invite sent</Badge>
            </div>
            <p class="mt-1 text-[13px] text-muted-foreground" data-testid="counseling-subtitle">{{ line }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
