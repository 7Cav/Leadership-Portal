<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import OrgTreeNode from './OrgTreeNode.vue';

// Flat units array with `path` (AD-18) — nested here for rendering; the server keeps the
// authoritative shape flat so subtree scoping elsewhere never depends on this tree walk.
const props = defineProps({
    units: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
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
            <span
                v-if="isAdmin"
                class="inline-flex items-center gap-1.5 rounded border border-gold-soft bg-panel px-2.5 py-1 text-xs text-gold"
            >
                🔒 Admin
            </span>
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
                        @select="(id) => (selectedId = id)"
                        @toggle="toggleCollapsed"
                    />
                </ul>
            </div>

            <div class="rounded-lg border border-border bg-panel p-4">
                <div v-if="selectedUnit" data-testid="detail-panel">
                    <h2 class="mb-3 text-sm font-medium text-gray-300">Unit — {{ selectedUnit.name }}</h2>
                    <dl class="grid grid-cols-2 gap-3 text-[13px]">
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
