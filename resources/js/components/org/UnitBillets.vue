<script setup>
import { computed, ref, watch } from 'vue';
import { PhCaretDown, PhCaretRight, PhWarning } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import ReasonDialog from '@/components/ReasonDialog.vue';
import BilletAssignForm from './BilletAssignForm.vue';
import { conflictUnitLabel, holdersLabel, postBillet, sourceLabel } from '@/lib/billets';

// The selected unit's billets on Org structure, for an Admin (2026-11-13): every billet whose holders land in this
// unit, how many hold it now, and why (a rule, an Admin's placement, or the unit it commands). An Admin adds a billet
// to the unit, moves one elsewhere or removes their own placement; a rule's placement is shown but follows the Cav. A
// billet in two units at once is warned about here, on every unit involved, with the unit the sync uses.
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
            <PhWarning v-if="conflicts.length" class="size-3.5 text-warning" aria-label="A billet here is in two units" data-testid="unit-billets-conflict-icon" />
        </button>

        <div v-show="open" id="unit-billets-list">
            <ul v-if="conflicts.length" class="mt-2 space-y-1.5" aria-label="Billets in two units">
                <li
                    v-for="(conflict, index) in conflicts"
                    :key="index"
                    data-testid="unit-billet-conflict"
                    role="alert"
                    class="rounded-md border border-warning/40 bg-warning/8 px-2.5 py-1.5 text-xs"
                >
                    <span class="font-medium">{{ conflict.title }}</span> is in {{ conflict.units.map(conflictUnitLabel).join(', ') }}. {{ conflict.uses }}
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
            <p class="mt-1.5 text-xs text-muted-foreground">An Admin's placement beats the import rules. Holders move on the next roster sync.</p>
        </div>

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
