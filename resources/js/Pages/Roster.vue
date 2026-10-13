<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import {
    PhArrowsClockwise,
    PhCaretRight,
    PhCaretUpDown,
    PhCheck,
    PhGridFour,
    PhListBullets,
    PhLockSimple,
    PhUsersThree,
    PhWarning,
} from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import EmptyState from '@/components/EmptyState.vue';
import MatrixView from '@/components/roster/MatrixView.vue';
import OrbatView from '@/components/roster/OrbatView.vue';
import TrooperSheet from '@/components/roster/TrooperSheet.vue';
import { useViewAs } from '@/composables/useViewAs';
import { forumDate } from '@/lib/forumDate';
import { percentLabel } from '@/lib/roster';

// The roster (Stories 2.8, 4.6, 4.7, 4.9). One page, two views of the same subtree, toggled and
// kept in the URL (`?view=`): the order of battle (binding mockup roster-s2.html) and the
// attendance matrix (roster-s3.html). Both open the same trooper Sheet. Every number on it comes
// from RosterController, which reads the shared KPI service; nothing here re-derives a KPI.
const props = defineProps({
    unit: { type: Object, default: null },
    trail: { type: Array, required: true },
    scopeOptions: { type: Array, required: true },
    weeks: { type: Array, required: true },
    currentWeek: { type: String, required: true },
    units: { type: Array, required: true },
    troopers: { type: Array, required: true },
    sync: { type: Object, required: true },
    can: { type: Object, required: true },
    links: { type: Object, required: true },
});

const page = usePage();
const VIEWS = ['orbat', 'matrix'];

function queryParam(name) {
    const url = page.url ?? '';
    const search = url.includes('?') ? url.slice(url.indexOf('?')) : '';

    return new URLSearchParams(search).get(name);
}

const view = ref(VIEWS.includes(queryParam('view')) ? queryParam('view') : 'orbat');
const query = ref('');
const filter = ref('all');
const openTrooperId = ref(Number(queryParam('trooper')) || null);

// The view and the open trooper live in the URL so a link or a reload lands on the same screen.
// A client-side replace: switching views needs no new data from the server.
function syncUrl() {
    const url = new URL(page.url, window.location.origin);
    url.searchParams.set('view', view.value);
    if (openTrooperId.value) url.searchParams.set('trooper', openTrooperId.value);
    else url.searchParams.delete('trooper');
    router.replace({ url: `${url.pathname}${url.search}`, preserveState: true, preserveScroll: true });
}
watch([view, openTrooperId], syncUrl);

const root = computed(() => props.units[0] ?? null);
const strength = computed(() => root.value?.kpis.strength ?? 0);
const sectionCount = computed(() => props.units.filter((u) => u.type === 'section').length);
const platoonCount = computed(() => props.units.filter((u) => u.type === 'platoon').length);

const composition = computed(() => {
    if (!props.unit || props.unit.type === 'section') return null;
    const parts = [];
    if (platoonCount.value && props.unit.type !== 'platoon') parts.push(`${platoonCount.value} platoon${platoonCount.value === 1 ? '' : 's'}`);
    parts.push(`${sectionCount.value} section${sectionCount.value === 1 ? '' : 's'}`);

    return `in ${parts.join(' and ')}`;
});

// Unit navigation: a real visit, since another unit means another subtree of data.
const navigating = ref(false);
let offStart;
let offFinish;
onMounted(() => {
    offStart = router.on('start', (event) => {
        if (event.detail.visit.url.pathname.startsWith('/roster')) navigating.value = true;
    });
    offFinish = router.on('finish', () => (navigating.value = false));
});
onBeforeUnmount(() => {
    offStart?.();
    offFinish?.();
});

function unitHref(id) {
    return `/roster/${id}?view=${view.value}`;
}

// Source and freshness (AD-20): the time is always the last successful sync.
function clock(iso) {
    const at = new Date(iso);
    const hhmm = `${String(at.getUTCHours()).padStart(2, '0')}:${String(at.getUTCMinutes()).padStart(2, '0')}`;
    const today = new Date().toISOString().slice(0, 10) === iso.slice(0, 10);

    return today ? `${hhmm}Z` : `${forumDate(iso)} ${hhmm}Z`;
}
const syncLabel = computed(() =>
    props.sync.lastSuccessAt ? `${props.sync.source}, synced ${clock(props.sync.lastSuccessAt)}` : `${props.sync.source}, never synced`,
);
const syncDetail = computed(() => {
    const lines = ['Ranks, billets and placements come from milpac and refresh on every roster sync.'];
    if (props.sync.stale && props.sync.lastAttempt) {
        lines.push(
            `The last attempt (${clock(props.sync.lastAttempt.at)}) ${props.sync.lastAttempt.status === 'partial' ? 'skipped some rows' : 'failed'}; this is the last good copy.`,
        );
    }

    return lines.join(' ');
});

// While previewing someone else (1.13) the server refuses every write, so the control says so up
// front instead of failing on click.
const { active: viewAs, subject: viewAsSubject } = useViewAs();

const syncing = ref(false);
function syncNow() {
    if (viewAs.value || syncing.value) return;
    syncing.value = true;
    // An Admin refreshes the whole roster; a leader refreshes only the echelon on screen.
    router.post(
        props.can.sync ? '/settings/roster-sync' : `/units/${props.unit.id}/roster-sync`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success('Roster sync started', { description: 'Reload in a minute to see the refreshed roster.' }),
            // A failed request is an HTTP exception or a network error, never a validation error, so
            // each gets its own message instead of Inertia's raw error dialog (returning false).
            onHttpException: (response) => {
                toast.error(
                    response.status === 403
                        ? 'Only an Admin or this unit\'s leader can start a roster sync.'
                        : response.status === 419
                          ? 'Your session expired. Reload the page and try again.'
                          : `The sync could not be started (error ${response.status}). Try again.`,
                );

                return false;
            },
            onNetworkError: () => {
                toast.error('Could not reach the portal. Check your connection and try again.');

                return false;
            },
            onFinish: () => (syncing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="unit ? `Roster ${unit.callsign ?? unit.name}` : 'Roster'" />

    <div class="space-y-4">
        <section class="flex flex-wrap items-end gap-x-6 gap-y-3" aria-labelledby="roster-title">
            <div class="min-w-0">
                <nav v-if="trail.length > 1" aria-label="Unit" class="mb-0.5">
                    <ol class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                        <li v-for="(crumb, index) in trail.slice(0, -1)" :key="crumb.id" class="inline-flex items-center gap-1">
                            <Link v-if="crumb.inScope" :href="unitHref(crumb.id)" class="hover:text-foreground">{{ crumb.callsign ?? crumb.name }}</Link>
                            <span v-else>{{ crumb.callsign ?? crumb.name }}</span>
                            <PhCaretRight v-if="index < trail.length - 2" class="size-3" aria-hidden="true" />
                        </li>
                    </ol>
                </nav>
                <h1 id="roster-title" class="text-xl font-semibold tracking-tight sm:text-[22px]">
                    Roster<span v-if="unit" class="text-muted-foreground">{{ ' ' }}{{ unit.callsign ?? unit.name }}</span>
                </h1>
                <p v-if="unit" class="mt-0.5 text-[13px] text-muted-foreground" data-testid="scope-line">
                    {{ unit.name }}.
                    <template v-if="strength">
                        <span class="tabular-nums">{{ strength }}</span> trooper{{ strength === 1 ? '' : 's' }}<template v-if="composition">{{ ' ' }}{{ composition }}</template>.
                        RC 30d <span class="font-medium text-foreground tabular-nums">{{ percentLabel(root.kpis.rc) }}</span>, SP 30d
                        <span class="font-medium text-foreground tabular-nums">{{ percentLabel(root.kpis.sp) }}</span>.
                    </template>
                    <template v-else>No troopers assigned yet.</template>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                <DropdownMenu v-if="scopeOptions.length">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" data-testid="roster-scope" :aria-label="`Unit: ${unit?.callsign ?? unit?.name}. Change unit`">
                            <PhUsersThree />
                            <span class="max-w-40 truncate">{{ unit?.callsign ?? unit?.name ?? 'Pick a unit' }}</span>
                            <PhCaretUpDown class="text-muted-foreground" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="max-h-[min(28rem,calc(100dvh-6rem))] w-72 overflow-y-auto">
                        <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Show the roster for</DropdownMenuLabel>
                        <DropdownMenuItem v-for="option in scopeOptions" :key="option.id" as-child>
                            <Link
                                :href="unitHref(option.id)"
                                class="gap-2"
                                :style="{ paddingLeft: `${0.5 + Math.min(option.depth - scopeOptions[0].depth, 4) * 0.75}rem` }"
                                :aria-current="option.id === unit?.id ? 'true' : undefined"
                                :data-testid="`scope-option-${option.id}`"
                            >
                                <span class="font-medium">{{ option.callsign ?? option.name }}</span>
                                <span class="truncate text-muted-foreground">{{ option.name }}</span>
                                <PhCheck v-if="option.id === unit?.id" class="ml-auto" aria-label="Current unit" />
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <Tooltip>
                    <TooltipTrigger as-child>
                        <Badge
                            :variant="sync.stale ? 'warning' : 'outline'"
                            tabindex="0"
                            class="h-7 px-2.5 text-xs"
                            data-testid="roster-sync-stamp"
                        >
                            <PhWarning v-if="sync.stale" />
                            <PhArrowsClockwise v-else />
                            {{ syncLabel }}<template v-if="sync.stale">, {{ sync.lastAttempt?.status === 'partial' ? 'last attempt incomplete' : 'last attempt failed' }}</template>
                        </Badge>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-72">{{ syncDetail }}</TooltipContent>
                </Tooltip>

                <template v-if="can.sync || can.syncUnit">
                    <Tooltip v-if="viewAs">
                        <TooltipTrigger as-child>
                            <Button variant="outline" size="sm" aria-disabled="true" class="cursor-not-allowed opacity-60" @click.prevent>
                                <PhLockSimple />Sync now
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Read-only while viewing as {{ viewAsSubject }}</TooltipContent>
                    </Tooltip>
                    <Button v-else variant="outline" size="sm" :disabled="syncing" data-testid="roster-sync-now" @click="syncNow">
                        <PhArrowsClockwise :class="syncing && 'motion-safe:animate-spin'" />{{ syncing ? 'Starting sync' : can.sync ? 'Sync now' : 'Sync this unit' }}
                    </Button>
                </template>
            </div>
        </section>

        <section v-if="!unit" class="rounded-[10px] border border-border bg-card py-14">
            <EmptyState :icon="PhUsersThree" title="No roster in your scope yet">
                The roster shows the unit your milpac billet commands, or a unit an admin granted you. Neither applies to your
                account yet; ask an S1 admin if that is wrong.
            </EmptyState>
        </section>

        <template v-else>
            <Tabs v-model="view">
                <TabsList aria-label="Roster view">
                    <TabsTrigger value="orbat" data-testid="view-orbat"><PhListBullets />Order of battle</TabsTrigger>
                    <TabsTrigger value="matrix" data-testid="view-matrix"><PhGridFour />Attendance matrix</TabsTrigger>
                </TabsList>
            </Tabs>

            <section v-if="!strength && !troopers.length" class="rounded-[10px] border border-border bg-card py-14">
                <EmptyState :icon="PhUsersThree" :title="`No troopers in ${unit.callsign ?? unit.name} yet`">
                    Troopers appear here once milpac places them in a section of this unit and the roster syncs.
                    <template v-if="sync.lastSuccessAt">The last sync, {{ clock(sync.lastSuccessAt) }}, placed nobody here.</template>
                    <template v-if="can.sync">Check the section mapping in Org structure, then sync.</template>
                </EmptyState>
            </section>

            <OrbatView
                v-else-if="view === 'orbat'"
                v-model:query="query"
                v-model:filter="filter"
                :units="units"
                :troopers="troopers"
                :weeks="weeks"
                :current-week="currentWeek"
                :loading="navigating"
                @open="openTrooperId = $event"
            />
            <MatrixView
                v-else
                v-model:query="query"
                v-model:filter="filter"
                :units="units"
                :troopers="troopers"
                :weeks="weeks"
                :current-week="currentWeek"
                :loading="navigating"
                @open="openTrooperId = $event"
            />

            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                Roster from {{ sync.source }}<template v-if="sync.lastSuccessAt">, synced {{ clock(sync.lastSuccessAt) }}</template>.
                Rollcall and SP marks from section leaders. RC and SP cover the last 4 Cav weeks.
            </p>
        </template>
    </div>

    <TrooperSheet
        :trooper-id="openTrooperId"
        :links="links"
        :sync-label="syncLabel"
        @close="openTrooperId = null"
    />
</template>
