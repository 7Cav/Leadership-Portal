<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { PhFloppyDisk } from '@phosphor-icons/vue';
import ConsequenceFields from '@/components/counseling/ConsequenceFields.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { COUNSELING_TYPES, fieldLimit, leaderForm, todayUtc } from '@/lib/counseling';
import { onHttpException } from '@/lib/httpError';
import { toast } from 'vue-sonner';

// The fields of a record kept with its source PDF (an older upload or an import, #565), typed in from that PDF so the
// portal can count it and show it like any other: the type, what it is for, its date and the form's text, the trooper's
// part included. The PDF stays the record's document. POST counseling.review.
const props = defineProps({
    record: { type: Object, required: true },
    kinds: { type: Array, required: true },
    limits: { type: Object, required: true },
});

const stored = props.record.form ?? {};
const form = useForm({
    type: props.record.type,
    kind: props.record.kind ?? '',
    counseled_on: props.record.date ?? '',
    form: {
        ...leaderForm(stored),
        plan_trooper: typeof stored.plan_trooper === 'string' ? stored.plan_trooper : '',
        comments_trooper: typeof stored.comments_trooper === 'string' ? stored.comments_trooper : '',
        appeal: stored.appeal === 'do' || stored.appeal === 'do_not' ? stored.appeal : null,
    },
});
const lor = computed(() => form.type === 'lor');
const authority = computed(() => COUNSELING_TYPES[form.type].authority.toLowerCase());
const error = (key) => form.errors[`form.${key}`];

const TEXT = computed(() => [
    { key: 'infraction', label: 'Evidenced infraction and previous instances', rows: 3 },
    { key: 'regulation', label: 'Associated regulation, order or SOP', rows: 2 },
    { key: 'discussion', label: 'Notes of discussion', rows: 3 },
    { key: 'plan_authority', label: `Agreed plan of action: ${authority.value}`, rows: 2 },
    { key: 'plan_trooper', label: 'Agreed plan of action: trooper', rows: 2 },
    { key: 'plan_other', label: 'Agreed plan of action: misc / others', rows: 2 },
    { key: 'comments_authority', label: `Any comments: ${authority.value}`, rows: 2 },
    { key: 'comments_trooper', label: 'Any comments: trooper', rows: 2 },
    { key: 'comments_other', label: 'Any comments: misc / others', rows: 2 },
]);

function submit() {
    form.transform((data) => {
        const { consequences, removal, special_punishments, appeal, ...text } = data.form;

        return {
            type: data.type,
            kind: data.kind,
            counseled_on: data.counseled_on,
            form: data.type === 'lor' ? { ...text, consequences, removal: consequences.includes('removal') ? removal : null, special_punishments, appeal } : text,
        };
    });
    form.post(`/counseling/${props.record.id}/review`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => toast.success('Record reviewed'),
        onHttpException: onHttpException('The review was not saved', (message) => toast.error(message)),
    });
}
</script>

<template>
    <form class="overflow-hidden rounded-xl border border-border bg-card" aria-labelledby="review-title" data-testid="counseling-review" @submit.prevent="submit">
        <div class="border-b border-border px-4 py-3">
            <h2 id="review-title" class="text-[15px] font-semibold">Fill in from the PDF</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Copy what the PDF says, so the portal can count this record. The PDF stays the record's document.</p>
        </div>
        <div class="flex flex-col gap-4 px-4 py-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="review-type">Type</Label>
                    <NativeSelect id="review-type" v-model="form.type" data-testid="review-type">
                        <option v-for="(type, value) in COUNSELING_TYPES" :key="value" :value="value">{{ type.label }}</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-1.5">
                    <Label for="review-kind">What it is for</Label>
                    <NativeSelect id="review-kind" v-model="form.kind" required :aria-invalid="!!form.errors.kind" data-testid="review-kind">
                        <option value="" disabled>Choose one</option>
                        <option v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                    </NativeSelect>
                    <p v-if="form.errors.kind" class="text-xs text-destructive">{{ form.errors.kind }}</p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="review-date">Date of Letter</Label>
                    <Input id="review-date" v-model="form.counseled_on" type="date" :max="todayUtc()" required :aria-invalid="!!form.errors.counseled_on" data-testid="review-date" />
                    <p v-if="form.errors.counseled_on" class="text-xs text-destructive">{{ form.errors.counseled_on }}</p>
                </div>
            </div>

            <div v-for="field in TEXT" :key="field.key" class="grid gap-1.5">
                <Label :for="`review-${field.key}`">{{ field.label }}</Label>
                <Textarea :id="`review-${field.key}`" v-model="form.form[field.key]" :rows="field.rows" :maxlength="fieldLimit(field.key, limits)" :data-testid="`review-${field.key}`" />
                <p v-if="error(field.key)" class="text-xs text-destructive">{{ error(field.key) }}</p>
            </div>

            <template v-if="lor">
                <ConsequenceFields
                    id="review"
                    v-model:consequences="form.form.consequences"
                    v-model:removal="form.form.removal"
                    v-model:special-punishments="form.form.special_punishments"
                    :max="limits.long"
                    :errors="form.errors"
                />
                <fieldset class="grid gap-1.5" data-testid="review-appeal">
                    <legend class="mb-1 text-[13px] font-medium">The trooper</legend>
                    <label v-for="(label, value) in { do_not: 'Did not wish to appeal', do: 'Wished to appeal' }" :key="value" class="inline-flex items-center gap-2 text-[13px]">
                        <input v-model="form.form.appeal" type="radio" name="review-appeal" class="size-4 accent-primary" :value="value" :data-testid="`review-appeal-${value}`" />
                        {{ label }}
                    </label>
                    <p v-if="error('appeal')" class="text-xs text-destructive">{{ error('appeal') }}</p>
                </fieldset>
            </template>
        </div>
        <div class="flex justify-end border-t border-border px-4 py-3">
            <Button type="submit" :disabled="form.processing" data-testid="review-submit"><PhFloppyDisk aria-hidden="true" />Save review</Button>
        </div>
    </form>
</template>
