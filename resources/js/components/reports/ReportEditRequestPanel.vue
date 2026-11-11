<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { PhCheck, PhCircleNotch, PhLockKey, PhPaperPlaneTilt, PhX } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { stamp } from '@/lib/report';

// A sent report past its deadline: ask a leader above to unlock it, see where the request stands, and, for a leader
// above, approve or deny it. Approving unlocks it until the coming Saturday 2359Z, and Edit report works again.
const props = defineProps({
    // `editRequest` from the server: { locked, editableUntil, canRequest, canDecide, latest }.
    editRequest: { type: Object, required: true },
    // Where "Request edit" posts: the report's own `/edit-request` address.
    requestUrl: { type: String, required: true },
});

const latest = computed(() => props.editRequest.latest);
const pending = computed(() => latest.value?.status === 'pending');
const approvedOpen = computed(() => latest.value?.status === 'approved' && !props.editRequest.locked);
const shown = computed(() => props.editRequest.locked || approvedOpen.value);

const asking = ref(false);
const reason = ref('');
const note = ref('');
const processing = ref(false);
const error = ref(null);

function post(url, data, done) {
    processing.value = true;
    error.value = null;
    router.post(url, data, {
        preserveScroll: true,
        onSuccess: done,
        onError: (errors) => {
            error.value = errors.reason ?? errors.note ?? errors.decision ?? errors.report ?? Object.values(errors)[0];
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const sendRequest = () =>
    post(props.requestUrl, { reason: reason.value.trim() }, () => {
        asking.value = false;
        reason.value = '';
    });
const decide = (approve) => post(`/report-edit-requests/${latest.value.id}/${approve ? 'approve' : 'deny'}`, { note: note.value.trim() || null }, () => (note.value = ''));
</script>

<template>
    <div v-if="shown" class="mt-4 rounded-lg border border-border px-3.5 py-3 text-[13px]" data-testid="edit-request">
        <p v-if="approvedOpen" class="text-muted-foreground" data-testid="edit-request-status">
            {{ latest.decidedBy }} approved the edit request. Open until {{ stamp(editRequest.editableUntil) }}.
        </p>

        <template v-else>
            <p class="flex items-center gap-1.5 font-medium"><PhLockKey class="size-4 text-muted-foreground" aria-hidden="true" />Locked at its deadline</p>

            <div v-if="pending" class="mt-1.5" data-testid="edit-request-status">
                <p>
                    {{ latest.requestedBy }} asked to edit it {{ stamp(latest.requestedAt) }}. Waiting on a leader above.
                </p>
                <p class="mt-1 text-muted-foreground whitespace-pre-line">{{ latest.reason }}</p>
            </div>
            <p v-else-if="latest?.status === 'denied'" class="mt-1.5 text-muted-foreground" data-testid="edit-request-status">
                {{ latest.decidedBy }} denied the edit request {{ stamp(latest.decidedAt) }}.<template v-if="latest.note"> {{ latest.note }}</template>
            </p>
            <p v-else class="mt-1.5 text-muted-foreground">To change it now, ask a leader above you to unlock it.</p>

            <div v-if="editRequest.canDecide" class="mt-3 space-y-2" data-testid="edit-request-decide">
                <Label for="edit-request-note" class="text-[13px]">Note for {{ latest.requestedBy }} (optional)</Label>
                <Textarea id="edit-request-note" v-model="note" rows="2" maxlength="500" :aria-invalid="!!error" />
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" :disabled="processing" data-testid="edit-request-approve" @click="decide(true)">
                        <PhCircleNotch v-if="processing" class="animate-spin" aria-hidden="true" /><PhCheck v-else aria-hidden="true" />Approve
                    </Button>
                    <Button size="sm" variant="outline" :disabled="processing" data-testid="edit-request-deny" @click="decide(false)">
                        <PhX aria-hidden="true" />Deny
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">Approving unlocks it until the coming Saturday 23&#8202;59Z.</p>
            </div>

            <template v-if="editRequest.canRequest">
                <Button v-if="!asking" variant="outline" size="sm" class="mt-3" data-testid="edit-request-open" @click="asking = true">
                    <PhPaperPlaneTilt aria-hidden="true" />Request edit
                </Button>
                <div v-else class="mt-3 space-y-2">
                    <Label for="edit-request-reason" class="text-[13px]">What needs to change, and why</Label>
                    <Textarea id="edit-request-reason" v-model="reason" rows="3" maxlength="1000" :aria-invalid="!!error" />
                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" :disabled="!reason.trim() || processing" data-testid="edit-request-send" @click="sendRequest">
                            <PhCircleNotch v-if="processing" class="animate-spin" aria-hidden="true" />Send request
                        </Button>
                        <Button size="sm" variant="ghost" :disabled="processing" @click="asking = false">Cancel</Button>
                    </div>
                    <p class="text-xs text-muted-foreground">The leaders one level above you are notified.</p>
                </div>
            </template>

            <p v-if="error" class="mt-2 text-xs text-destructive" role="alert">{{ error }}</p>
        </template>
    </div>
</template>
