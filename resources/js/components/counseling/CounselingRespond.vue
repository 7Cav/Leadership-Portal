<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { PhCheck } from '@phosphor-icons/vue';
import PmConsent from '@/components/counseling/PmConsent.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useNow } from '@/composables/useNow';
import { fieldLimit, timeLeft, zulu } from '@/lib/counseling';
import { onHttpException } from '@/lib/httpError';
import { toast } from 'vue-sonner';

// The trooper's part of a shared record (#565), submitted once: their plan of action and comments, and on an LOR
// whether they DO or DO NOT wish to appeal, with their reason when they do. Asking to appeal sends a PM to the appeal
// authority from the trooper's own forum account, so that choice needs their permission for PMs first.
const props = defineProps({
    record: { type: Object, required: true },
    appealAuthority: { type: String, default: null },
    limits: { type: Object, required: true },
    pm: { type: Object, required: true },
});

const lor = computed(() => props.record.type === 'lor');
const now = useNow();
const left = computed(() => timeLeft(props.record.respondBy, now.value));

const form = useForm({ plan_trooper: '', comments_trooper: '', appeal: null, appeal_reason: '' });
const needsPm = computed(() => lor.value && form.appeal === 'do' && !props.pm.allowed);
const blocked = computed(() => form.processing || needsPm.value || (lor.value && !form.appeal));

const CHOICES = computed(() => [
    { value: 'do_not', title: 'I do not wish to appeal', detail: 'The letter locks as written.' },
    {
        value: 'do',
        title: 'I wish to appeal',
        detail: props.appealAuthority ? `${props.appealAuthority} decides within 72 hours.` : 'The commander one level up decides within 72 hours.',
    },
]);

function submit() {
    if (lor.value && form.appeal === 'do' && !form.appeal_reason.trim()) {
        form.setError('appeal_reason', 'Say why you appeal.');
        return;
    }
    form.transform((data) =>
        lor.value
            ? { plan_trooper: data.plan_trooper, comments_trooper: data.comments_trooper, appeal: data.appeal, appeal_reason: data.appeal === 'do' ? data.appeal_reason : '' }
            : { plan_trooper: data.plan_trooper, comments_trooper: data.comments_trooper },
    );
    form.post(`/counseling/${props.record.id}/respond`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Response submitted', { description: 'Your leader and S1 have been told.' }),
        onHttpException: onHttpException('Your response was not submitted', (message) => toast.error(message)),
    });
}
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="counseling-respond">
        <div class="flex flex-wrap items-center gap-x-8 gap-y-2 rounded-xl border border-border bg-card px-4 py-3" data-testid="respond-countdown">
            <div>
                <p class="text-xs text-muted-foreground">{{ lor ? 'Answer whether you appeal by' : 'Add your part by' }} {{ zulu(record.respondBy) }}</p>
                <p class="text-lg font-semibold tabular-nums">{{ left ? `${left} left` : 'Time is up' }}</p>
            </div>
            <p class="min-w-0 flex-1 text-[13px] text-muted-foreground">
                Without an answer the {{ lor ? 'letter' : 'statement' }} stands as written. It locks 72 hours after it was shared.
            </p>
        </div>

        <slot />

        <form class="overflow-hidden rounded-xl border border-border bg-card" aria-labelledby="your-part-title" data-testid="respond-form" @submit.prevent="submit">
            <div class="flex items-center gap-2 border-b border-border px-4 py-3">
                <h2 id="your-part-title" class="text-[15px] font-semibold">Your part</h2>
                <span class="ml-auto text-xs text-muted-foreground">You submit once</span>
            </div>
            <div class="flex flex-col gap-4 px-4 py-4">
                <div class="grid gap-1.5">
                    <Label for="respond-plan">Agreed plan of action: trooper</Label>
                    <Textarea id="respond-plan" v-model="form.plan_trooper" rows="3" :maxlength="fieldLimit('plan_trooper', limits)" placeholder="What you will do" data-testid="respond-plan" />
                    <p v-if="form.errors.plan_trooper" class="text-xs text-destructive">{{ form.errors.plan_trooper }}</p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="respond-comments">Any comments</Label>
                    <Textarea id="respond-comments" v-model="form.comments_trooper" rows="3" :maxlength="fieldLimit('comments_trooper', limits)" placeholder="Optional" data-testid="respond-comments" />
                    <p v-if="form.errors.comments_trooper" class="text-xs text-destructive">{{ form.errors.comments_trooper }}</p>
                </div>

                <fieldset v-if="lor" class="grid gap-2" data-testid="respond-appeal">
                    <legend class="mb-1 text-[13px] font-medium">Do you wish to appeal this letter?</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="choice in CHOICES"
                            :key="choice.value"
                            class="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 text-[13px] has-[:focus-visible]:ring-3 has-[:focus-visible]:ring-ring/50"
                            :class="form.appeal === choice.value ? 'border-primary bg-muted/60' : 'border-border'"
                        >
                            <input v-model="form.appeal" type="radio" name="respond-appeal" class="mt-0.5 size-4 accent-primary" :value="choice.value" :data-testid="`respond-appeal-${choice.value}`" />
                            <span>
                                <span class="block font-medium">{{ choice.title }}</span>
                                <span class="block text-xs text-muted-foreground">{{ choice.detail }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.appeal" class="text-xs text-destructive">{{ form.errors.appeal }}</p>
                </fieldset>

                <div v-if="lor && form.appeal === 'do'" class="grid gap-1.5">
                    <Label for="respond-reason">Why you appeal</Label>
                    <Textarea
                        id="respond-reason"
                        v-model="form.appeal_reason"
                        rows="3"
                        :maxlength="limits.reason"
                        required
                        aria-describedby="respond-reason-help"
                        :aria-invalid="!!form.errors.appeal_reason"
                        data-testid="respond-reason"
                    />
                    <p id="respond-reason-help" class="text-xs text-muted-foreground">Required. Say why you appeal, so the commander can decide.</p>
                    <p v-if="form.errors.appeal_reason" class="text-xs text-destructive" data-testid="respond-reason-error">{{ form.errors.appeal_reason }}</p>
                </div>

                <PmConsent :pm="pm" :asks="lor && form.appeal === 'do'" :consent-error="form.errors.pm_consent" :pm-error="form.errors.pm" sends="your appeal" />
            </div>
            <div class="flex flex-wrap items-center gap-3 border-t border-border px-4 py-3">
                <p class="min-w-0 flex-1 text-xs text-muted-foreground">Your leader and S1 are told when you submit.</p>
                <Button type="submit" :disabled="blocked" data-testid="respond-submit"><PhCheck aria-hidden="true" />Submit response</Button>
            </div>
        </form>
    </div>
</template>
