<script setup>
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchSelect from '@/components/SearchSelect.vue';
import { postBillet } from '@/lib/billets';

// Place a billet in a unit: pick the unit for a fixed billet (`positionId`), or the billet for a fixed unit (`unitId`,
// a unit's Add billet). A reason is required. When the server says the billet is placed in another unit by an Admin,
// the form asks before sending it again as a move.
const props = defineProps({
    positionId: { type: Number, default: null },
    unitId: { type: Number, default: null },
    // `{ value, label }` rows: units for a fixed billet, billets for a fixed unit.
    options: { type: Array, required: true },
    pickLabel: { type: String, required: true },
    submitLabel: { type: String, default: 'Place billet' },
    testid: { type: String, default: 'billet-assign' },
});

const emit = defineEmits(['done', 'cancel']);

const picked = ref(null);
const reason = ref('');
const error = ref(null);
const move = ref(null);
const sending = ref(false);

const target = computed(() => (props.positionId === null ? { position: picked.value, unit: props.unitId } : { position: props.positionId, unit: picked.value }));
const ready = computed(() => !sending.value && target.value.position !== null && target.value.unit !== null && reason.value.trim() !== '');

function send(confirmMove = false) {
    if (!ready.value) return;
    error.value = null;
    sending.value = true;
    postBillet(
        `/settings/org-structure/billets/${target.value.position}/assign`,
        { unit_id: target.value.unit, reason: reason.value.trim(), ...(confirmMove ? { confirm_move: true } : {}) },
        {
            failed: 'The billet was not placed',
            onDone: () => {
                move.value = null;
                emit('done');
            },
            onError: (message, needsMove) => {
                if (needsMove) move.value = message;
                else error.value = message;
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}

function pick(value) {
    picked.value = value;
    move.value = null;
}
</script>

<template>
    <form :data-testid="testid" class="mt-2 space-y-2.5 border-t border-border pt-2.5" @submit.prevent="send(false)">
        <div class="grid gap-1">
            <Label :for="`${testid}-pick`">{{ pickLabel }}</Label>
            <SearchSelect
                :id="`${testid}-pick`"
                :model-value="picked"
                :options="options"
                :placeholder="`Select a ${pickLabel.toLowerCase()}`"
                search-placeholder="Search"
                empty-text="Nothing matches that search."
                :testid="`${testid}-pick`"
                content-class="w-96 max-w-[calc(100vw-2rem)]"
                @update:model-value="pick"
            />
        </div>
        <div class="grid gap-1">
            <Label :for="`${testid}-reason`">Reason</Label>
            <Input :id="`${testid}-reason`" v-model="reason" :data-testid="`${testid}-reason`" type="text" maxlength="500" placeholder="Why this unit, such as who confirmed it" />
        </div>
        <p v-if="error" :data-testid="`${testid}-error`" role="alert" class="text-xs text-destructive">{{ error }}</p>
        <div v-if="move" :data-testid="`${testid}-move`" role="alert" class="rounded-md border border-warning/40 bg-warning/8 px-2.5 py-2 text-xs">
            <p>{{ move }}</p>
            <div class="mt-2 flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" @click="move = null">Keep it there</Button>
                <Button type="button" size="sm" :data-testid="`${testid}-move-confirm`" :disabled="sending" @click="send(true)">Move it here</Button>
            </div>
        </div>
        <div v-else class="flex items-center justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" :data-testid="`${testid}-cancel`" @click="emit('cancel')">Cancel</Button>
            <Button type="submit" size="sm" :data-testid="`${testid}-submit`" :disabled="!ready">{{ sending ? 'Placing' : submitLabel }}</Button>
        </div>
    </form>
</template>
