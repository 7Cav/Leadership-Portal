<script setup>
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { forumDate } from '@/lib/forumDate';
import { EXPIRY_PRESETS, endsOnIn, todayZulu } from '@/lib/grantExpiry';

// A grant's end date: none, ends for good, or lapses until renewed, with quick picks for 7, 30 and 90 days or any
// date. The date is a Zulu day; the grant counts until 23 59Z on it.
const mode = defineModel('mode', { type: String, default: 'none' });
const endsOn = defineModel('endsOn', { type: String, default: null });

const props = defineProps({
    idPrefix: { type: String, required: true },
    errors: { type: Object, default: () => ({}) },
});

const min = computed(() => todayZulu());

function pick(days) {
    endsOn.value = endsOnIn(days);
}
</script>

<template>
    <div class="grid gap-1.5">
        <Label :for="`${props.idPrefix}-mode`">End date</Label>
        <NativeSelect :id="`${props.idPrefix}-mode`" v-model="mode" :data-testid="`${props.idPrefix}-mode`" :aria-invalid="!!errors.mode">
            <option value="none">No end date</option>
            <option value="ends">Ends on a date</option>
            <option value="renew">Needs renewing by a date</option>
        </NativeSelect>
        <p v-if="errors.mode" class="text-xs text-destructive">{{ errors.mode }}</p>
    </div>

    <div v-if="mode !== 'none'" class="grid gap-1.5" :data-testid="`${props.idPrefix}-date`">
        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Quick picks">
            <Button
                v-for="days in EXPIRY_PRESETS"
                :key="days"
                type="button"
                size="sm"
                :variant="endsOn === endsOnIn(days) ? 'secondary' : 'outline'"
                :data-testid="`${props.idPrefix}-preset-${days}`"
                @click="pick(days)"
            >
                {{ days }} days
            </Button>
        </div>
        <Label :for="`${props.idPrefix}-ends-on`" class="sr-only">Date</Label>
        <Input
            :id="`${props.idPrefix}-ends-on`"
            v-model="endsOn"
            type="date"
            :min="min"
            :data-testid="`${props.idPrefix}-ends-on`"
            :aria-invalid="!!errors.ends_on"
        />
        <p class="text-xs text-muted-foreground" :data-testid="`${props.idPrefix}-hint`">
            <template v-if="endsOn">
                {{ mode === 'renew' ? 'Stops working' : 'Revoked' }} after 23 59Z on {{ forumDate(endsOn) }}{{ mode === 'renew' ? ' until someone renews it.' : '.' }}
            </template>
            <template v-else>Choose a quick pick or a date.</template>
        </p>
        <p v-if="errors.ends_on" class="text-xs text-destructive" :data-testid="`${props.idPrefix}-error`">{{ errors.ends_on }}</p>
    </div>
</template>
