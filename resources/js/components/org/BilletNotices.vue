<script setup>
import { computed, ref } from 'vue';
import { PhWarning } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import ReasonDialog from '@/components/ReasonDialog.vue';
import BilletAssignForm from './BilletAssignForm.vue';
import { conflictHeading, holdersLabel, joinAnd, placementBadge, placementUnits, postBillet, sameNameSentence } from '@/lib/billets';

// The billets waiting for an Admin at the top of Org structure (2026-11-13), the ones the sidebar counts: placed
// billets the Cav renamed (confirm the unit still fits), billets in two units at once, and new billets nothing places
// (assign a unit, or leave unplaced). Hidden when nothing waits.
//
// A billet in two units comes in two kinds, worded apart. Two Cav billets sharing one name (`title`): nobody is placed
// wrong, only the name may confuse, so the Admin marks it fine or renames or moves one. One billet placed in two units
// (`rule`): an Admin's placement against the automatic one. Keep in the Admin's unit marks it fine as it is; Keep in
// the automatic unit removes the Admin's placement, so the holder moves there on the next roster sync.
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
// `{ conflict, keep }`: keep is null for Fine as it is, or the unit `{ unitId, label, source }` to keep the billet in.
const resolving = ref(null);
const resolveError = ref(null);
const resolveProcessing = ref(false);

// Keep a billet placed in two units where its holder sits (the Admin's unit), or where the automatic placement puts it
// when removing the Admin's placement lands it there: the import's rule, or the unit it commands when no rule places it.
function keepOptions(conflict) {
    const units = placementUnits(conflict);
    const admin = units.find((at) => at.source === 'admin');
    const fallback = units.find((at) => at.source === 'rule') ?? units.find((at) => at.source === 'command');
    return [admin, fallback].filter(Boolean);
}

const dialog = computed(() => {
    const open = resolving.value;
    if (!open) return null;
    const { conflict, keep } = open;
    if (!keep) {
        return {
            title: 'Mark as fine?',
            description: `"${conflict.title}" stops being flagged until the units its billets are in change.`,
            confirm: 'Mark as fine',
            reasonLabel: 'Why is this fine?',
            placeholder: 'Two deputies hold this title, one in each group',
            reason: '',
        };
    }
    const admin = placementUnits(conflict).find((at) => at.source === 'admin');
    return {
        title: `Keep in ${keep.label}?`,
        description:
            keep.source === 'admin'
                ? `${conflict.title} stays in ${keep.label} and stops being flagged until its units change.`
                : `This removes the Admin's placement in ${admin?.label ?? 'the other unit'}. The holder moves to ${keep.label} on the next roster sync.`,
        confirm: `Keep in ${keep.label}`,
        reasonLabel: 'Reason for the record',
        placeholder: null,
        reason: `Kept in ${keep.label}`,
    };
});

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

function resolve(reason) {
    const open = resolving.value;
    if (!open) return;
    const { conflict, keep } = open;
    // Keeping it in the automatic unit removes the Admin's placement; anything else marks the conflict fine as it is.
    const unassign = keep && keep.source !== 'admin';
    const path = unassign ? 'unassign' : 'acknowledge';
    const data = unassign ? { reason } : { kind: conflict.kind, reason };
    resolveError.value = null;
    resolveProcessing.value = true;
    postBillet(`/settings/org-structure/billets/${conflict.positionId}/${path}`, data, {
        failed: keep ? 'The billet was not kept there' : 'The billet was not marked fine',
        onDone: () => {
            resolving.value = null;
            toast.success(keep ? `Kept in ${keep.label}` : 'Marked fine');
        },
        onError: (message) => {
            resolveError.value = message;
        },
        onFinish: () => {
            resolveProcessing.value = false;
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
            <p class="text-xs text-muted-foreground">They stay in their unit. Confirm the unit still fits the new name, or move the billet.</p>
            <ul class="mt-1.5 space-y-1.5 text-[13px]">
                <li v-for="billet in renamed" :key="billet.positionId" data-testid="billet-renamed" class="rounded-md border border-border bg-background px-3 py-2">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span>{{ billet.renamedFrom }} → <span class="font-medium">{{ billet.title }}</span></span>
                        <button type="button" class="text-xs text-muted-foreground underline-offset-2 hover:underline" @click="emit('select-unit', billet.unitId)">in {{ billet.unitLabel }}</button>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <Button type="button" variant="outline" size="sm" :disabled="confirming === billet.positionId" data-testid="billet-renamed-confirm" @click="confirmName(billet)">
                            Confirm name
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="conflicts.length" class="mt-3" aria-labelledby="billets-conflicts-heading" data-testid="billets-conflicts">
            <h3 id="billets-conflicts-heading" class="text-[13px] font-semibold">In two units</h3>
            <ul class="mt-1.5 space-y-1.5 text-[13px]">
                <li v-for="conflict in conflicts" :key="conflict.key ?? conflict.positionId" data-testid="billet-conflict" class="rounded-md border border-border bg-background px-3 py-2">
                    <p class="font-medium" data-testid="billet-conflict-heading">{{ conflictHeading(conflict) }}</p>

                    <template v-if="conflict.kind === 'title'">
                        <p class="mt-0.5" data-testid="billet-conflict-text">{{ sameNameSentence(conflict) }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Nobody is placed wrong, but rosters and reports show only the name, so people may mix them up. To tell them apart, rename one in the Cav or move it.
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Button type="button" variant="outline" size="sm" data-testid="billet-conflict-fine" @click="resolving = { conflict, keep: null }">Fine as it is</Button>
                            <Button
                                v-for="at in placementUnits(conflict)"
                                :key="at.unitId"
                                type="button"
                                variant="outline"
                                size="sm"
                                data-testid="billet-conflict-open"
                                @click="emit('select-unit', at.unitId)"
                            >
                                Open {{ at.label }}
                            </Button>
                        </div>
                    </template>

                    <template v-else>
                        <p class="mt-0.5" data-testid="billet-conflict-text">
                            {{ conflict.title }} is set up in {{ placementUnits(conflict).length === 2 ? 'both ' : '' }}<template v-for="(at, index) in placementUnits(conflict)" :key="at.unitId"
                                ><template v-if="index > 0">{{ index === placementUnits(conflict).length - 1 ? ' and ' : ', ' }}</template>{{ at.label }}
                                <Badge variant="outline" class="align-middle">{{ placementBadge(at) }}</Badge></template
                            >. Its holder can only be placed in one, and is in {{ placementUnits(conflict)[0].label }} now.
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Button
                                v-for="at in keepOptions(conflict)"
                                :key="at.unitId"
                                type="button"
                                variant="outline"
                                size="sm"
                                data-testid="billet-conflict-keep"
                                @click="resolving = { conflict, keep: at }"
                            >
                                Keep in {{ at.label }}
                            </Button>
                        </div>
                    </template>
                </li>
            </ul>
        </section>

        <section v-if="newBillets.length" class="mt-3" aria-labelledby="billets-new-heading" data-testid="billets-new">
            <h3 id="billets-new-heading" class="text-[13px] font-semibold">New billets</h3>
            <p class="text-xs text-muted-foreground">Nothing places these yet, so their holders are in no unit. Assign a unit, or leave the billet unplaced.</p>
            <ul class="mt-1.5 max-h-72 space-y-1.5 overflow-y-auto text-[13px]">
                <li v-for="billet in newBillets" :key="billet.positionId" data-testid="billet-new" class="rounded-md border border-border bg-background px-3 py-2">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium">{{ billet.title }}</span>
                        <span v-if="billet.group" class="text-xs text-muted-foreground">{{ billet.group }}</span>
                        <span class="text-xs text-muted-foreground">{{ holdersLabel(billet.holders) }}</span>
                    </div>
                    <div v-if="assigning !== billet.positionId" class="mt-2 flex flex-wrap gap-2">
                        <Button type="button" variant="outline" size="sm" data-testid="billet-new-assign" @click="assigning = billet.positionId">Assign</Button>
                        <Button type="button" variant="outline" size="sm" data-testid="billet-new-leave" @click="leaving = billet">Leave unplaced</Button>
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
            :open="resolving !== null"
            :title="dialog?.title ?? ''"
            :description="dialog?.description ?? null"
            :confirm-label="dialog?.confirm ?? ''"
            pending-label="Saving"
            :reason-label="dialog?.reasonLabel"
            :placeholder="dialog?.placeholder"
            :initial-reason="dialog?.reason ?? ''"
            :processing="resolveProcessing"
            :error="resolveError"
            testid="billet-resolve-dialog"
            @update:open="(value) => !value && (resolving = null)"
            @confirm="resolve"
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
