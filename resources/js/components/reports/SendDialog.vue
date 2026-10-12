<script setup>
import { computed, ref, watch } from 'vue';
import { PhCircleNotch, PhLockSimple, PhPaperPlaneTilt, PhWarning } from '@phosphor-icons/vue';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { KPIS, formatKpi, kpiChange, unitLabel } from '@/lib/report';
import DeltaBadge from './DeltaBadge.vue';

// The pre-send overview (5.5): exactly what will freeze — the six KPIs with their change, the
// training subject and the notes — plus anything still open or missing, and the reason the send
// is recorded with (AD-16). Freezing is internal to the portal; nothing is posted anywhere
// (AD-10). Cancelling changes nothing.
const props = defineProps({
    open: { type: Boolean, required: true },
    unit: { type: Object, required: true },
    kpis: { type: Object, default: null },
    delta: { type: Object, default: null },
    content: { type: Object, required: true },
    openChecks: { type: Array, default: () => [] },
    missingUnits: { type: Array, default: () => [] },
    defaultReason: { type: String, required: true },
    processing: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:open', 'confirm']);

const reason = ref(props.defaultReason);
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) reason.value = props.defaultReason;
    },
);

const anyway = computed(() => props.openChecks.length > 0 || props.missingUnits.length > 0);
const destination = computed(() => (props.unit.parent ? unitLabel(props.unit.parent) : 'command'));
const blank = computed(() => !reason.value.trim());
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent class="gap-0 p-0 sm:max-w-lg" data-testid="send-dialog">
            <div class="max-h-[70dvh] overflow-y-auto p-5 pb-4">
                <div class="flex items-start gap-3">
                    <span class="grid size-9 flex-none place-items-center rounded-md border border-border text-lg" aria-hidden="true">
                        <PhLockSimple />
                    </span>
                    <div class="min-w-0">
                        <AlertDialogTitle class="text-base leading-snug font-semibold">
                            {{ anyway ? 'Send this report with items open?' : 'Freeze and send this report?' }}
                        </AlertDialogTitle>
                        <AlertDialogDescription class="mt-1 text-[13px] text-muted-foreground">
                            The draft becomes a fixed snapshot in the portal for {{ destination }}. Nothing is posted outside the
                            portal, and you cannot edit the report afterwards.
                        </AlertDialogDescription>
                    </div>
                </div>

                <div
                    v-if="anyway"
                    class="mt-4 flex items-start gap-2.5 rounded-md border border-warning/35 bg-warning/8 px-3 py-2.5 text-[13px]"
                    data-testid="send-warning"
                >
                    <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                    <div class="space-y-1">
                        <p v-if="openChecks.length">Still open: {{ openChecks.map((c) => c.title.toLowerCase()).join(', ') }}.</p>
                        <p v-if="missingUnits.length">
                            Holding up the chain: {{ missingUnits.join(', ') }}. Their numbers stay out of this report.
                        </p>
                    </div>
                </div>

                <dl v-if="kpis" class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-[13px] sm:grid-cols-3" data-testid="send-kpis">
                    <div v-for="kpi in KPIS" :key="kpi.key">
                        <dt class="text-xs text-muted-foreground">{{ kpi.label }}</dt>
                        <dd class="flex items-baseline gap-1.5">
                            <span class="font-semibold tabular-nums">{{ formatKpi(kpis, kpi) }}</span>
                            <DeltaBadge :change="kpiChange(delta, kpi)" />
                        </dd>
                    </div>
                </dl>

                <dl class="mt-4 grid grid-cols-[7.5rem_minmax(0,1fr)] gap-y-2 text-[13px]">
                    <template v-if="unit.isLeaf">
                        <dt class="text-muted-foreground">Trained</dt>
                        <dd>{{ content.trainingSubject?.trim() || 'Not filled in' }}</dd>
                    </template>
                    <dt class="text-muted-foreground">Notes</dt>
                    <dd class="line-clamp-4 whitespace-pre-line">{{ content.notes?.trim() || 'None' }}</dd>
                    <dt class="text-muted-foreground">After sending</dt>
                    <dd>Read-only. Later rollcall edits do not change it.</dd>
                </dl>

                <div class="mt-4 space-y-1.5">
                    <Label for="send-reason" class="text-[13px]">Reason for the record</Label>
                    <Textarea
                        id="send-reason"
                        v-model="reason"
                        rows="2"
                        aria-describedby="send-reason-help"
                        :aria-invalid="blank || !!error"
                    />
                    <p id="send-reason-help" class="text-xs text-muted-foreground">Saved in the audit log with the snapshot.</p>
                    <p v-if="error" class="text-xs text-destructive" role="alert">{{ error }}</p>
                </div>
            </div>

            <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2 border-t border-border px-5 py-4">
                <AlertDialogCancel :disabled="processing" class="mt-0">Keep editing</AlertDialogCancel>
                <Button :disabled="blank || processing" data-testid="send-confirm" @click="emit('confirm', reason.trim())">
                    <PhCircleNotch v-if="processing" class="animate-spin" aria-hidden="true" />
                    <PhPaperPlaneTilt v-else aria-hidden="true" />
                    {{ processing ? 'Freezing' : anyway ? 'Send anyway' : 'Freeze & send' }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
