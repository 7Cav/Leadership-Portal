<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { PhUsersThree, PhWarning } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EmptyState from '@/components/EmptyState.vue';
import { forumDateTime } from '@/lib/forumDate';

// One section's roster (Story 2.8). `section` is null when the viewer has no section in scope.
// `sync` says where the data comes from and how current it is (AD-20): `lastSyncedAt` is the last
// successful run, and `stale` is set when the latest run since did not succeed, in which case the
// cached roster below is the last good one.
const props = defineProps({
    section: { type: Object, default: null },
    sections: { type: Array, required: true },
    troopers: { type: Array, required: true },
    sync: { type: Object, default: null },
});

// The roster's status codes, as the Cav rosters name them. `inactive` is a trooper who has left every
// Cav roster: shown as departed, never silently dropped.
const STATUS = {
    combat: { label: 'Combat', variant: 'success' },
    reserve: { label: 'Reserve', variant: 'info' },
    eloa: { label: 'ELOA', variant: 'warning' },
    inactive: { label: 'Departed', variant: 'danger' },
};

const statusOf = (trooper) => STATUS[trooper.status] ?? { label: trooper.status, variant: 'outline' };
const isDeparted = (trooper) => trooper.status === 'inactive';
const displayName = (trooper) => trooper.username ?? `Unknown (Cav #${trooper.cav_id})`;
const sectionLabel = (section) => (section.callsign ? `${section.callsign} · ${section.name}` : section.name);

const activeCount = computed(() => props.troopers.filter((trooper) => !isDeparted(trooper)).length);
const departedCount = computed(() => props.troopers.length - activeCount.value);

// Why the roster may not be current, in words: a failed or partial latest run shows the last good cache.
const staleNotice = computed(() => {
    if (!props.sync) return null;
    if (props.sync.lastSyncedAt === null) {
        return props.sync.latestStatus === null
            ? 'The Cav roster has not been synced yet, so this list may be empty or out of date until the first sync runs.'
            : 'No Cav roster sync has succeeded yet, so this list may be empty or out of date.';
    }
    if (!props.sync.stale) return null;

    const outcome = props.sync.latestStatus === 'partial' ? 'was only partly applied' : 'failed';

    return `The latest Cav roster sync ${outcome} on ${forumDateTime(props.sync.latestFinishedAt)} UTC. This is the roster from the last successful sync.`;
});

function openSection(event) {
    router.get(`/roster/${event.target.value}`);
}
</script>

<template>
    <Head :title="section ? `${section.name} roster` : 'Roster'" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-6 sm:px-6">
        <EmptyState v-if="!section" :icon="PhUsersThree" title="No section in your scope yet" class="py-16">
            A leader's scope comes from their Cav billet or from a staff grant an Admin gives them. Once it covers a section, its roster opens here.
        </EmptyState>

        <template v-else>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ section.name }}
                        <span v-if="section.callsign" class="ml-1.5 font-mono text-sm font-normal text-muted-foreground">{{ section.callsign }}</span>
                    </h1>
                    <p data-testid="source-line" class="mt-1 text-[13px] text-muted-foreground">
                        Source: {{ sync?.source ?? 'Cav roster' }}
                        <template v-if="sync?.lastSyncedAt">
                            · last synced <span data-testid="last-synced">{{ forumDateTime(sync.lastSyncedAt) }} UTC</span>
                        </template>
                        <template v-else> · never synced</template>
                    </p>
                </div>

                <div v-if="sections.length > 1" class="grid gap-1">
                    <Label for="section-switcher" class="text-xs text-muted-foreground">Section</Label>
                    <NativeSelect id="section-switcher" data-testid="section-switcher" :model-value="section.id" class="min-w-56" @change="openSection">
                        <option v-for="option in sections" :key="option.id" :value="option.id">{{ sectionLabel(option) }}</option>
                    </NativeSelect>
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

            <Card class="gap-0 py-0">
                <div class="flex items-center gap-2 px-4 pt-3.5 pb-2">
                    <h2 class="text-[15px] font-semibold">Troopers</h2>
                    <span data-testid="counts" class="truncate text-xs text-muted-foreground">
                        {{ activeCount }} {{ activeCount === 1 ? 'trooper' : 'troopers' }}<template v-if="departedCount">, {{ departedCount }} departed</template>
                    </span>
                </div>

                <Table v-if="troopers.length" class="text-[13px]">
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Rank</TableHead>
                            <TableHead class="h-8 text-xs font-medium text-muted-foreground">Name</TableHead>
                            <TableHead class="h-8 text-xs font-medium text-muted-foreground">MOS</TableHead>
                            <TableHead class="h-8 pr-4 text-xs font-medium text-muted-foreground">Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="trooper in troopers" :key="trooper.id" data-testid="trooper-row" :class="{ 'text-muted-foreground': isDeparted(trooper) }">
                            <TableCell class="px-4">{{ trooper.rank ?? 'None' }}</TableCell>
                            <TableCell class="font-medium">
                                {{ displayName(trooper) }}
                                <Badge v-if="trooper.placed_by_hand" variant="outline" class="ml-1.5" data-testid="by-hand">Placed by hand</Badge>
                            </TableCell>
                            <TableCell class="font-mono text-xs">{{ trooper.mos ?? 'None' }}</TableCell>
                            <TableCell class="pr-4">
                                <Badge :variant="statusOf(trooper).variant" data-testid="status">{{ statusOf(trooper).label }}</Badge>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <EmptyState v-else :icon="PhUsersThree" title="No troopers placed here yet" class="border-t border-border py-10">
                    Troopers appear once the Cav roster sync maps them to this section, or an Admin places them by hand.
                </EmptyState>
            </Card>
        </template>
    </div>
</template>
