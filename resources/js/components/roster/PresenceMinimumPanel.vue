<script setup>
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { PhTimer } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';
import { postJson } from '@/lib/postJson';

// A battalion's practice presence minimum (#558): how long a trooper must be on the practice's server inside its
// hour to count as attending, at their own section's practice or as a make-up at another's. Its HQ staff and Admins
// change it (`canEdit`); everyone else who opens the battalion reads it.
const props = defineProps({
    // `{ minutes, isDefault, default, canEdit }` from `PresenceMinimum::props()`.
    minimum: { type: Object, required: true },
    unitId: { type: Number, required: true },
});

const current = ref(props.minimum);
const minutes = ref(String(props.minimum.minutes));
const saving = ref(false);

watch(
    () => props.minimum,
    (value) => {
        current.value = value;
        minutes.value = String(value.minutes);
    },
);

async function send(value, success) {
    saving.value = true;
    try {
        const result = await postJson(`/roster/${props.unitId}/presence-minimum`, { minutes: value }).catch(unreachable);
        if (result.ok && result.data?.presenceMinimum) {
            current.value = result.data.presenceMinimum;
            minutes.value = String(current.value.minutes);
            toast.success(success);
        } else {
            toast.error('Could not change the presence minimum', { description: messageFor(result) });
        }
    } finally {
        saving.value = false;
    }
}

function save() {
    const value = Number.parseInt(minutes.value, 10);
    if (!Number.isInteger(value) || value < 1 || value > 60) {
        toast.error('Could not change the presence minimum', { description: 'Pick a minimum between 1 and 60 minutes.' });

        return;
    }
    send(value, 'Presence minimum saved');
}
</script>

<template>
    <section class="rounded-lg border border-border p-4" data-testid="presence-minimum">
        <h2 class="flex items-center gap-2 text-sm font-semibold">
            <PhTimer class="size-4" aria-hidden="true" />Practice presence minimum
        </h2>
        <p class="mt-1 text-sm text-muted-foreground" data-testid="presence-minimum-text">
            A trooper counts as at a section practice after {{ current.minutes }} minutes on its server during the hour<template
                v-if="current.isDefault"
            >, the default</template>.
        </p>
        <form v-if="current.canEdit" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="save">
            <div class="grid gap-1">
                <Label for="presence-minimum-minutes">Minutes</Label>
                <Input id="presence-minimum-minutes" v-model="minutes" type="number" min="1" max="60" class="w-24" />
            </div>
            <Button type="submit" size="sm" :disabled="saving">Save</Button>
            <Button
                v-if="!current.isDefault"
                type="button"
                size="sm"
                variant="outline"
                :disabled="saving"
                data-testid="presence-minimum-reset"
                @click="send(null, 'Presence minimum back to the default')"
            >
                Use the default ({{ current.default }})
            </Button>
        </form>
    </section>
</template>
