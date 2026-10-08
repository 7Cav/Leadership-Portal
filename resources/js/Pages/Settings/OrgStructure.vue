<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { PhCaretDown, PhCheckCircle, PhCloudArrowDown, PhLockSimple, PhPlus, PhTreeStructure, PhWarningCircle } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Separator } from '@/components/ui/separator';
import EmptyState from '@/components/EmptyState.vue';
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
});

const tree = computed(() => {
    const byId = new Map(props.units.map((unit) => [unit.id, { ...unit, children: [] }]));
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

    return props.units.filter((unit) => RANK[unit.type] === RANK[selectedUnit.value.type] - 1);
});

function submitEdit() {
    editForm.patch(`/settings/org-structure/${selectedUnit.value.id}`, { preserveScroll: true });
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
// Manual placement of an unplaced trooper (Story 2.7). One chip is open at a time. The sections are
// the `section` echelon of the tree, listed by `path` so they read in chain-of-command order.
const sections = computed(() =>
    props.units
        .filter((unit) => unit.type === 'section')
        .sort((a, b) => a.path.localeCompare(b.path, undefined, { numeric: true }))
        .map((unit) => ({ id: unit.id, label: unit.callsign ? `${unit.callsign} · ${unit.name}` : unit.name })),
);
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
}

function cancelAssign() {
    assigningId.value = null;
    assignError.value = null;
}

function submitAssign(trooper) {
    assignError.value = null;
    assigning.value = true;
    router.post(
        `/settings/org-structure/troopers/${trooper.id}/placement`,
        { section_id: assignSection.value, reason: assignReason.value.trim() },
        {
            preserveScroll: true,
            onSuccess: cancelAssign,
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
                {{ importResult.mappingsCreated }} section {{ importResult.mappingsCreated === 1 ? 'mapping' : 'mappings' }} created.
                <template v-if="importResult.mappingsDiffering">
                    {{ importResult.mappingsDiffering }} existing {{ importResult.mappingsDiffering === 1 ? 'mapping points' : 'mappings point' }}
                    at a different section than Cav now lists, kept as they are.
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
                </div>
                <ul v-if="tree.length" role="tree" aria-label="Chain of command" class="px-2 pb-2">
                    <OrgTreeNode
                        v-for="node in tree"
                        :key="node.id"
                        :node="node"
                        :selected-id="selectedId"
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
                    </h2>

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
                    aria-labelledby="unplaced-heading"
                    class="mt-4 rounded-lg border border-dashed border-border p-3"
                >
                    <h3 id="unplaced-heading" class="text-[13px] font-semibold">
                        <template v-if="unplacedTroopers.length">
                            <span data-testid="unplaced-count">
                                {{ unplacedTroopers.length }} synced {{ unplacedTroopers.length === 1 ? 'trooper' : 'troopers' }} not yet placed
                            </span>
                        </template>
                        <template v-else>All synced troopers are placed</template>
                    </h3>
                    <p v-if="!unplacedTroopers.length" class="mt-1 text-xs text-muted-foreground">
                        Troopers the sync cannot map to a section will be listed here.
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
                            <span class="block max-w-[28ch] truncate text-xs text-muted-foreground">
                                <span v-if="trooper.mos" class="font-mono">{{ trooper.mos }} · </span>{{
                                    trooper.billet ?? (trooper.position_id !== null ? `Billet ${trooper.position_id}` : 'No billet reported')
                                }}
                            </span>

                            <!--
                              Place this trooper into a section by hand (Story 2.7). The server is the boundary:
                              Admin only, a required reason, and an audited write. This is the convenience layer.
                            -->
                            <button
                                v-if="assigningId !== trooper.id"
                                type="button"
                                data-testid="assign-trooper"
                                class="mt-1 inline-flex items-center gap-0.5 text-xs font-medium text-primary hover:underline disabled:cursor-not-allowed disabled:text-muted-foreground disabled:no-underline"
                                :disabled="!sections.length"
                                :title="sections.length ? undefined : 'There are no sections to place into yet'"
                                @click="openAssign(trooper)"
                            >
                                Assign <PhCaretDown class="size-3" aria-hidden="true" />
                            </button>
                            <form v-else data-testid="assign-form" class="mt-2 space-y-2.5 border-t border-border pt-2.5" @submit.prevent="submitAssign(trooper)">
                                <div class="grid gap-1">
                                    <Label :for="`assign-section-${trooper.id}`">Section</Label>
                                    <NativeSelect :id="`assign-section-${trooper.id}`" v-model="assignSection" data-testid="assign-section">
                                        <option :value="null">Choose a section</option>
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
                                        placeholder="Why this section, e.g. who confirmed it"
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
