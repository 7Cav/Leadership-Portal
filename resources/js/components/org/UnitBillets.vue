<script setup>
import { computed, ref, watch } from 'vue';
import { PhCaretDown, PhCaretRight, PhWarning } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import ReasonDialog from '@/components/ReasonDialog.vue';
import BilletAssignForm from './BilletAssignForm.vue';
import { conflictHeading, holdersLabel, joinAnd, otherUnitsLabel, placementUnits, postBillet, sameNameSentence, sourceLabel } from '@/lib/billets';

// The selected unit's billets on Org structure, for an Admin (2026-11-13): every billet whose holders land in this
// unit, how many hold it now, and why (a rule, an Admin's placement, or the unit it commands). An Admin adds a billet
// to the unit, moves one elsewhere or removes their own placement; a rule's placement is shown but follows the Cav. A
// billet in two units at once is warned about here, on every unit involved; what to do about it sits on the card at the
// top of the page. One an Admin marked fine reads "Also in DCOS2 · marked fine" instead, with Flag again.
const props = defineProps({
    unitId: { type: Number, required: true },
    billets: { type: Array, default: () => [] },
    conflicts: { type: Array, default: () => [] },
    // Every billet the Cav lists, `{ positionId, title, group, unitId }`, for Add billet.
    catalog: { type: Array, default: () => [] },
    // `{ value, label }` for every unit, for Move.
    unitOptions: { type: Array, default: () => [] },
});

const open = ref(false);
const moving = ref(null);
const adding = ref(false);
const removing = ref(null);
const removeError = ref(null);
const removeProcessing = ref(false);
const confirming = ref(null);
const undoing = ref(null);
const undoError = ref(null);
const undoProcessing = ref(false);

const activeConflicts = computed(() => props.conflicts.filter((conflict) => !conflict.acknowledged));
const acknowledgedConflicts = computed(() => props.conflicts.filter((conflict) => conflict.acknowledged));

// A fresh state for every unit selected.
watch(
    () => props.unitId,
    () => {
        moving.value = null;
        adding.value = false;
    },
);

const otherUnits = computed(() => props.unitOptions.filter((option) => option.value !== props.unitId));
const addOptions = computed(() =>
    props.catalog
        .filter((billet) => billet.unitId !== props.unitId)
        .map((billet) => ({ value: billet.positionId, label: billet.group ? `${billet.title} (${billet.group})` : billet.title })),
);

function done(message) {
    moving.value = null;
    adding.value = false;
    toast.success(message);
}

function confirmName(billet) {
    confirming.value = billet.positionId;
    postBillet(`/settings/org-structure/billets/${billet.positionId}/confirm-name`, {}, {
        failed: 'The name was not confirmed',
        onDone: () => toast.success('New name confirmed'),
        onError: (message) => toast.error(message),
        onFinish: () => {
            confirming.value = null;
        },
    });
}

function undo(reason) {
    const conflict = undoing.value;
    if (!conflict) return;
    undoError.value = null;
    undoProcessing.value = true;
    postBillet(`/settings/org-structure/billets/${conflict.positionId}/unacknowledge`, { kind: conflict.kind, reason }, {
        failed: 'The billet was not flagged again',
        onDone: () => {
            undoing.value = null;
            toast.success('Flagged again');
        },
        onError: (message) => {
            undoError.value = message;
        },
        onFinish: () => {
            undoProcessing.value = false;
        },
    });
}

// What a billet in two units is, in a line: the shared name, or the units it is set up in and where its holder sits.
function conflictSummary(conflict) {
    if (conflict.kind === 'title') return sameNameSentence(conflict);
    const units = placementUnits(conflict);
    return `${conflict.title} is set up in ${units.length === 2 ? 'both ' : ''}${joinAnd(units.map((at) => at.label))}. Its holder is in ${units[0].label} now.`;
}

function remove(reason) {
    const billet = removing.value;
    if (!billet) return;
    removeError.value = null;
    removeProcessing.value = true;
    postBillet(`/settings/org-structure/billets/${billet.positionId}/unassign`, { reason }, {
        failed: 'The placement was not removed',
        onDone: () => {
            removing.value = null;
            toast.success('Placement removed');
        },
        onError: (message) => {
            removeError.value = message;
        },
        onFinish: () => {
            removeProcessing.value = false;
        },
    });
}
</script>

<template>
    <section data-testid="unit-billets" aria-label="Billets" class="mb-4 rounded-lg border border-border p-3">
        <button
            type="button"
            class="flex w-full items-center gap-1.5 text-left text-[13px] font-semibold"
            :aria-expanded="open"
            aria-controls="unit-billets-list"
            data-testid="unit-billets-toggle"
            @click="open = !open"
        >
            <component :is="open ? PhCaretDown : PhCaretRight" class="size-3.5" aria-hidden="true" />
            Billets
            <span class="font-normal text-muted-foreground">{{ billets.length }}</span>
            <PhWarning v-if="activeConflicts.length" class="size-3.5 text-warning" aria-label="A billet here is in two units" data-testid="unit-billets-conflict-icon" />
        </button>

        <div v-show="open" id="unit-billets-list">
            <ul v-if="activeConflicts.length" class="mt-2 space-y-1.5" aria-label="Billets in two units">
                <li
                    v-for="(conflict, index) in activeConflicts"
                    :key="index"
                    data-testid="unit-billet-conflict"
                    role="alert"
                    class="rounded-md border border-warning/40 bg-warning/8 px-2.5 py-1.5 text-xs"
                >
                    <span class="font-medium">{{ conflictHeading(conflict) }}.</span> {{ conflictSummary(conflict) }} Sort it out in the card at the top of the page.
                </li>
            </ul>
            <ul v-if="acknowledgedConflicts.length" class="mt-2 space-y-1" aria-label="Billets in two units, marked fine">
                <li v-for="(conflict, index) in acknowledgedConflicts" :key="index" data-testid="unit-billet-acknowledged" class="flex flex-wrap items-baseline gap-x-2 text-xs text-muted-foreground">
                    <span>{{ conflict.title }}: also in {{ otherUnitsLabel(conflict, unitId) }} · marked fine</span>
                    <Button type="button" variant="link" size="sm" class="h-auto p-0 text-xs" data-testid="unit-billet-acknowledged-undo" @click="undoing = conflict">Flag again</Button>
                </li>
            </ul>

            <p v-if="!billets.length" class="mt-2 text-xs text-muted-foreground" data-testid="unit-billets-empty">No billet lands its holders in this unit.</p>
            <ul v-else class="mt-2 max-h-72 space-y-1.5 overflow-y-auto text-[13px]">
                <li v-for="billet in billets" :key="billet.positionId" data-testid="unit-billet" class="rounded-md border border-border px-2.5 py-1.5">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium">{{ billet.title }}</span>
                        <span class="text-xs text-muted-foreground">{{ holdersLabel(billet.holders) }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground" data-testid="unit-billet-source">{{ sourceLabel(billet) }}</p>
                    <p v-if="billet.renamedFrom" data-testid="unit-billet-renamed" class="mt-1 text-xs">
                        Renamed in the Cav: {{ billet.renamedFrom }} → {{ billet.title }}.
                        <Button type="button" variant="link" size="sm" class="h-auto p-0 text-xs" :disabled="confirming === billet.positionId" data-testid="unit-billet-confirm" @click="confirmName(billet)">
                            Confirm name
                        </Button>
                    </p>
                    <div v-if="moving !== billet.positionId && (billet.movable || billet.removable)" class="mt-1 flex gap-3">
                        <Button v-if="billet.movable" type="button" variant="link" size="sm" class="h-auto p-0 text-xs" data-testid="unit-billet-move" @click="moving = billet.positionId">
                            Move
                        </Button>
                        <Button v-if="billet.removable" type="button" variant="link" size="sm" class="h-auto p-0 text-xs" data-testid="unit-billet-remove" @click="removing = billet">
                            Remove
                        </Button>
                    </div>
                    <BilletAssignForm
                        v-if="moving === billet.positionId"
                        :position-id="billet.positionId"
                        :options="otherUnits"
                        pick-label="Unit"
                        submit-label="Move billet"
                        testid="unit-billet-move-form"
                        @done="done('Billet moved')"
                        @cancel="moving = null"
                    />
                </li>
            </ul>

            <Button v-if="!adding" type="button" variant="outline" size="sm" class="mt-2" data-testid="unit-billet-add" @click="adding = true">Add billet</Button>
            <BilletAssignForm
                v-else
                :unit-id="unitId"
                :options="addOptions"
                pick-label="Billet"
                submit-label="Add billet"
                testid="unit-billet-add-form"
                @done="done('Billet added')"
                @cancel="adding = false"
            />
            <p class="mt-1.5 text-xs text-muted-foreground" data-testid="unit-billets-footer">
                A billet you place here stays here, even if the Cav would put it elsewhere. People move on the next roster sync.
            </p>
        </div>

        <ReasonDialog
            :open="undoing !== null"
            title="Flag again?"
            :description="undoing ? `${undoing.title} shows again among the billets that need an Admin.` : null"
            confirm-label="Flag again"
            pending-label="Saving"
            :processing="undoProcessing"
            :error="undoError"
            testid="unit-billet-undo-dialog"
            @update:open="(value) => !value && (undoing = null)"
            @confirm="undo"
        />

        <ReasonDialog
            :open="removing !== null"
            title="Remove this placement?"
            :description="removing ? `${removing.title} goes back to where the import rules put it, or to no unit if no rule places it.` : null"
            confirm-label="Remove placement"
            pending-label="Removing"
            :processing="removeProcessing"
            :error="removeError"
            testid="unit-billet-remove-dialog"
            @update:open="(value) => !value && (removing = null)"
            @confirm="remove"
        />
    </section>
</template>
