<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import OrgTreeNode from './OrgTreeNode.vue';

// Flat units array with `path` (AD-18) — nested here for rendering; the server keeps the
// authoritative shape flat so subtree scoping elsewhere never depends on this tree walk.
const props = defineProps({
    units: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
    // Flashed once by the import action (Story 2.9):
    // `{ unitsCreated, mappingsCreated, mappingsDiffering, skippedTitles }`.
    importResult: { type: Object, default: null },
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
            deleteError.value = errors.delete ?? 'Delete failed — see server response.';
        },
    });
}
</script>

<template>
    <Head title="Settings · Organization structure · 7Cav Leadership Portal" />

    <div class="min-h-screen bg-bg p-6 text-white">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-gold">Settings · Organization structure</h1>
                <p class="mt-1 text-sm text-gray-400">
                    Author the chain of command: Regiment › Battalion › Company › Platoon › Section.
                </p>
            </div>
            <div v-if="isAdmin" class="flex items-center gap-2">
                <!--
                  Admin-only (Story 2.9). Hidden for everyone else; the server refuses a
                  non-Admin import regardless (AD-6) — this is convenience, not the boundary.
                -->
                <button
                    type="button"
                    data-testid="import-from-cav"
                    :disabled="importing"
                    class="rounded border border-gold-soft bg-panel px-3 py-1.5 text-sm font-medium text-gold disabled:opacity-50"
                    @click="runImport"
                >
                    {{ importing ? 'Importing…' : 'Import from Cav roster' }}
                </button>
                <span class="inline-flex items-center gap-1.5 rounded border border-gold-soft bg-panel px-2.5 py-1 text-xs text-gold">
                    🔒 Admin
                </span>
            </div>
        </div>

        <p v-if="importError" data-testid="import-error" role="alert" class="mb-4 rounded border border-bad bg-panel px-3 py-2 text-sm text-bad">
            {{ importError }}
        </p>
        <div v-else-if="importResult" data-testid="import-result" role="status" class="mb-4 rounded border border-border bg-panel px-3 py-2 text-sm text-gray-300">
            Imported from the Cav roster: {{ importResult.unitsCreated }} {{ importResult.unitsCreated === 1 ? 'node' : 'nodes' }} and
            {{ importResult.mappingsCreated }} section {{ importResult.mappingsCreated === 1 ? 'mapping' : 'mappings' }} created.
            <template v-if="importResult.mappingsDiffering">
                {{ importResult.mappingsDiffering }} existing {{ importResult.mappingsDiffering === 1 ? 'mapping points' : 'mappings point' }}
                at a different section than Cav now lists — kept as they are.
            </template>
            <template v-if="importResult.skippedTitles.length">
                {{ importResult.skippedTitles.length }} position {{ importResult.skippedTitles.length === 1 ? 'title' : 'titles' }} skipped
                (designation not recognised):
                <span class="font-mono text-gray-400">{{ importResult.skippedTitles.join(', ') }}</span>
            </template>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-lg border border-border bg-panel p-3">
                <h2 class="mb-2 px-2 text-sm font-medium text-gray-300">Chain of command</h2>
                <ul role="tree">
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
            </div>

            <div class="rounded-lg border border-border bg-panel p-4">
                <div v-if="selectedUnit" data-testid="detail-panel">
                    <h2 class="mb-3 text-sm font-medium text-gray-300">
                        Edit unit — {{ selectedUnit.name }}
                        <span class="ml-1.5 rounded border border-gold-soft px-1.5 py-0.5 text-[11px] capitalize text-gold">{{
                            selectedUnit.type
                        }}</span>
                    </h2>

                    <!--
                      Editable for an Admin (AC #1, #2); read-only otherwise. Echelon isn't
                      editable — see `UpdateUnit`'s docblock for why re-parenting is kept
                      separate from a rank change.
                    -->
                    <form v-if="isAdmin" data-testid="edit-unit-form" class="space-y-3" @submit.prevent="submitEdit">
                        <label class="block text-xs text-gray-400">
                            Name
                            <input v-model="editForm.name" data-testid="edit-name" type="text" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm" />
                            <p v-if="editForm.errors.name" class="mt-1 text-[11px] text-bad" data-testid="edit-error-name">
                                {{ editForm.errors.name }}
                            </p>
                        </label>

                        <label class="block text-xs text-gray-400">
                            Callsign
                            <input
                                v-model="editForm.callsign"
                                data-testid="edit-callsign"
                                type="text"
                                class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm font-mono"
                            />
                            <p v-if="editForm.errors.callsign" class="mt-1 text-[11px] text-bad" data-testid="edit-error-callsign">
                                {{ editForm.errors.callsign }}
                            </p>
                        </label>

                        <label class="block text-xs text-gray-400">
                            Parent
                            <select
                                v-model="editForm.parent_id"
                                data-testid="edit-parent_id"
                                :disabled="selectedUnit.type === 'regiment'"
                                class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm disabled:opacity-50"
                            >
                                <option :value="null">{{ selectedUnit.type === 'regiment' ? '— none (root) —' : '— choose a parent —' }}</option>
                                <option v-for="unit in editLegalParents" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                            </select>
                            <p v-if="editForm.errors.parent_id" class="mt-1 text-[11px] text-bad" data-testid="edit-error-parent_id">
                                {{ editForm.errors.parent_id }}
                            </p>
                        </label>

                        <div class="flex items-center justify-between border-t border-border pt-3">
                            <!--
                              Matches the tree's own tool visibility (`OrgTreeNode.vue`): the
                              root can't be removed via a UI control here either, so the two
                              affordances for the same node never disagree.
                            -->
                            <button
                                v-if="selectedUnit.parent_id !== null"
                                type="button"
                                data-testid="delete-unit"
                                class="rounded border border-bad px-3 py-1.5 text-sm font-medium text-bad"
                                @click="requestDelete(selectedUnit.id)"
                            >
                                Delete {{ selectedUnit.type }}
                            </button>
                            <span v-else></span>
                            <div class="flex flex-1 items-center justify-end gap-2">
                                <p v-if="deleteError" data-testid="delete-error" class="text-[11px] text-bad">{{ deleteError }}</p>
                                <button type="button" data-testid="cancel-edit" class="rounded px-3 py-1.5 text-sm text-gray-400" @click="cancelEdit">
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    data-testid="save-unit"
                                    :disabled="editForm.processing"
                                    class="rounded bg-gold px-3 py-1.5 text-sm font-medium text-bg disabled:opacity-50"
                                >
                                    Save unit
                                </button>
                            </div>
                        </div>
                    </form>

                    <dl v-else class="grid grid-cols-2 gap-3 text-[13px]">
                        <div>
                            <dt class="text-gray-500">Name</dt>
                            <dd>{{ selectedUnit.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Callsign</dt>
                            <dd class="font-mono">{{ selectedUnit.callsign ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Echelon</dt>
                            <dd class="capitalize">{{ selectedUnit.type }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Troopers</dt>
                            <dd>{{ selectedUnit.trooper_count }}</dd>
                        </div>
                    </dl>
                </div>
                <p v-else class="text-sm text-gray-500">Select a unit to see its details.</p>

                <!--
                  Add-node control, Admin-only (AC #2). Hidden here for a non-Admin per the
                  mockup, but the server enforces the same gate on `store` regardless (AC #5,
                  AD-6) — this is convenience, not the security boundary.
                -->
                <form
                    v-if="isAdmin"
                    data-testid="add-unit-form"
                    class="mt-5 space-y-3 border-t border-border pt-4"
                    @submit.prevent="submitAdd"
                >
                    <h3 class="text-sm font-medium text-gray-300">+ Add unit</h3>

                    <label class="block text-xs text-gray-400">
                        Echelon
                        <select v-model="form.type" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm">
                            <option v-for="type in unitTypes" :key="type" :value="type">{{ type }}</option>
                        </select>
                        <p v-if="form.errors.type" class="mt-1 text-[11px] text-bad" data-testid="error-type">
                            {{ form.errors.type }}
                        </p>
                    </label>

                    <label class="block text-xs text-gray-400">
                        Name
                        <input v-model="form.name" type="text" class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm" />
                        <p v-if="form.errors.name" class="mt-1 text-[11px] text-bad" data-testid="error-name">
                            {{ form.errors.name }}
                        </p>
                    </label>

                    <label class="block text-xs text-gray-400">
                        Callsign
                        <input
                            v-model="form.callsign"
                            type="text"
                            class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm font-mono"
                        />
                        <p v-if="form.errors.callsign" class="mt-1 text-[11px] text-bad" data-testid="error-callsign">
                            {{ form.errors.callsign }}
                        </p>
                    </label>

                    <label class="block text-xs text-gray-400">
                        Parent
                        <select
                            v-model="form.parent_id"
                            :disabled="form.type === 'regiment'"
                            class="mt-1 w-full rounded border border-border bg-bg px-2 py-1.5 text-sm disabled:opacity-50"
                        >
                            <option :value="null">{{ form.type === 'regiment' ? '— none (root) —' : '— choose a parent —' }}</option>
                            <option v-for="unit in legalParents" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                        </select>
                        <p v-if="form.errors.parent_id" class="mt-1 text-[11px] text-bad" data-testid="error-parent_id">
                            {{ form.errors.parent_id }}
                        </p>
                    </label>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded bg-gold px-3 py-1.5 text-sm font-medium text-bg disabled:opacity-50"
                    >
                        Save unit
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
