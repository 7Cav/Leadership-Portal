<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { PhArrowSquareOut, PhWarning } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { forumDate, forumDateTime } from '@/lib/forumDate';

// One trooper's milpac (Story 6.3): the ID block from the cached Cav roster. Everything is shaped on the server (`Milpac\TrooperMilpac`) and drawn here; the
// page decides nothing about who may look, the controller has already gated it. The service record that
// completes the mockup's second column arrives with 6.6.
const props = defineProps({
    trooper: { type: Object, required: true },
    sync: { type: Object, required: true },
});

const STATUS = {
    combat: { label: 'Active', variant: 'success' },
    reserve: { label: 'Reserve', variant: 'info' },
    eloa: { label: 'ELOA', variant: 'warning' },
    inactive: { label: 'Departed', variant: 'danger' },
};

const status = computed(() => STATUS[props.trooper.status] ?? { label: props.trooper.status, variant: 'outline' });
// The avatar's letters, as the mockup draws them: the surname's first letter and the given initial ("Milkovic.D"
// is MD). A name with no dot takes its first two letters.
const initials = computed(() => {
    const name = props.trooper.username ?? '';
    const [surname, given] = name.split('.');
    const letters = given ? `${surname[0] ?? ''}${given[0]}` : name.slice(0, 2);

    return letters.replace(/[^A-Za-z]/g, '').toUpperCase() || '?';
});
const sectionLabel = computed(() => {
    const section = props.trooper.section;
    if (!section) return null;

    return section.callsign ? `${section.callsign} ${section.name}` : section.name;
});

// "2y 3m", "7m", or "under a month": how long, from a whole-month count.
function duration(months) {
    if (months === null || months === undefined) return 'Unknown';
    if (months < 1) return 'Under a month';

    const years = Math.floor(months / 12);
    const rest = months % 12;

    return [years ? `${years}y` : null, rest ? `${rest}m` : null].filter(Boolean).join(' ');
}

// Why the ID block may not be current: a run since the last good one did not succeed.
const staleNotice = computed(() => {
    if (props.sync.lastSuccessAt === null) {
        return 'No Cav roster sync has succeeded yet, so this record may be empty or out of date.';
    }
    if (!props.sync.stale) return null;

    const outcome = props.sync.lastAttempt?.status === 'partial' ? 'was only partly applied' : 'failed';
    const at = props.sync.lastAttempt?.at ? ` on ${forumDateTime(props.sync.lastAttempt.at)} UTC` : '';

    return `The latest Cav roster sync ${outcome}${at}. This is the record from the last successful sync.`;
});
</script>

<template>
    <Head :title="trooper.displayName" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-6 sm:px-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight" data-testid="trooper-name">{{ trooper.displayName }}</h1>
                <p class="mt-1 text-[13px] text-muted-foreground" data-testid="trooper-subtitle">
                    <template v-if="trooper.billet">{{ trooper.billet }}</template>
                    <template v-else>No billet on record</template>
                    <template v-if="sectionLabel"> · {{ sectionLabel }}</template>
                    · Profile #{{ trooper.cavId }}
                    ·
                    <a
                        :href="trooper.milpacUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 text-primary hover:underline"
                        data-testid="milpac-link"
                    >
                        view on 7cav.us <PhArrowSquareOut class="size-3" aria-hidden="true" /><span class="sr-only">(opens in a new tab)</span>
                    </a>
                </p>
                <p data-testid="source-line" class="mt-1 text-[13px] text-muted-foreground">
                    Source: {{ sync.source }}
                    <template v-if="sync.lastSuccessAt">
                        · last synced <span data-testid="last-synced">{{ forumDateTime(sync.lastSuccessAt) }} UTC</span>
                    </template>
                    <template v-else> · never synced</template>
                </p>
            </div>
        </div>

        <p
            v-if="staleNotice"
            data-testid="stale-notice"
            role="status"
            class="flex items-start gap-2 rounded-lg border border-warning/35 bg-warning/8 px-3.5 py-2.5 text-[13px]"
        >
            <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
            <span>{{ staleNotice }}</span>
        </p>

        <div class="grid items-start gap-5 lg:grid-cols-[20rem_1fr]">
            <div class="flex flex-col gap-5">
                <Card class="gap-0 overflow-hidden py-0" data-testid="id-block">
                    <div class="flex flex-col items-center gap-1 px-5 pt-6 pb-5 text-center">
                        <div
                            class="mb-2 flex size-16 items-center justify-center rounded-full border-2 border-primary text-lg font-semibold text-primary"
                            aria-hidden="true"
                        >
                            {{ initials }}
                        </div>
                        <h2 class="text-base font-semibold">{{ trooper.displayName }}</h2>
                        <p class="text-[13px] text-primary" data-testid="billet">{{ trooper.billet ?? 'No billet on record' }}</p>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="trooper.tis">Joined <span data-testid="joined">{{ forumDate(trooper.tis) }}</span> · </template>
                            MOS <span data-testid="mos">{{ trooper.mos ?? 'None' }}</span>
                        </p>
                        <Badge :variant="status.variant" class="mt-2" data-testid="status">{{ status.label }}</Badge>
                    </div>

                    <dl class="grid grid-cols-2 border-t border-border text-[13px]">
                        <div class="flex flex-col gap-0.5 border-r border-border px-4 py-3">
                            <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Time in service</dt>
                            <dd class="font-medium tabular-nums" data-testid="tis">{{ duration(trooper.tisMonths) }}</dd>
                        </div>
                        <div class="flex flex-col gap-0.5 px-4 py-3">
                            <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Time in grade</dt>
                            <dd class="font-medium tabular-nums" data-testid="tig">{{ duration(trooper.tigMonths) }}</dd>
                        </div>
                        <div class="col-span-2 flex flex-col gap-0.5 border-t border-border px-4 py-3">
                            <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Rank</dt>
                            <dd class="font-medium" data-testid="rank">{{ trooper.rank ?? 'None' }}</dd>
                        </div>
                        <div class="col-span-2 flex flex-col gap-0.5 border-t border-border px-4 py-3">
                            <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Primary section</dt>
                            <dd class="font-medium" data-testid="section">
                                <template v-if="sectionLabel">{{ sectionLabel }}</template>
                                <span v-else class="text-muted-foreground" data-testid="unmapped">Unmapped: not placed in a section yet</span>
                            </dd>
                        </div>
                    </dl>
                </Card>
            </div>
        </div>
    </div>
</template>
