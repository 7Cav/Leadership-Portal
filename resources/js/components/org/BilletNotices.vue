<script setup>
import { computed, ref } from 'vue';
import { PhWarning } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import ReasonDialog from '@/components/ReasonDialog.vue';
import BilletAssignForm from './BilletAssignForm.vue';
import { conflictUnitLabel, holdersLabel, postBillet } from '@/lib/billets';

// The billets waiting for an Admin at the top of Org structure (2026-11-13), the ones the sidebar counts: placed
// billets the Cav renamed (old → new, confirm the placement follows), billets in two units at once (acknowledge one
// that is meant to be there), and new billets nothing places (assign a unit, or leave unplaced). Hidden when nothing
// waits.
const props = defineProps({
    renamed: { type: Array, default: () => [] },
    conflicts: { type: Array, default: () => [] },
    newBillets: { type: Array, default: () => [] },
    // `{ value, label }` for every unit, for Assign.
    unitOptions: { type: Array, default: () => [] },
});

const emit = defineEmits(['select-unit']);

const total = computed(() => props.renamed.length + props.conflicts.length + props.newBillets.length);
const assigning = ref(null);
const confirming = ref(null);
const leaving = ref(null);
const leaveError = ref(null);
const leaveProcessing = ref(false);
const acknowledging = ref(null);
const acknowledgeError = ref(null);
const acknowledgeProcessing = ref(false);

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

function leave(reason) {
    const billet = leaving.value;
    if (!billet) return;
    leaveError.value = null;
    leaveProcessing.value = true;
    postBillet(`/settings/org-structure/billets/${billet.positionId}/leave-unplaced`, { reason }, {
        failed: 'The billet was not left unplaced',
        onDone: () => {
            leaving.value = null;
            toast.success('Billet left unplaced');
        },
        onError: (message) => {
            leaveError.value = message;
        },
        onFinish: () => {
            leaveProcessing.value = false;
        },
    });
}

function acknowledge(reason) {
    const conflict = acknowledging.value;
    if (!conflict) return;
    acknowledgeError.value = null;
    acknowledgeProcessing.value = true;
    postBillet(`/settings/org-structure/billets/${conflict.positionId}/acknowledge`, { kind: conflict.kind, reason }, {
        failed: 'The billet was not acknowledged',
        onDone: () => {
            acknowledging.value = null;
            toast.success('Acknowledged');
        },
        onError: (message) => {
            acknowledgeError.value = message;
        },
        onFinish: () => {
            acknowledgeProcessing.value = false;
        },
    });
}

function assigned() {
    assigning.value = null;
    toast.success('Billet placed');
}
</script>

<template>
    <Card v-if="total" data-testid="billet-notices" class="gap-0 border-warning/40 bg-warning/6 p-4">
        <h2 class="flex items-center gap-2 text-[15px] font-semibold">
            <PhWarning class="size-4 text-warning" aria-hidden="true" />
            {{ total }} {{ total === 1 ? 'billet needs' : 'billets need' }} an Admin
        </h2>

        <section v-if="renamed.length" class="mt-3" aria-labelledby="billets-renamed-heading" data-testid="billets-renamed">
            <h3 id="billets-renamed-heading" class="text-[13px] font-semibold">Renamed in the Cav</h3>
            <p class="text-xs text-muted-foreground">The placement stays as it is. Confirm it still fits the new name, or move the billet from its unit.</p>
            <ul class="mt-1.5 space-y-1 text-[13px]">
                <li v-for="billet in renamed" :key="billet.positionId" data-testid="billet-renamed" class="flex flex-wrap items-baseline gap-x-2">
                    <span>{{ billet.renamedFrom }} → <span class="font-medium">{{ billet.title }}</span></span>
                    <button type="button" class="text-xs text-muted-foreground underline-offset-2 hover:underline" @click="emit('select-unit', billet.unitId)">in {{ billet.unitLabel }}</button>
                    <Button type="button" variant="link" size="sm" class="h-auto p-0 text-xs" :disabled="confirming === billet.positionId" data-testid="billet-renamed-confirm" @click="confirmName(billet)">
                        Confirm name
                    </Button>
                </li>
            </ul>
        </section>

        <section v-if="conflicts.length" class="mt-3" aria-labelledby="billets-conflicts-heading" data-testid="billets-conflicts">
            <h3 id="billets-conflicts-heading" class="text-[13px] font-semibold">In two units at once</h3>
            <ul class="mt-1.5 space-y-1 text-[13px]">
                <li v-for="(conflict, index) in conflicts" :key="index" data-testid="billet-conflict">
                    <span class="font-medium">{{ conflict.title }}</span> is in {{ conflict.units.map(conflictUnitLabel).join(', ') }}.
                    <span class="text-muted-foreground">{{ conflict.uses }}</span>
                    <Button type="button" variant="link" size="sm" class="ml-2 h-auto p-0 text-xs" data-testid="billet-conflict-acknowledge" @click="acknowledging = conflict">
                        Acknowledge
                    </Button>
                </li>
            </ul>
        </section>

        <section v-if="newBillets.length" class="mt-3" aria-labelledby="billets-new-heading" data-testid="billets-new">
            <h3 id="billets-new-heading" class="text-[13px] font-semibold">New billets</h3>
            <p class="text-xs text-muted-foreground">No rule places these. Their holders are not placed until a billet has a unit.</p>
            <ul class="mt-1.5 max-h-72 space-y-1.5 overflow-y-auto text-[13px]">
                <li v-for="billet in newBillets" :key="billet.positionId" data-testid="billet-new" class="rounded-md border border-border bg-background px-2.5 py-1.5">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium">{{ billet.title }}</span>
                        <span v-if="billet.group" class="text-xs text-muted-foreground">{{ billet.group }}</span>
                        <span class="text-xs text-muted-foreground">{{ holdersLabel(billet.holders) }}</span>
                    </div>
                    <div v-if="assigning !== billet.positionId" class="mt-1 flex gap-3">
                        <Button type="button" variant="link" size="sm" class="h-auto p-0 text-xs" data-testid="billet-new-assign" @click="assigning = billet.positionId">Assign</Button>
                        <Button type="button" variant="link" size="sm" class="h-auto p-0 text-xs text-muted-foreground" data-testid="billet-new-leave" @click="leaving = billet">Leave unplaced</Button>
                    </div>
                    <BilletAssignForm
                        v-else
                        :position-id="billet.positionId"
                        :options="unitOptions"
                        pick-label="Unit"
                        testid="billet-new-form"
                        @done="assigned"
                        @cancel="assigning = null"
                    />
                </li>
            </ul>
        </section>

        <ReasonDialog
            :open="acknowledging !== null"
            title="Acknowledge this billet in two units?"
            :description="acknowledging ? `${acknowledging.title} stops showing here while it stays in these units. If it is added to another unit or taken out of one, it shows again.` : null"
            confirm-label="Acknowledge"
            pending-label="Saving"
            :processing="acknowledgeProcessing"
            :error="acknowledgeError"
            testid="billet-acknowledge-dialog"
            @update:open="(value) => !value && (acknowledging = null)"
            @confirm="acknowledge"
        />

        <ReasonDialog
            :open="leaving !== null"
            title="Leave this billet unplaced?"
            :description="leaving ? `${leaving.title} stops asking for a unit until the Cav renames it or lists it in another group.` : null"
            confirm-label="Leave unplaced"
            pending-label="Saving"
            :processing="leaveProcessing"
            :error="leaveError"
            testid="billet-leave-dialog"
            @update:open="(value) => !value && (leaving = null)"
            @confirm="leave"
        />
    </Card>
</template>
