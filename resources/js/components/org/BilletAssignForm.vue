<script setup>
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SearchSelect from '@/components/SearchSelect.vue';
import { postBillet } from '@/lib/billets';

// Place a billet in a unit: pick the unit for a fixed billet (`positionId`), or the billet for a fixed unit (`unitId`,
// a unit's Add billet). A reason is required. When the server says the billet is placed in another unit by an Admin,
// the form asks before sending it again as a move; when it says the unit is of another level than the billet's title
// names (a section billet in a company, a company billet in a platoon), the form asks before placing it there anyway.
// Each step confirmed is sent again with the next try, so both can be asked.
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
// The step the server asks to confirm, `{ kind: 'move' | 'level', message }`, and the steps already confirmed.
const ask = ref(null);
const confirmed = ref({ move: false, level: false });
const sending = ref(false);

const target = computed(() => (props.positionId === null ? { position: picked.value, unit: props.unitId } : { position: props.positionId, unit: picked.value }));
const ready = computed(() => !sending.value && target.value.position !== null && target.value.unit !== null && reason.value.trim() !== '');

function send() {
    if (!ready.value) return;
    error.value = null;
    sending.value = true;
    postBillet(
        `/settings/org-structure/billets/${target.value.position}/assign`,
        {
            unit_id: target.value.unit,
            reason: reason.value.trim(),
            ...(confirmed.value.move ? { confirm_move: true } : {}),
            ...(confirmed.value.level ? { confirm_level: true } : {}),
        },
        {
            failed: 'The billet was not placed',
            onDone: () => {
                ask.value = null;
                emit('done');
            },
            onError: (message, step) => {
                if (step) ask.value = { kind: step, message };
                else error.value = message;
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}

function confirm() {
    confirmed.value = { ...confirmed.value, [ask.value.kind]: true };
    ask.value = null;
    send();
}

function pick(value) {
    picked.value = value;
    ask.value = null;
    confirmed.value = { move: false, level: false };
}
</script>

<template>
    <form :data-testid="testid" class="mt-2 space-y-2.5 border-t border-border pt-2.5" @submit.prevent="send">
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
        <div v-if="ask" :data-testid="`${testid}-${ask.kind}`" role="alert" class="rounded-md border border-warning/40 bg-warning/8 px-2.5 py-2 text-xs">
            <p>{{ ask.message }}</p>
            <div class="mt-2 flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" :data-testid="`${testid}-${ask.kind}-cancel`" @click="ask = null">{{ ask.kind === 'move' ? 'Keep it there' : 'Cancel' }}</Button>
                <Button type="button" size="sm" :data-testid="`${testid}-${ask.kind}-confirm`" :disabled="sending" @click="confirm">
                    {{ ask.kind === 'move' ? 'Move it here' : 'Place it anyway' }}
                </Button>
            </div>
        </div>
        <div v-else class="flex items-center justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" :data-testid="`${testid}-cancel`" @click="emit('cancel')">Cancel</Button>
            <Button type="submit" size="sm" :data-testid="`${testid}-submit`" :disabled="!ready">{{ sending ? 'Placing' : submitLabel }}</Button>
        </div>
    </form>
</template>
