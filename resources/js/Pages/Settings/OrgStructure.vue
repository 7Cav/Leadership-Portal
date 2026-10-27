<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    PhCaretDown,
    PhCheckCircle,
    PhCloudArrowDown,
    PhLockSimple,
    PhMagnifyingGlass,
    PhPlus,
    PhTreeStructure,
    PhWarningCircle,
} from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { toast } from 'vue-sonner';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Separator } from '@/components/ui/separator';
import EmptyState from '@/components/EmptyState.vue';
import { onHttpException } from '@/lib/httpError';
import { ancestorIds, searchUnits } from '@/lib/orgSearch';
import OrgTreeNode from './OrgTreeNode.vue';

// Flat units array with `path` (AD-18) — nested here for rendering; the server keeps the
// authoritative shape flat so subtree scoping elsewhere never depends on this tree walk.
const props = defineProps({
    units: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
    // Flashed once by the import action (Story 2.9):
    // `{ unitsCreated, mappingsCreated, mappingsDiffering, skippedTitles }`.
    importResult: { type: Object, default: null },
    // Troopers the roster sync could not place into a section (Story 2.6), as
    // `{ id, cav_id, username, rank, mos, billet, position_id }`. Null when the server withholds
    // the list (anyone but an Admin), which is different from an empty array (nobody unplaced).
    unplacedTroopers: { type: Array, default: null },
    // The true count of unplaced troopers; the list above is capped, so this can be larger.
    unplacedTotal: { type: Number, default: null },
    // Who is in each group the viewer may see, by unit id: `{ id, displayName, billet, was }`. `was` is the line
    // unit an ELOA trooper left, or null.
    groupMembers: { type: Object, default: () => ({}) },
});

// New Recruits are hidden until asked for: they are not yet part of any unit, and never in an ancestor's count.
const showRecruits = ref(false);
const hasRecruits = computed(() => props.units.some((unit) => unit.kind === 'recruit'));
const hiddenIds = computed(() => {
    if (showRecruits.value) return new Set();
    const recruitPaths = props.units.filter((unit) => unit.kind === 'recruit').map((unit) => unit.path);
    return new Set(props.units.filter((unit) => recruitPaths.some((path) => unit.path.startsWith(path))).map((unit) => unit.id));
});
const shownUnits = computed(() => props.units.filter((unit) => !hiddenIds.value.has(unit.id)));

const unplacedCount = computed(() => props.unplacedTotal ?? props.unplacedTroopers?.length ?? 0);

// A trooper with no reported billet title falls back to the position id: it is why they are here,
// the sync found no section mapped to it.
function billetLabel(trooper) {
    if (trooper.billet) return trooper.billet;
    return trooper.position_id !== null ? `Billet not mapped (position ${trooper.position_id})` : 'No billet reported';
}

const tree = computed(() => {
    const byId = new Map(shownUnits.value.map((unit) => [unit.id, { ...unit, children: [] }]));
    const roots = [];

    for (const unit of byId.values()) {
        if (unit.parent_id !== null && byId.has(unit.parent_id)) {
            byId.get(unit.parent_id).children.push(unit);
        } else {
            roots.push(unit);
        }
    }

    return roots;
});

// Which node ids are collapsed — a plain reactive Set so a caret toggle updates just that
// branch's `v-if` without re-deriving the whole tree.
const collapsed = reactive(new Set());

function toggleCollapsed(id) {
    if (collapsed.has(id)) {
        collapsed.delete(id);
    } else {
        collapsed.add(id);
    }
}

const selectedId = ref(null);
const selectedUnit = computed(() => props.units.find((unit) => unit.id === selectedId.value) ?? null);
const selectedMembers = computed(() => (selectedUnit.value ? (props.groupMembers[selectedUnit.value.id] ?? null) : null));

// Game or team label (Admin): its own small form, audited on the server.
const labelForm = useForm({ label: '' });
watch(
    selectedUnit,
    (unit) => {
        labelForm.label = unit?.label ?? '';
        labelForm.clearErrors();
    },
    { immediate: true },
);

// Acting lead (Admin): a serving trooper who leads a unit the roster names no lead for, such as a DEVCOM starter
// platoon run by a DEVCOM Lead. One per unit; audited with a required reason on the server.
const actingQuery = ref('');
const actingResults = ref([]);
const actingPick = ref(null);
const actingReason = ref('');
const actingError = ref(null);
const actingBusy = ref(false);
const canTakeActingLead = computed(
    () => selectedUnit.value !== null && selectedUnit.value.type !== 'group' && selectedUnit.value.type !== 'regiment',
);

watch(selectedUnit, () => {
    actingQuery.value = '';
    actingResults.value = [];
    actingPick.value = null;
    actingReason.value = '';
    actingError.value = null;
});

async function searchActing() {
    const q = actingQuery.value.trim();
    actingPick.value = null;
    if (q.length < 2) {
        actingResults.value = [];
        return;
    }
    try {
        const response = await fetch(`/troopers/search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        actingResults.value = response.ok ? ((await response.json()).troopers ?? []).filter((t) => !t.departed) : [];
    } catch {
        actingResults.value = [];
    }
}

function actingOptions(onError) {
    return {
        preserveScroll: true,
        onError: (errors) => (actingError.value = errors.acting_lead ?? errors.reason ?? errors.trooper_id ?? Object.values(errors)[0] ?? onError),
        onFinish: () => (actingBusy.value = false),
        onSuccess: () => {
            actingPick.value = null;
            actingReason.value = '';
            actingQuery.value = '';
            actingResults.value = [];
        },
    };
}

function assignActing() {
    actingError.value = null;
    actingBusy.value = true;
    router.post(
        `/settings/org-structure/${selectedUnit.value.id}/acting-lead`,
        { trooper_id: actingPick.value.id, reason: actingReason.value.trim() },
        actingOptions('The acting lead was not saved.'),
    );
}

function removeActing() {
    actingError.value = null;
    actingBusy.value = true;
    router.delete(`/settings/org-structure/${selectedUnit.value.id}/acting-lead`, {
        data: { reason: actingReason.value.trim() },
        ...actingOptions('The acting lead was not removed.'),
    });
}

function submitLabel() {
    labelForm.patch(`/settings/org-structure/${selectedUnit.value.id}/label`, {
        preserveScroll: true,
        onHttpException: onHttpException('The label was not saved', (message) => toast.error(message)),
    });
}

// Find a unit by name or callsign. Picking a match opens the branches above it, selects it,
// scrolls it into view and moves focus to its row, which glows for a moment so the eye finds it.
const unitQuery = ref('');
const searchOpen = ref(false);
const activeIndex = ref(0);
const highlightId = ref(null);
let highlightTimer = null;

const search = computed(() => searchUnits(shownUnits.value, unitQuery.value));
const listOpen = computed(() => searchOpen.value && unitQuery.value.trim() !== '');
const activeOption = computed(() => (listOpen.value ? (search.value.results[activeIndex.value] ?? null) : null));
const searchAnnouncement = computed(() => {
    if (!unitQuery.value.trim()) return '';
    const { total } = search.value;
    return total === 0 ? 'No units match' : `${total} ${total === 1 ? 'unit matches' : 'units match'}`;
});

watch(unitQuery, () => {
    activeIndex.value = 0;
    searchOpen.value = true;
});

watch(activeOption, (option) => {
    if (option) nextTick(() => document.getElementById(`unit-search-option-${option.id}`)?.scrollIntoView?.({ block: 'nearest' }));
});

function moveActive(step) {
    const count = search.value.results.length;
    if (!count) return;
    if (!searchOpen.value) {
        searchOpen.value = true;
        return;
    }
    activeIndex.value = (activeIndex.value + step + count) % count;
}

function onSearchKeydown(event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        moveActive(event.key === 'ArrowDown' ? 1 : -1);
    } else if (event.key === 'Enter') {
        if (activeOption.value) {
            event.preventDefault();
            pickUnit(activeOption.value.id);
        }
    } else if (event.key === 'Escape') {
        if (listOpen.value) {
            searchOpen.value = false;
        } else if (unitQuery.value) {
            unitQuery.value = '';
        }
    }
}

function pickUnit(id) {
    const unit = props.units.find((candidate) => candidate.id === id);
    if (!unit) return;

    for (const ancestorId of ancestorIds(unit)) {
        collapsed.delete(ancestorId);
    }
    selectedId.value = id;
    searchOpen.value = false;

    highlightId.value = id;
    clearTimeout(highlightTimer);
    highlightTimer = setTimeout(() => (highlightId.value = null), 1600);

    nextTick(() => {
        const row = document.querySelector(`[data-testid="unit-row-${id}"]`);
        const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        row?.scrollIntoView?.({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
        row?.focus({ preventScroll: true });
    });
}

onBeforeUnmount(() => clearTimeout(highlightTimer));

// Mirrors `UnitType::rank()` (app/Models/UnitType.php) — Regiment first, one rank per echelon.
// `CreateUnit` enforces the real invariant server-side; this is the UI's best-effort mirror of
// it (AC #5's "hidden in the UI" pattern), so the parent list below only ever offers legal
// parents instead of relying on the 422 round-trip to teach the Admin the rule.
const RANK = { regiment: 0, battalion: 1, company: 2, platoon: 3, section: 4 };
const unitTypes = Object.keys(RANK);

const form = useForm({
    type: 'battalion',
    name: '',
    callsign: '',
    parent_id: null,
});

// A Regiment is always a root (no legal parent); everything else needs a parent exactly one
// rank above it.
const legalParents = computed(() =>
    form.type === 'regiment' ? [] : props.units.filter((unit) => RANK[unit.type] === RANK[form.type] - 1),
);

// Changing the echelon can invalidate the previously-selected parent (e.g. switching from
// Company to Regiment), so re-pick rather than silently submit a now-illegal combination.
watch(
    () => form.type,
    () => {
        form.parent_id = legalParents.value.some((unit) => unit.id === form.parent_id) ? form.parent_id : null;
    },
);

function submitAdd() {
    form.post('/settings/org-structure', {
        onSuccess: () => form.reset('name', 'callsign'),
        onHttpException: onHttpException('The unit was not added', (message) => toast.error(message)),
    });
}

// Pre-fills the shared add-unit form from a row's ＋ tool rather than duplicating a form per
// row (the mockup's per-node ".add-child" affordance, built on the one form 1.8 already has).
function prefillAddChild({ parentId, type }) {
    form.type = type;
    form.parent_id = parentId;
}

const editForm = useForm({ name: '', callsign: '', parent_id: null });

watch(
    selectedUnit,
    (unit) => {
        if (unit) {
            editForm.name = unit.name;
            editForm.callsign = unit.callsign ?? '';
            editForm.parent_id = unit.parent_id;
            editForm.clearErrors();
        }
    },
    { immediate: true },
);

// A descendant always has strictly higher rank than its ancestor, and the unit itself has equal
// rank — neither can ever equal `rank - 1`, so this rank filter alone already excludes both a
// cycle and a self-reparent without a separate path check (mirrors the add-form's `legalParents`).
const editLegalParents = computed(() => {
    if (!selectedUnit.value) {
        return [];
    }

    // A group sits under the regiment or a staff group (a department under its portfolio), never under itself or
    // anything below it; a line unit never sits under a group (`UnitType::canSitUnder()`).
    if (selectedUnit.value.type === 'group') {
        const own = selectedUnit.value.path;
        return props.units.filter(
            (unit) => (unit.type === 'regiment' || (unit.type === 'group' && unit.kind === 'staff')) && !unit.path.startsWith(own),
        );
    }

    return props.units.filter((unit) => RANK[unit.type] === RANK[selectedUnit.value.type] - 1);
});

function submitEdit() {
    editForm.patch(`/settings/org-structure/${selectedUnit.value.id}`, {
        preserveScroll: true,
        onHttpException: onHttpException('The unit was not saved', (message) => toast.error(message)),
    });
}

function cancelEdit() {
    if (!selectedUnit.value) {
        return;
    }
    editForm.name = selectedUnit.value.name;
    editForm.callsign = selectedUnit.value.callsign ?? '';
    editForm.parent_id = selectedUnit.value.parent_id;
    editForm.clearErrors();
}

// Exposed so a test can assert the ＋ tool actually pre-filled this form, without depending on
// a re-render to observe it through the DOM (the mocked `useForm` in tests isn't reactive).
defineExpose({ form });

// Import from the Cav position groups (Story 2.9). A plain `router.post()` — there is no form
// state to carry — so a refused import's `errors.import` is caught here, as `deleteError` is.
// Manual placement of an unplaced trooper (Story 2.7). One chip is open at a time. Since 2026-10-24 any unit can take
// a trooper: a section, an HQ node for its staff, or a group (a staff department, ELOA, Reservists, New Recruits).
// Listed by `path` so they read in chain-of-command order.
const sections = computed(() =>
    [...props.units]
        .sort((a, b) => a.path.localeCompare(b.path, undefined, { numeric: true }))
        .map((unit) => ({ id: unit.id, label: sectionLabel(unit) })),
);

// Squads and sections share names across platoons ("1st Squad"), and this is an audited personnel
// action, so each option names the units above it (the root regiment left out as noise).
function sectionLabel(unit) {
    const own = unit.callsign ? `${unit.callsign} · ${unit.name}` : unit.name;
    const above = unit.path
        .split('/')
        .filter(Boolean)
        .slice(1, -1)
        .map((id) => props.units.find((candidate) => candidate.id === Number(id))?.name)
        .filter(Boolean);

    return above.length ? `${own} (${above.join(' / ')})` : own;
}
const assigningId = ref(null);
const assignSection = ref(null);
const assignReason = ref('');
const assignError = ref(null);
const assigning = ref(false);

function openAssign(trooper) {
    assigningId.value = trooper.id;
    assignSection.value = null;
    assignReason.value = '';
    assignError.value = null;
    // Keyboard and screen-reader users keep their place: focus moves into the form it opened.
    nextTick(() => document.getElementById(`assign-section-${trooper.id}`)?.focus());
}

function cancelAssign() {
    const trooperId = assigningId.value;
    assigningId.value = null;
    assignError.value = null;
    nextTick(() => document.getElementById(`assign-button-${trooperId}`)?.focus());
}

// A placed trooper's chip leaves the list, so focus goes to the panel instead of falling to the page.
function finishAssign() {
    assigningId.value = null;
    toast.success('Trooper placed');
    nextTick(() => document.querySelector('[data-testid="unplaced-panel"]')?.focus());
}

function submitAssign(trooper) {
    assignError.value = null;
    assigning.value = true;
    router.post(
        `/settings/org-structure/troopers/${trooper.id}/placement`,
        { section_id: assignSection.value, reason: assignReason.value.trim() },
        {
            preserveScroll: true,
            onSuccess: finishAssign,
            // A 403 or an expired session is an HTTP exception, not a validation error: say so here
            // instead of Inertia's raw error dialog (returning false).
            onHttpException: (response) => {
                assignError.value =
                    response.status === 403
                        ? 'Only an Admin can place a trooper.'
                        : response.status === 419
                          ? 'Your session expired. Reload the page and try again.'
                          : `The placement failed (error ${response.status}). Try again.`;

                return false;
            },
            onError: (errors) => {
                assignError.value =
                    errors.placement ?? errors.section_id ?? errors.reason ?? Object.values(errors)[0] ?? 'The placement failed. Reload the page and try again.';
            },
            onFinish: () => {
                assigning.value = false;
            },
        },
    );
}

const importing = ref(false);
const importError = ref(null);

function runImport() {
    importError.value = null;
    importing.value = true;
    router.post(
        '/settings/org-structure/import',
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                importError.value =
                    errors.import ?? Object.values(errors)[0] ?? 'The import failed. Reload the page and try again; if it persists, check the server logs.';
            },
            onFinish: () => {
                importing.value = false;
            },
        },
    );
}

// A blocked delete (e.g. remaining children) 422s with an `errors.delete` message — there's no
// `useForm` in play for a plain `router.delete()` call, so that error has nowhere to land
// without this: without it, a refused delete would fail with no visible feedback at all.
const deleteError = ref(null);

function requestDelete(id) {
    const unit = props.units.find((candidate) => candidate.id === id);
    if (!unit || !confirm(`Delete "${unit.name}"? This cannot be undone.`)) {
        return;
    }

    deleteError.value = null;
    router.delete(`/settings/org-structure/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            if (selectedId.value === id) {
                selectedId.value = null;
            }
        },
        onError: (errors) => {
            deleteError.value = errors.delete ?? 'The delete failed. Reload the page and try again; if it persists, check the server logs.';
        },
    });
}
</script>

<template>
    <Head title="Settings · Organization structure · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Organization structure</h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    The chain of command, from regiment down to section. Rosters, rollcalls and reports all roll up along it.
                </p>
            </div>
            <div v-if="isAdmin" class="flex items-center gap-2">
                <Badge variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin</Badge>
                <!--
                  Admin-only (Story 2.9). Hidden for everyone else; the server refuses a non-Admin
                  import regardless (AD-6). This is convenience, not the boundary.
                -->
                <Button variant="outline" data-testid="import-from-cav" :disabled="importing" @click="runImport">
                    <PhCloudArrowDown aria-hidden="true" />
                    {{ importing ? 'Importing…' : 'Import from Cav roster' }}
                </Button>
            </div>
        </div>

        <p
            v-if="importError"
            data-testid="import-error"
            role="alert"
            class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/8 px-3.5 py-2.5 text-[13px] text-destructive"
        >
            <PhWarningCircle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ importError }}</span>
        </p>
        <div
            v-else-if="importResult"
            data-testid="import-result"
            role="status"
            class="flex items-start gap-2 rounded-lg border border-success/30 bg-success/8 px-3.5 py-2.5 text-[13px]"
        >
            <PhCheckCircle class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
            <p>
                Imported from the Cav roster: {{ importResult.unitsCreated }} {{ importResult.unitsCreated === 1 ? 'node' : 'nodes' }} and
                {{ importResult.mappingsCreated }} placement {{ importResult.mappingsCreated === 1 ? 'mapping' : 'mappings' }} created.
                <template v-if="importResult.mappingsDiffering">
                    {{ importResult.mappingsDiffering }} existing {{ importResult.mappingsDiffering === 1 ? 'mapping points' : 'mappings point' }}
                    at a different unit than Cav now lists, kept as they are.
                </template>
                <!-- Story 1.16's command-billet counts: shown only when the server sends them. -->
                <template v-if="importResult.commandMappingsCreated !== undefined">
                    {{ importResult.commandMappingsCreated }} command {{ importResult.commandMappingsCreated === 1 ? 'mapping' : 'mappings' }} created,
                    {{ importResult.commandMappingsRepointed ?? 0 }} repointed and {{ importResult.commandMappingsRemoved ?? 0 }} removed.
                </template>
                <template v-if="importResult.skippedTitles.length">
                    {{ importResult.skippedTitles.length }} position {{ importResult.skippedTitles.length === 1 ? 'title' : 'titles' }} skipped
                    (designation not recognised):
                    <span class="font-mono text-xs text-muted-foreground">{{ importResult.skippedTitles.join(', ') }}</span>
                </template>
            </p>
        </div>

        <div class="grid items-start gap-5 lg:grid-cols-[1.1fr_0.9fr]">
            <Card class="min-w-0 gap-0 py-0">
                <div class="flex items-center gap-2 px-4 pt-3.5 pb-2">
                    <h2 class="text-[15px] font-semibold">Chain of command</h2>
                    <span class="truncate text-xs text-muted-foreground">{{ units.length }} {{ units.length === 1 ? 'unit' : 'units' }}</span>
                    <label v-if="hasRecruits" class="ml-auto flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground">
                        <input v-model="showRecruits" type="checkbox" data-testid="show-recruits" class="size-3.5 accent-primary" />
                        Show New Recruits
                    </label>
                </div>
                <div v-if="units.length" class="relative px-4 pb-2" data-testid="unit-search">
                    <label for="unit-search" class="sr-only">Find a unit by name or callsign</label>
                    <PhMagnifyingGlass class="pointer-events-none absolute top-1/2 left-6.5 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                    <Input
                        id="unit-search"
                        v-model="unitQuery"
                        data-testid="unit-search-input"
                        type="text"
                        role="combobox"
                        autocomplete="off"
                        aria-autocomplete="list"
                        aria-controls="unit-search-results"
                        :aria-expanded="listOpen"
                        :aria-activedescendant="activeOption ? `unit-search-option-${activeOption.id}` : undefined"
                        placeholder="Find a unit by name or callsign"
                        class="pl-7.5"
                        @keydown="onSearchKeydown"
                        @focus="searchOpen = true"
                        @blur="searchOpen = false"
                    />
                    <div
                        v-show="listOpen"
                        class="absolute inset-x-4 top-full z-20 -mt-1 rounded-lg border border-border bg-popover p-1 text-popover-foreground shadow-panel"
                    >
                        <ul id="unit-search-results" role="listbox" aria-label="Matching units" class="max-h-72 overflow-y-auto">
                            <li
                                v-for="(result, index) in search.results"
                                :id="`unit-search-option-${result.id}`"
                                :key="result.id"
                                role="option"
                                :aria-selected="activeOption?.id === result.id"
                                :data-testid="`unit-search-option-${result.id}`"
                                class="cursor-pointer rounded-md px-2 py-1.5 text-[13px]"
                                :class="activeOption?.id === result.id && 'bg-accent'"
                                @mousedown.prevent
                                @mousemove="activeIndex = index"
                                @click="pickUnit(result.id)"
                            >
                                <span class="block truncate">
                                    {{ result.name }}
                                    <span v-if="result.callsign" class="ml-1.5 font-mono text-[11px] text-muted-foreground">{{ result.callsign }}</span>
                                </span>
                                <span class="block truncate text-xs text-muted-foreground">
                                    <span class="capitalize">{{ result.type }}</span><template v-if="result.trail"> in {{ result.trail }}</template>
                                </span>
                            </li>
                        </ul>
                        <p v-if="!search.total" data-testid="unit-search-empty" class="px-2 py-1.5 text-[13px] text-muted-foreground">
                            No unit matches that name or callsign.
                        </p>
                        <p v-else-if="search.total > search.results.length" data-testid="unit-search-more" class="px-2 pt-1 pb-0.5 text-xs text-muted-foreground">
                            Showing {{ search.results.length }} of {{ search.total }}. Keep typing to narrow it down.
                        </p>
                    </div>
                    <p class="sr-only" aria-live="polite" data-testid="unit-search-status">{{ searchAnnouncement }}</p>
                </div>
                <ul v-if="tree.length" role="tree" aria-label="Chain of command" class="px-2 pb-2">
                    <OrgTreeNode
                        v-for="node in tree"
                        :key="node.id"
                        :node="node"
                        :selected-id="selectedId"
                        :highlight-id="highlightId"
                        :collapsed="collapsed"
                        :is-admin="isAdmin"
                        @select="(id) => (selectedId = id)"
                        @toggle="toggleCollapsed"
                        @add-child="prefillAddChild"
                        @delete="requestDelete"
                    />
                </ul>
                <EmptyState v-else :icon="PhTreeStructure" title="No units yet" class="border-t border-border py-10">
                    {{ isAdmin ? 'Import the tree from the Cav roster, or add the regiment by hand.' : 'An Admin has not set up the chain of command yet.' }}
                </EmptyState>
            </Card>

            <Card class="min-w-0 gap-0 p-4">
                <div v-if="selectedUnit" data-testid="detail-panel">
                    <h2 class="mb-3 flex items-center gap-2 text-[15px] font-semibold">
                        <span class="truncate">{{ selectedUnit.name }}</span>
                        <Badge variant="outline" class="capitalize">{{ selectedUnit.type }}</Badge>
                        <Badge v-if="selectedUnit.label" variant="outline" data-testid="detail-label">{{ selectedUnit.label }}</Badge>
                    </h2>
                    <p v-if="selectedUnit.lead || selectedUnit.lead_vacant" data-testid="detail-lead" class="-mt-1.5 mb-3 text-[13px] text-muted-foreground">
                        <template v-if="selectedUnit.lead"
                            >Lead: {{ selectedUnit.lead.displayName }}<template v-if="selectedUnit.lead.source === 'acting'"> (acting)</template></template
                        >
                        <template v-else>Lead: vacant</template>
                    </p>

                    <!-- Who is in this group: staff department, ELOA (with where each trooper was), Reservists, New Recruits. -->
                    <section v-if="selectedMembers" data-testid="group-members" aria-label="Troopers in this group" class="mb-4">
                        <h3 class="text-[13px] font-semibold">{{ selectedMembers.length }} {{ selectedMembers.length === 1 ? 'trooper' : 'troopers' }}</h3>
                        <ul class="mt-1.5 max-h-60 space-y-1 overflow-y-auto text-[13px]">
                            <li v-for="member in selectedMembers" :key="member.id" data-testid="group-member" class="flex flex-wrap items-baseline gap-x-2">
                                <span class="font-medium">{{ member.displayName }}</span>
                                <span v-if="member.billet" class="text-xs text-muted-foreground">{{ member.billet }}</span>
                                <span v-if="member.was" data-testid="member-was" class="font-mono text-[11px] text-muted-foreground">was: {{ member.was }}</span>
                            </li>
                        </ul>
                    </section>

                    <section v-if="isAdmin && canTakeActingLead" data-testid="acting-lead" aria-label="Acting lead" class="mb-4 rounded-lg border border-dashed border-border p-3">
                        <h3 class="text-[13px] font-semibold">Acting lead</h3>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            For a unit whose lead is not in the roster data, such as a DEVCOM starter platoon run by a DEVCOM Lead. They get a
                            Platoon Leader's command over this unit while they serve.
                        </p>
                        <p v-if="selectedUnit.acting_lead" data-testid="acting-lead-current" class="mt-2 text-[13px]">{{ selectedUnit.acting_lead.displayName }}</p>
                        <div v-else class="mt-2 grid gap-1.5">
                            <Label for="acting-search">Trooper</Label>
                            <Input id="acting-search" v-model="actingQuery" data-testid="acting-search" type="text" placeholder="Search by username" @input="searchActing" />
                            <ul v-if="actingResults.length" class="max-h-40 overflow-y-auto text-[13px]">
                                <li v-for="result in actingResults" :key="result.id">
                                    <button
                                        type="button"
                                        data-testid="acting-result"
                                        class="w-full rounded-sm px-1.5 py-1 text-left hover:bg-accent"
                                        :class="actingPick?.id === result.id && 'bg-accent font-medium'"
                                        @click="actingPick = result"
                                    >
                                        {{ result.displayName }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="mt-2 grid gap-1.5">
                            <Label for="acting-reason">Reason</Label>
                            <Input id="acting-reason" v-model="actingReason" data-testid="acting-reason" type="text" maxlength="500" placeholder="Who confirmed it" />
                        </div>
                        <p v-if="actingError" data-testid="acting-error" role="alert" class="mt-1.5 text-xs text-destructive">{{ actingError }}</p>
                        <div class="mt-2 flex justify-end">
                            <Button
                                v-if="selectedUnit.acting_lead"
                                type="button"
                                variant="outline"
                                size="sm"
                                data-testid="acting-remove"
                                :disabled="actingBusy || !actingReason.trim()"
                                @click="removeActing"
                                >Remove acting lead</Button
                            >
                            <Button
                                v-else
                                type="button"
                                size="sm"
                                data-testid="acting-assign"
                                :disabled="actingBusy || !actingPick || !actingReason.trim()"
                                @click="assignActing"
                                >Make acting lead</Button
                            >
                        </div>
                    </section>

                    <form v-if="isAdmin" data-testid="label-form" class="mb-4 flex items-end gap-2" @submit.prevent="submitLabel">
                        <div class="grid flex-1 gap-1.5">
                            <Label for="edit-label">Game or team label</Label>
                            <Input id="edit-label" v-model="labelForm.label" data-testid="edit-label" type="text" maxlength="40" placeholder="e.g. WARDOGS" :aria-invalid="!!labelForm.errors.label" />
                            <p v-if="labelForm.errors.label" class="text-xs text-destructive" data-testid="edit-error-label">{{ labelForm.errors.label }}</p>
                        </div>
                        <Button type="submit" variant="outline" data-testid="save-label" :disabled="labelForm.processing">Save label</Button>
                    </form>

                    <!--
                      Editable for an Admin (AC #1, #2); read-only otherwise. Echelon isn't editable:
                      see `UpdateUnit`'s docblock for why re-parenting is kept separate from a rank change.
                    -->
                    <form v-if="isAdmin" data-testid="edit-unit-form" class="space-y-3" @submit.prevent="submitEdit">
                        <div class="grid gap-1.5">
                            <Label for="edit-name">Name</Label>
                            <Input id="edit-name" v-model="editForm.name" data-testid="edit-name" type="text" :aria-invalid="!!editForm.errors.name" />
                            <p v-if="editForm.errors.name" class="text-xs text-destructive" data-testid="edit-error-name">{{ editForm.errors.name }}</p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="edit-callsign">Callsign</Label>
                            <Input
                                id="edit-callsign"
                                v-model="editForm.callsign"
                                data-testid="edit-callsign"
                                type="text"
                                class="font-mono"
                                :aria-invalid="!!editForm.errors.callsign"
                            />
                            <p v-if="editForm.errors.callsign" class="text-xs text-destructive" data-testid="edit-error-callsign">
                                {{ editForm.errors.callsign }}
                            </p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="edit-parent">Parent</Label>
                            <NativeSelect
                                id="edit-parent"
                                v-model="editForm.parent_id"
                                data-testid="edit-parent_id"
                                :disabled="selectedUnit.type === 'regiment'"
                                :aria-invalid="!!editForm.errors.parent_id"
                            >
                                <option :value="null">{{ selectedUnit.type === 'regiment' ? 'None, this is the root' : 'Choose a parent' }}</option>
                                <option v-for="unit in editLegalParents" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                            </NativeSelect>
                            <p v-if="editForm.errors.parent_id" class="text-xs text-destructive" data-testid="edit-error-parent_id">
                                {{ editForm.errors.parent_id }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
                            <!--
                              Matches the tree's own tool visibility (`OrgTreeNode.vue`): the root can't be
                              removed from a UI control here either, so the two affordances agree.
                            -->
                            <Button
                                v-if="selectedUnit.parent_id !== null"
                                type="button"
                                variant="outline"
                                data-testid="delete-unit"
                                class="text-destructive hover:text-destructive"
                                @click="requestDelete(selectedUnit.id)"
                            >
                                Delete {{ selectedUnit.type }}
                            </Button>
                            <span v-else></span>
                            <div class="flex flex-1 items-center justify-end gap-2">
                                <p v-if="deleteError" data-testid="delete-error" role="alert" class="text-xs text-destructive">{{ deleteError }}</p>
                                <Button type="button" variant="ghost" data-testid="cancel-edit" @click="cancelEdit">Cancel</Button>
                                <Button type="submit" data-testid="save-unit" :disabled="editForm.processing">Save unit</Button>
                            </div>
                        </div>
                    </form>

                    <dl v-else class="grid grid-cols-2 gap-3 text-[13px]">
                        <div>
                            <dt class="text-xs text-muted-foreground">Name</dt>
                            <dd>{{ selectedUnit.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Callsign</dt>
                            <dd class="font-mono">{{ selectedUnit.callsign ?? 'None' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Echelon</dt>
                            <dd class="capitalize">{{ selectedUnit.type }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">Troopers</dt>
                            <dd class="tabular-nums">{{ selectedUnit.trooper_count }}</dd>
                        </div>
                    </dl>
                </div>
                <p v-else class="text-[13px] text-muted-foreground">Select a unit to see its details.</p>

                <!--
                  Troopers the sync could not place (Story 2.6): a dashed panel in the detail card, one chip
                  per trooper. Shown only when the server sent the list, which it does for an Admin alone.
                  Read-only here; the "assign" action on each chip is Story 2.7.
                -->
                <section
                    v-if="unplacedTroopers !== null"
                    data-testid="unplaced-panel"
                    tabindex="-1"
                    aria-label="Troopers not yet placed"
                    class="mt-4 rounded-lg border border-dashed border-border p-3"
                >
                    <h3 v-if="unplacedTroopers.length" class="text-[13px] font-semibold">
                        <span data-testid="unplaced-count">
                            {{ unplacedCount }} synced {{ unplacedCount === 1 ? 'trooper' : 'troopers' }} not yet placed
                        </span>
                    </h3>
                    <EmptyState v-if="!unplacedTroopers.length" :icon="PhCheckCircle" title="All synced troopers are placed" class="py-4">
                        Troopers the sync cannot map to a unit will be listed here.
                    </EmptyState>
                    <p v-else-if="unplacedCount > unplacedTroopers.length" data-testid="unplaced-capped" class="mt-1 text-xs text-muted-foreground">
                        Showing the first {{ unplacedTroopers.length }}.
                    </p>
                    <!--
                      With no chain of command yet nothing can be mapped, so the whole roster lands here. Say why,
                      instead of leaving an Admin to assume the sync is broken.
                    -->
                    <p v-if="unplacedTroopers.length && !units.length" data-testid="unplaced-hint" class="mt-1 text-xs text-muted-foreground">
                        The chain of command is empty, so no trooper can be mapped yet. Import it from the Cav roster first.
                    </p>
                    <!-- Scrolls inside the card: right after a fresh install the list can hold the whole roster. -->
                    <ul v-if="unplacedTroopers.length" aria-label="Troopers not yet placed" class="mt-2.5 flex max-h-72 flex-wrap gap-2 overflow-y-auto">
                        <li
                            v-for="trooper in unplacedTroopers"
                            :key="trooper.id"
                            data-testid="unplaced-trooper"
                            :class="['min-w-0 rounded-md border border-border px-2.5 py-1.5 text-[13px]', assigningId === trooper.id && 'w-full']"
                        >
                            <span class="font-medium">
                                <span v-if="trooper.rank" class="text-muted-foreground">{{ trooper.rank }}</span>
                                {{ trooper.username ?? `Unknown (Cav #${trooper.cav_id})` }}
                            </span>
                            <span class="block max-w-[28ch] truncate text-xs text-muted-foreground" :title="billetLabel(trooper)">
                                <span v-if="trooper.mos" class="font-mono">{{ trooper.mos }} · </span>{{ billetLabel(trooper) }}
                            </span>

                            <!--
                              Place this trooper into a section by hand (Story 2.7). The server is the boundary:
                              Admin only, a required reason, and an audited write. This is the convenience layer.
                            -->
                            <template v-if="assigningId !== trooper.id">
                                <Button
                                    v-if="sections.length"
                                    :id="`assign-button-${trooper.id}`"
                                    type="button"
                                    variant="link"
                                    size="sm"
                                    class="mt-1 h-auto p-0 text-xs"
                                    data-testid="assign-trooper"
                                    @click="openAssign(trooper)"
                                >
                                    Assign <PhCaretDown class="size-3" aria-hidden="true" />
                                </Button>
                                <!-- A disabled button takes no hover or focus, so the focusable wrapper carries the reason. -->
                                <TooltipProvider v-else :delay-duration="200">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span tabindex="0" class="mt-1 inline-flex items-center gap-0.5 text-xs font-medium text-muted-foreground">
                                                <Button type="button" variant="link" size="sm" class="h-auto p-0 text-xs" data-testid="assign-trooper" disabled>
                                                    Assign <PhCaretDown class="size-3" aria-hidden="true" />
                                                </Button>
                                                <span class="sr-only" data-testid="assign-disabled-reason">There are no units to place into yet</span>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>There are no units to place into yet</TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </template>
                            <form v-else data-testid="assign-form" class="mt-2 space-y-2.5 border-t border-border pt-2.5" @submit.prevent="submitAssign(trooper)">
                                <div class="grid gap-1">
                                    <Label :for="`assign-section-${trooper.id}`">Unit</Label>
                                    <NativeSelect :id="`assign-section-${trooper.id}`" v-model="assignSection" data-testid="assign-section">
                                        <option :value="null">Select a unit</option>
                                        <option v-for="section in sections" :key="section.id" :value="section.id">{{ section.label }}</option>
                                    </NativeSelect>
                                </div>
                                <div class="grid gap-1">
                                    <Label :for="`assign-reason-${trooper.id}`">Reason</Label>
                                    <Input
                                        :id="`assign-reason-${trooper.id}`"
                                        v-model="assignReason"
                                        data-testid="assign-reason"
                                        type="text"
                                        maxlength="500"
                                        placeholder="Why this unit, e.g. who confirmed it"
                                    />
                                </div>
                                <p v-if="assignError" data-testid="assign-error" role="alert" class="text-xs text-destructive">{{ assignError }}</p>
                                <div class="flex items-center justify-end gap-2">
                                    <Button type="button" variant="ghost" size="sm" data-testid="assign-cancel" @click="cancelAssign">Cancel</Button>
                                    <Button type="submit" size="sm" data-testid="assign-submit" :disabled="assigning || assignSection === null || !assignReason.trim()">
                                        {{ assigning ? 'Placing…' : 'Place trooper' }}
                                    </Button>
                                </div>
                            </form>
                        </li>
                    </ul>
                </section>

                <!--
                  Add-node control, Admin-only (AC #2). Hidden for a non-Admin per the mockup; the
                  server enforces the same gate on `store` regardless (AC #5, AD-6).
                -->
                <template v-if="isAdmin">
                    <Separator class="my-4" />
                    <form data-testid="add-unit-form" class="space-y-3" @submit.prevent="submitAdd">
                        <h3 class="flex items-center gap-1.5 text-[13px] font-semibold"><PhPlus aria-hidden="true" />Add unit</h3>

                        <div class="grid gap-1.5">
                            <Label for="add-type">Echelon</Label>
                            <NativeSelect id="add-type" v-model="form.type" class="capitalize" :aria-invalid="!!form.errors.type">
                                <option v-for="type in unitTypes" :key="type" :value="type">{{ type }}</option>
                            </NativeSelect>
                            <p v-if="form.errors.type" class="text-xs text-destructive" data-testid="error-type">{{ form.errors.type }}</p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="add-name">Name</Label>
                            <Input id="add-name" v-model="form.name" type="text" :aria-invalid="!!form.errors.name" />
                            <p v-if="form.errors.name" class="text-xs text-destructive" data-testid="error-name">{{ form.errors.name }}</p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="add-callsign">Callsign</Label>
                            <Input id="add-callsign" v-model="form.callsign" type="text" class="font-mono" :aria-invalid="!!form.errors.callsign" />
                            <p v-if="form.errors.callsign" class="text-xs text-destructive" data-testid="error-callsign">{{ form.errors.callsign }}</p>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="add-parent">Parent</Label>
                            <NativeSelect
                                id="add-parent"
                                v-model="form.parent_id"
                                :disabled="form.type === 'regiment'"
                                :aria-invalid="!!form.errors.parent_id"
                            >
                                <option :value="null">{{ form.type === 'regiment' ? 'None, this is the root' : 'Choose a parent' }}</option>
                                <option v-for="unit in legalParents" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                            </NativeSelect>
                            <p v-if="form.errors.parent_id" class="text-xs text-destructive" data-testid="error-parent_id">{{ form.errors.parent_id }}</p>
                        </div>

                        <Button type="submit" :disabled="form.processing">Save unit</Button>
                    </form>
                </template>
            </Card>
        </div>
    </div>
</template>
