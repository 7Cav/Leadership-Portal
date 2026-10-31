<script setup>
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { COUNSELING_TYPES, consequenceBadges } from '@/lib/counseling';

// What the leader wrote on a record, read-only (#565): the trooper's "What your leader wrote" and the chain's view of a
// shared record. With `trooperPart`, the trooper's answer follows when they gave one. Every value is plain text.
const props = defineProps({
    record: { type: Object, required: true },
    title: { type: String, default: 'What the leader wrote' },
    trooperPart: { type: Boolean, default: false },
});

const form = computed(() => props.record.form ?? {});
const authority = computed(() => (COUNSELING_TYPES[props.record.type] ?? COUNSELING_TYPES.dcs).authority.toLowerCase());
const text = (key) => (typeof form.value[key] === 'string' ? form.value[key].trim() : '');

const leader = computed(() =>
    [
        { key: 'infraction', label: 'Evidenced infraction' },
        { key: 'regulation', label: 'Regulation / SOP' },
        { key: 'discussion', label: 'Notes of discussion' },
        { key: 'plan_authority', label: `Plan of action: ${authority.value}` },
        { key: 'plan_other', label: 'Plan of action: misc / others' },
        { key: 'comments_authority', label: `Comments: ${authority.value}` },
        { key: 'comments_other', label: 'Comments: misc / others' },
        { key: 'special_punishments', label: 'Special punishments ordered' },
    ].filter((row) => text(row.key)),
);
const badges = computed(() => (props.record.type === 'lor' ? consequenceBadges(form.value) : []));
const trooper = computed(() =>
    [
        { key: 'plan_trooper', label: 'Plan of action: trooper' },
        { key: 'comments_trooper', label: 'Comments: trooper' },
    ].filter((row) => text(row.key)),
);
const appeal = computed(() => ({ do: 'Wishes to appeal', do_not: 'Does not wish to appeal' })[form.value.appeal] ?? null);
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-border bg-card" :aria-label="title" data-testid="counseling-letter">
        <h2 class="border-b border-border px-4 py-3 text-[15px] font-semibold">{{ title }}</h2>
        <dl>
            <div v-for="row in leader" :key="row.key" class="border-b border-border px-4 py-3 last:border-b-0" :data-testid="`letter-${row.key}`">
                <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">{{ row.label }}</dt>
                <dd class="mt-1 text-[13px] whitespace-pre-line">{{ text(row.key) }}</dd>
            </div>
            <div v-if="badges.length" class="border-b border-border px-4 py-3 last:border-b-0" data-testid="letter-consequences">
                <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Recommended consequence</dt>
                <dd class="mt-1 flex flex-wrap gap-1.5"><Badge v-for="badge in badges" :key="badge" variant="warning">{{ badge }}</Badge></dd>
            </div>
            <template v-if="trooperPart">
                <div v-for="row in trooper" :key="row.key" class="border-b border-border px-4 py-3 last:border-b-0" :data-testid="`letter-${row.key}`">
                    <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">{{ row.label }}</dt>
                    <dd class="mt-1 text-[13px] whitespace-pre-line">{{ text(row.key) }}</dd>
                </div>
                <div v-if="appeal" class="px-4 py-3" data-testid="letter-appeal">
                    <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Appeal</dt>
                    <dd class="mt-1 text-[13px]">{{ appeal }}</dd>
                </div>
            </template>
            <p v-if="!leader.length && !badges.length" class="px-4 py-3 text-[13px] text-muted-foreground">Nothing written yet.</p>
        </dl>
    </section>
</template>
