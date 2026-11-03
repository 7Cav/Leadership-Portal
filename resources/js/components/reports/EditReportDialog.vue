<script setup>
import { computed, ref, watch } from 'vue';
import { PhCircleNotch, PhPencilSimpleLine } from '@phosphor-icons/vue';
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
import { stamp } from '@/lib/report';

// "Edit report": reopens a sent report until its deadline, with the reason the audit log records (AD-16). The sent
// snapshot stays the record until the report is sent again. Cancelling changes nothing.
const props = defineProps({
    open: { type: Boolean, required: true },
    due: { type: String, required: true },
    processing: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:open', 'confirm']);

const reason = ref('');
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) reason.value = '';
    },
);

const blank = computed(() => !reason.value.trim());
</script>

<template>
    <AlertDialog :open="open" @update:open="emit('update:open', $event)">
        <AlertDialogContent class="gap-0 p-0 sm:max-w-md" data-testid="edit-dialog">
            <div class="p-5 pb-4">
                <AlertDialogTitle class="text-base leading-snug font-semibold">Edit this sent report?</AlertDialogTitle>
                <AlertDialogDescription class="mt-1 text-[13px] text-muted-foreground">
                    The week's roll call opens again. The sent version stays the record until you send the report again, which you
                    can do until {{ stamp(due) }}.
                </AlertDialogDescription>

                <div class="mt-4 space-y-1.5">
                    <Label for="edit-reason" class="text-[13px]">Reason for the record</Label>
                    <Textarea id="edit-reason" v-model="reason" rows="2" maxlength="1000" aria-describedby="edit-reason-help" :aria-invalid="!!error" />
                    <p id="edit-reason-help" class="text-xs text-muted-foreground">Saved in the audit log.</p>
                    <p v-if="error" class="text-xs text-destructive" role="alert">{{ error }}</p>
                </div>
            </div>

            <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2 border-t border-border px-5 py-4">
                <AlertDialogCancel :disabled="processing" class="mt-0">Cancel</AlertDialogCancel>
                <Button :disabled="blank || processing" data-testid="edit-confirm" @click="emit('confirm', reason.trim())">
                    <PhCircleNotch v-if="processing" class="animate-spin" aria-hidden="true" />
                    <PhPencilSimpleLine v-else aria-hidden="true" />
                    Edit report
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
