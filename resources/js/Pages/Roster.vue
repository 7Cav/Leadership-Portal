<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { toast } from "vue-sonner";
import {
    PhArrowsClockwise,
    PhCaretRight,
    PhCaretUpDown,
    PhCheck,
    PhFlag,
    PhChartLine,
    PhGridFour,
    PhListBullets,
    PhLockSimple,
    PhUsersThree,
    PhWarning,
} from "@phosphor-icons/vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from "@/components/ui/dialog";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from "@/components/ui/tooltip";
import EmptyState from "@/components/EmptyState.vue";
import Guidon from "@/components/Guidon.vue";
import LoaSyncNote from "@/components/LoaSyncNote.vue";
import LoaSyncProblems from "@/components/LoaSyncProblems.vue";
import MissingPracticeSlots from "@/components/MissingPracticeSlots.vue";
import MatrixView from "@/components/roster/MatrixView.vue";
import OrbatView from "@/components/roster/OrbatView.vue";
import StrengthView from "@/components/roster/StrengthView.vue";
import GuidonGenerator from "@/components/roster/GuidonGenerator.vue";
import PracticeGuestsPanel from "@/components/roster/PracticeGuestsPanel.vue";
import TrooperSheet from "@/components/roster/TrooperSheet.vue";
import { useNow } from "@/composables/useNow";
import { useViewAs } from "@/composables/useViewAs";
import { forumDate, zuluTime } from "@/lib/forumDate";
import { percentLabel } from "@/lib/roster";
import { localTime, timeAgo } from "@/lib/timeAgo";

// The roster (Stories 2.8, 4.6, 4.7, 4.9). One page, three views of the same subtree, toggled and
// kept in the URL (`?view=`): the order of battle (binding mockup roster-s2.html), the
// attendance matrix (roster-s3.html) and the unit's strength by week. The first two open the same
// trooper Sheet. Every number on it comes
// from RosterController, which reads the shared KPI service; nothing here re-derives a KPI.
const props = defineProps({
    unit: { type: Object, default: null },
    trail: { type: Array, required: true },
    scopeOptions: { type: Array, required: true },
    weeks: { type: Array, required: true },
    // The viewed unit's sections with no practice slot set, `[{ id, designation }]`; leaders only.
    sectionsWithoutSlot: { type: Array, default: () => [] },
    currentWeek: { type: String, required: true },
    units: { type: Array, required: true },
    troopers: { type: Array, required: true },
    sync: { type: Object, required: true },
    // Where leave comes from and when it last synced (`LoaSyncState::toArray()`, 4.3, AD-20): the LOA weeks,
    // "On LOA" and at-risk all move with it.
    loaSync: { type: Object, default: null },
    // Admins only: what the latest leave sync could not read (`LoaSyncProblems::latest()`).
    loaSyncProblems: { type: Object, default: null },
    can: { type: Object, required: true },
    links: { type: Object, required: true },
    // "Guests at our practices" for a section, platoon or company (JSAM evidence, 2026-10-23): `{ criteria, rows }`,
    // or null for a battalion or the regiment.
    guests: { type: Object, default: null },
    // The Strength tab (`StrengthTrends`): `{ range, weeks, change, children }`, sent only while that tab is open.
    strengthTrends: { type: Object, default: null },
});

const page = usePage();
const VIEWS = ["orbat", "matrix", "strength"];

function queryParam(name) {
    const url = page.url ?? "";
    const search = url.includes("?") ? url.slice(url.indexOf("?")) : "";

    return new URLSearchParams(search).get(name);
}

const view = ref(
    VIEWS.includes(queryParam("view")) ? queryParam("view") : "orbat",
);
const query = ref("");
const filter = ref("all");
const openTrooperId = ref(Number(queryParam("trooper")) || null);

// The view and the open trooper live in the URL so a link or a reload lands on the same screen.
// A client-side replace: switching views needs no new data from the server.
function syncUrl() {
    const url = new URL(page.url, window.location.origin);
    url.searchParams.set("view", view.value);
    if (openTrooperId.value)
        url.searchParams.set("trooper", openTrooperId.value);
    else url.searchParams.delete("trooper");
    router.replace({
        url: `${url.pathname}${url.search}`,
        preserveState: true,
        preserveScroll: true,
    });
}
watch([view, openTrooperId], syncUrl);

// The strength numbers are not part of the roster load: picking the tab, or a range on it, asks for
// just that prop with a partial reload.
const strengthLoading = ref(false);
function loadStrength(range) {
    strengthLoading.value = true;
    router.reload({
        only: ["strengthTrends"],
        data: { view: "strength", ...(range ? { range } : {}) },
        preserveScroll: true,
        onFinish: () => (strengthLoading.value = false),
    });
}
watch(
    view,
    (v) => {
        if (v === "strength" && !props.strengthTrends) loadStrength();
    },
    { immediate: true },
);

const root = computed(() => props.units[0] ?? null);

// New Recruits are hidden until asked for (2026-10-24), unless the roster is opened on their group itself.
const showRecruits = ref(false);
const recruitIds = computed(
    () => new Set(props.units.filter((u, index) => index > 0 && u.kind === "recruit").map((u) => u.id)),
);
const shownUnits = computed(() =>
    showRecruits.value ? props.units : props.units.filter((u) => !recruitIds.value.has(u.id)),
);
const shownTroopers = computed(() =>
    showRecruits.value ? props.troopers : props.troopers.filter((t) => !recruitIds.value.has(t.sectionId)),
);
const strength = computed(() => root.value?.kpis.strength ?? 0);
const sectionCount = computed(
    () => props.units.filter((u) => u.type === "section").length,
);
const platoonCount = computed(
    () => props.units.filter((u) => u.type === "platoon").length,
);

const composition = computed(() => {
    if (!props.unit || props.unit.type === "section") return null;
    const parts = [];
    if (platoonCount.value && props.unit.type !== "platoon")
        parts.push(
            `${platoonCount.value} platoon${platoonCount.value === 1 ? "" : "s"}`,
        );
    parts.push(
        `${sectionCount.value} section${sectionCount.value === 1 ? "" : "s"}`,
    );

    return `in ${parts.join(" and ")}`;
});

// Unit navigation: a real visit, since another unit means another subtree of data.
const navigating = ref(false);
let offStart;
let offFinish;
onMounted(() => {
    offStart = router.on("start", (event) => {
        if (event.detail.visit.url.pathname.startsWith("/roster"))
            navigating.value = true;
    });
    offFinish = router.on("finish", () => (navigating.value = false));
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
    const today = new Date().toISOString().slice(0, 10) === iso.slice(0, 10);

    return today ? zuluTime(at) : `${forumDate(iso)} ${zuluTime(at)}`;
}
// The stamp sits inside its own tooltip, so the relative time joins the text and the viewer's local
// time joins that tooltip (the shared clock keeps "35 min ago" current).
const now = useNow();
const syncLabel = computed(() =>
    props.sync.lastSuccessAt
        ? `${props.sync.source}, synced ${clock(props.sync.lastSuccessAt)} (${timeAgo(props.sync.lastSuccessAt, now.value)})`
        : `${props.sync.source}, never synced`,
);
const syncDetail = computed(() => {
    const lines = [
        "Ranks, billets and placements come from milpac and refresh on every roster sync.",
    ];
    if (props.sync.lastSuccessAt) {
        lines.push(`Last synced ${localTime(props.sync.lastSuccessAt)} your time.`);
    }
    if (props.sync.stale && props.sync.lastAttempt) {
        lines.push(
            `The last attempt (${clock(props.sync.lastAttempt.at)}) ${props.sync.lastAttempt.status === "partial" ? "skipped some rows" : "failed"}; this is the last good copy.`,
        );
    } else if (props.sync.stale) {
        lines.push("No sync has succeeded recently; this is the last good copy.");
    }

    return lines.join(" ");
});
// Why the stamp warns: the last attempt's outcome, or, with no failed attempt to name, an overdue sync.
const staleReason = computed(() => {
    if (!props.sync.lastAttempt) return "overdue";

    return props.sync.lastAttempt.status === "partial"
        ? "last attempt incomplete"
        : "last attempt failed";
});

// While previewing someone else (1.13) the server refuses every write, so the control says so up
// front instead of failing on click.
const { active: viewAs, subject: viewAsSubject } = useViewAs();

// The unit leader's own last sync of this echelon, apart from the roster-wide stamp.
const unitSyncLabel = computed(() => {
    const run = props.sync.unit;
    if (!run?.at) return null;
    const at = clock(run.at);

    return run.status === "failure"
        ? `This unit's sync failed ${at}`
        : `This unit synced ${at}${run.status === "partial" ? ", some rows skipped" : ""}`;
});

// The guidon generator (#446), for those who may save this unit's guidon (the server sends `can.editGuidon`).
const guidonOpen = ref(false);

const syncing = ref(false);
function syncNow() {
    if (viewAs.value || syncing.value) return;
    syncing.value = true;
    // An Admin refreshes the whole roster; a leader refreshes only the echelon on screen.
    router.post(
        props.can.sync
            ? "/settings/roster-sync"
            : `/units/${props.unit.id}/roster-sync`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () =>
                toast.success("Roster sync started", {
                    description:
                        "Reload in a minute to see the refreshed roster.",
                }),
            // A failed request is an HTTP exception or a network error, never a validation error, so
            // each gets its own message instead of Inertia's raw error dialog (returning false).
            onHttpException: (response) => {
                toast.error("Could not start the roster sync", {
                    description:
                        response.status === 403
                            ? "Only an Admin or this unit's leader can start a roster sync."
                            : response.status === 409
                              ? "A sync of this unit, or of a unit above or below it, is already queued or running. Reload in a minute to see it."
                              : response.status === 429
                                ? "Too many requests; wait a few minutes and try again."
                                : response.status === 419
                                  ? "Your session expired; reload the page and try again."
                                  : `(error ${response.status}). Try again.`,
                });

                return false;
            },
            onNetworkError: () => {
                toast.error("Could not start the roster sync", {
                    description:
                        "Could not reach the portal. Check your connection and try again.",
                });

                return false;
            },
            onFinish: () => (syncing.value = false),
        },
    );
}
</script>

<template>
    <Head :title="unit ? `Roster ${unit.callsign ?? unit.name} · 7Cav Leadership Portal` : 'Roster · 7Cav Leadership Portal'" />

    <div class="space-y-4">
        <section
            class="flex flex-wrap items-end gap-x-6 gap-y-3"
            aria-labelledby="roster-title"
        >
            <div class="min-w-0">
                <nav v-if="trail.length > 1" aria-label="Unit" class="mb-0.5">
                    <ol
                        class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
                    >
                        <li
                            v-for="(crumb, index) in trail.slice(0, -1)"
                            :key="crumb.id"
                            class="inline-flex items-center gap-1"
                        >
                            <Link
                                v-if="crumb.inScope"
                                :href="unitHref(crumb.id)"
                                class="hover:text-foreground"
                                >{{ crumb.callsign ?? crumb.name }}</Link
                            >
                            <span v-else>{{
                                crumb.callsign ?? crumb.name
                            }}</span>
                            <PhCaretRight
                                v-if="index < trail.length - 2"
                                class="size-3"
                                aria-hidden="true"
                            />
                        </li>
                    </ol>
                </nav>
                <h1
                    id="roster-title"
                    class="text-xl font-semibold tracking-tight sm:text-[22px]"
                >
                    Roster<span v-if="unit" class="text-muted-foreground"
                        >{{ " " }}{{ unit.callsign ?? unit.name }}</span
                    ><Guidon
                        v-if="unit?.guidon"
                        :design="unit.guidon"
                        :height="20"
                        class="ml-2"
                    /><span
                        v-if="unit?.label"
                        class="ml-2 rounded-sm border border-border px-1.5 align-middle text-xs font-semibold tracking-wide text-muted-foreground"
                        data-testid="unit-label"
                        >{{ unit.label }}</span
                    >
                </h1>
                <p
                    v-if="unit"
                    class="mt-0.5 text-[13px] text-muted-foreground"
                    data-testid="scope-line"
                >
                    {{ unit.name }}.<template v-if="strength"
                        >{{ " " }}Headcount <span class="tabular-nums">{{ strength }}</span
                        ><template v-if="composition"
                            >,{{ " " }}{{ composition }}</template
                        >. RC 30d
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ percentLabel(root.kpis.rc) }}</span
                        >, SP 30d
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ percentLabel(root.kpis.sp) }}</span
                        >.
                    </template>
                    <template v-else>{{ " " }}No troopers assigned yet.</template>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                <label
                    v-if="recruitIds.size"
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <input
                        v-model="showRecruits"
                        type="checkbox"
                        data-testid="show-recruits"
                        class="size-3.5 accent-primary"
                    />
                    Show New Recruits
                </label>
                <DropdownMenu v-if="scopeOptions.length">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            data-testid="roster-scope"
                            :aria-label="`Unit: ${unit?.callsign ?? unit?.name}. Change unit`"
                        >
                            <PhUsersThree />
                            <span class="max-w-40 truncate">{{
                                unit?.callsign ?? unit?.name ?? "Choose a unit"
                            }}</span>
                            <PhCaretUpDown class="text-muted-foreground" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        class="max-h-[min(28rem,calc(100dvh-6rem))] w-72 overflow-y-auto"
                    >
                        <DropdownMenuLabel
                            class="text-xs font-normal text-muted-foreground"
                            >Show the roster for</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="option in scopeOptions"
                            :key="option.id"
                            as-child
                        >
                            <Link
                                :href="unitHref(option.id)"
                                class="gap-2"
                                :style="{
                                    paddingLeft: `${0.5 + Math.min(option.depth - scopeOptions[0].depth, 4) * 0.75}rem`,
                                }"
                                :aria-current="
                                    option.id === unit?.id ? 'true' : undefined
                                "
                                :data-testid="`scope-option-${option.id}`"
                            >
                                <span class="font-medium">{{
                                    option.callsign ?? option.name
                                }}</span>
                                <Guidon :design="option.guidon" :height="12" />
                                <span class="truncate text-muted-foreground">{{
                                    option.name
                                }}</span>
                                <span
                                    v-if="option.label"
                                    class="rounded-sm border border-border px-1 text-[10px] font-semibold text-muted-foreground"
                                    >{{ option.label }}</span
                                >
                                <PhCheck
                                    v-if="option.id === unit?.id"
                                    class="ml-auto"
                                    aria-label="Current unit"
                                />
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
                            {{ syncLabel
                            }}<template v-if="sync.stale"
                                >, {{ staleReason }}</template
                            >
                        </Badge>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-72">{{
                        syncDetail
                    }}</TooltipContent>
                </Tooltip>

                <Badge
                    v-if="unitSyncLabel"
                    :variant="sync.unit.status === 'success' ? 'outline' : 'warning'"
                    class="h-7 px-2.5 text-xs"
                    data-testid="roster-unit-sync"
                >
                    <PhArrowsClockwise v-if="sync.unit.status === 'success'" />
                    <PhWarning v-else />
                    {{ unitSyncLabel }}
                </Badge>

                <Button
                    v-if="can.editGuidon"
                    variant="outline"
                    size="sm"
                    data-testid="roster-guidon"
                    @click="guidonOpen = true"
                >
                    <PhFlag />Edit guidon
                </Button>

                <template v-if="can.sync || can.syncUnit">
                    <Tooltip v-if="viewAs">
                        <TooltipTrigger as-child>
                            <Button
                                variant="outline"
                                size="sm"
                                aria-disabled="true"
                                class="cursor-not-allowed opacity-60"
                                @click.prevent
                            >
                                <PhLockSimple />Sync now
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent
                            >Read-only while viewing as
                            {{ viewAsSubject }}</TooltipContent
                        >
                    </Tooltip>
                    <Button
                        v-else
                        variant="outline"
                        size="sm"
                        :disabled="syncing"
                        data-testid="roster-sync-now"
                        @click="syncNow"
                    >
                        <PhArrowsClockwise
                            :class="syncing && 'motion-safe:animate-spin'"
                        />{{
                            syncing
                                ? "Starting sync"
                                : can.sync
                                  ? "Sync now"
                                  : "Sync this unit"
                        }}
                    </Button>
                </template>
            </div>
        </section>

        <section
            v-if="!unit"
            class="rounded-[10px] border border-border bg-card py-14"
        >
            <EmptyState
                :icon="PhUsersThree"
                title="No roster in your scope yet"
            >
                The roster shows the unit your milpac billet commands, or a unit
                an Admin granted you. Neither applies to your account yet. If
                that is wrong, ask an Admin.
            </EmptyState>
        </section>

        <template v-else>
            <MissingPracticeSlots :sections="sectionsWithoutSlot" :own="false" class="mb-3" />

            <Tabs v-model="view">
                <TabsList aria-label="Roster view">
                    <TabsTrigger value="orbat" data-testid="view-orbat"
                        ><PhListBullets />Order of battle</TabsTrigger
                    >
                    <TabsTrigger value="matrix" data-testid="view-matrix"
                        ><PhGridFour />Attendance matrix</TabsTrigger
                    >
                    <TabsTrigger value="strength" data-testid="view-strength"
                        ><PhChartLine />Strength</TabsTrigger
                    >
                </TabsList>
            </Tabs>

            <StrengthView
                v-if="view === 'strength'"
                :unit="unit"
                :trends="strengthTrends"
                :loading="strengthLoading"
                @range="loadStrength"
            />
            <section
                v-else-if="!strength && !troopers.length"
                class="rounded-[10px] border border-border bg-card py-14"
            >
                <EmptyState
                    :icon="PhUsersThree"
                    :title="`No troopers in ${unit.callsign ?? unit.name} yet`"
                >
                    Troopers appear here once milpac places them in a section of
                    this unit and the roster syncs.
                    <template v-if="sync.lastSuccessAt"
                        >The last sync, {{ clock(sync.lastSuccessAt) }}, placed
                        nobody here.</template
                    >
                    <template v-if="can.sync"
                        >Check the section mapping in Org structure, then
                        sync.</template
                    >
                </EmptyState>
            </section>

            <OrbatView
                v-else-if="view === 'orbat'"
                v-model:query="query"
                v-model:filter="filter"
                :units="shownUnits"
                :troopers="shownTroopers"
                :weeks="weeks"
                :current-week="currentWeek"
                :loading="navigating"
                @open="openTrooperId = $event"
            />
            <MatrixView
                v-else
                v-model:query="query"
                v-model:filter="filter"
                :units="shownUnits"
                :troopers="shownTroopers"
                :weeks="weeks"
                :current-week="currentWeek"
                :loading="navigating"
                @open="openTrooperId = $event"
            />

            <PracticeGuestsPanel
                v-if="guests"
                :guests="guests"
                :unit-label="unit.callsign ?? unit.name"
            />

            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                Roster from {{ sync.source
                }}<template v-if="sync.lastSuccessAt"
                    >, synced {{ clock(sync.lastSuccessAt) }}</template
                >. Roll call and SP marks from section leaders. RC and SP cover
                the last 4 Cav weeks.
            </p>
            <LoaSyncNote v-if="loaSync" :sync="loaSync" />
            <LoaSyncProblems v-if="loaSyncProblems" :run="loaSyncProblems" />
        </template>
    </div>

    <Dialog v-if="can.editGuidon" v-model:open="guidonOpen">
        <DialogContent class="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-md">
            <DialogTitle>Guidon of {{ unit.callsign ?? unit.name }}</DialogTitle>
            <DialogDescription>
                Pick a format, the colors and the text, then save. The saved guidon
                shows next to the unit in the portal.
            </DialogDescription>
            <GuidonGenerator :unit="unit" @saved="guidonOpen = false" />
        </DialogContent>
    </Dialog>

    <TrooperSheet
        :trooper-id="openTrooperId"
        :links="links"
        :sync-label="syncLabel"
        :loa-sync="loaSync"
        @close="openTrooperId = null"
    />
</template>
