<script setup>
import { computed, ref, watch } from 'vue';
import { PhCircleNotch } from '@phosphor-icons/vue';
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

// The app's confirm step for an action that cannot be taken back (the same AlertDialog as Settings, Templates), in
// place of the browser's own prompt() and confirm() boxes. With `requireReason` it asks for the reason the audit log
// keeps (AD-16) and will not confirm on a blank one. The page runs the request on `confirm` and passes back
// `processing` and any `error`, which shows inside the dialog so the Admin can fix it and try again.
const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    description: { type: String, default: null },
    confirmLabel: { type: String, required: true },
    pendingLabel: { type: String, default: null },
    requireReason: { type: Boolean, default: true },
    destructive: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    error: { type: String, default: null },
    testid: { type: String, default: 'reason-dialog' },
});

const emit = defineEmits(['update:open', 'confirm']);

const reason = ref('');
const reasonBlank = computed(() => reason.value.trim() === '');
const canConfirm = computed(() => !props.processing && (!props.requireReason || !reasonBlank.value));

// A fresh reason every time it opens, so one action's reason is never sent with the next.
watch(
    () => props.open,
    (open) => {
        if (open) reason.value = '';
    },
);

function close(open) {
    if (!open && !props.processing) emit('update:open', false);
}

function confirm() {
    if (canConfirm.value) emit('confirm', props.requireReason ? reason.value.trim() : null);
}
</script>

<template>
    <AlertDialog :open="open" @update:open="close">
        <AlertDialogContent class="sm:max-w-md" :data-testid="testid">
            <AlertDialogTitle class="text-base leading-snug font-semibold">{{ title }}</AlertDialogTitle>
            <AlertDialogDescription v-if="description" class="text-[13px] text-muted-foreground">{{ description }}</AlertDialogDescription>

            <form class="space-y-3" @submit.prevent="confirm">
                <div v-if="requireReason" class="space-y-1.5">
                    <Label :for="`${testid}-reason`" class="text-[13px]">Reason for the record</Label>
                    <Textarea
                        :id="`${testid}-reason`"
                        v-model="reason"
                        rows="2"
                        :data-testid="`${testid}-reason`"
                        :aria-describedby="`${testid}-reason-help`"
                        :aria-invalid="!!error"
                    />
                    <p :id="`${testid}-reason-help`" class="text-xs text-muted-foreground">Required. Saved in the audit log with the change.</p>
                </div>
                <p v-if="error" class="text-xs text-destructive" role="alert" :data-testid="`${testid}-error`">{{ error }}</p>

                <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2">
                    <AlertDialogCancel :disabled="processing" class="mt-0">Cancel</AlertDialogCancel>
                    <Button type="submit" :variant="destructive ? 'destructive' : 'default'" :disabled="!canConfirm" :data-testid="`${testid}-confirm`">
                        <PhCircleNotch v-if="processing" class="animate-spin" aria-hidden="true" />
                        {{ processing && pendingLabel ? pendingLabel : confirmLabel }}
                    </Button>
                </AlertDialogFooter>
            </form>
        </AlertDialogContent>
    </AlertDialog>
</template>
