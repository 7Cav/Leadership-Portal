<script setup>
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { PhListChecks } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';
import { postJson } from '@/lib/postJson';

// What counts for a unit's accountability: its HQ staff report (battalion, company, platoon) or, on a section, its
// troopers' makeups. A unit with nothing of its own takes its echelon's default. Admins and Regimental HQ staff change a
// battalion's on the org structure page, and they and its battalion HQ staff the units under it (`canEdit`); everyone
// else reads it. Options with no effect on the unit yet (`pending`) show disabled as coming later.
const props = defineProps({
    // `{ kind, options, source, choices: [{ key, label, pending }], canEdit }` from `AccountabilityRules::props()`.
    accountability: { type: Object, required: true },
    unitId: { type: Number, required: true },
    // Inside a unit's group on its parent's Admin tab: no frame of its own.
    nested: { type: Boolean, default: false },
});

const current = ref(props.accountability);
const picked = ref([...props.accountability.options]);
const saving = ref(false);

watch(
    () => props.accountability,
    (value) => {
        current.value = value;
        picked.value = [...value.options];
    },
);

const title = computed(() => {
    if (current.value.kind === 'makeup') return 'What counts as a makeup';

    const hq = { battalion: 'Battalion', company: 'Company', platoon: 'Platoon' }[current.value.type];

    return hq ? `What counts for ${hq} HQ accountability` : 'What counts for staff accountability';
});
const labelOf = (key) => current.value.choices.find((choice) => choice.key === key)?.label ?? key;

function toggle(key, on) {
    picked.value = on ? [...new Set([...picked.value, key])] : picked.value.filter((value) => value !== key);
}

async function send(options, success) {
    saving.value = true;
    try {
        const result = await postJson(`/roster/${props.unitId}/accountability`, { options }).catch(unreachable);
        if (result.ok && result.data?.accountability) {
            current.value = result.data.accountability;
            picked.value = [...current.value.options];
            toast.success(success);
        } else {
            toast.error('Could not change what counts', { description: messageFor(result) });
        }
    } finally {
        saving.value = false;
    }
}

function save() {
    if (!picked.value.length) {
        toast.error('Could not change what counts', { description: 'Tick at least one option, or use the default.' });

        return;
    }
    send(picked.value, 'Saved');
}
</script>

<template>
    <section :class="nested ? '' : 'rounded-lg border border-border p-4'" data-testid="accountability">
        <h2 class="flex items-center gap-2 text-sm font-semibold">
            <PhListChecks class="size-4" aria-hidden="true" />{{ title }}
        </h2>

        <form v-if="current.canEdit" class="mt-3 grid gap-2" @submit.prevent="save">
            <div v-for="choice in current.choices" :key="choice.key" class="flex items-center gap-2">
                <Checkbox
                    :id="`accountability-${unitId}-${choice.key}`"
                    :model-value="!choice.pending && picked.includes(choice.key)"
                    :disabled="choice.pending"
                    :data-testid="`accountability-${choice.key}`"
                    @update:model-value="(on) => toggle(choice.key, on === true)"
                />
                <Label :for="`accountability-${unitId}-${choice.key}`" class="font-normal" :class="choice.pending ? 'text-muted-foreground' : ''"
                    >{{ choice.label }}<span v-if="choice.pending" class="ml-1.5 text-xs" data-testid="accountability-pending">Coming later</span></Label
                >
            </div>
            <div class="mt-1 flex flex-wrap gap-2">
                <Button type="submit" size="sm" :disabled="saving">Save</Button>
                <Button
                    v-if="current.source === 'own'"
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="saving"
                    data-testid="accountability-reset"
                    @click="send(null, 'Back to the default')"
                >
                    Use the default
                </Button>
            </div>
        </form>
        <ul v-else class="mt-2 list-disc pl-5 text-sm" data-testid="accountability-list">
            <li v-for="key in current.options" :key="key">{{ labelOf(key) }}</li>
        </ul>
    </section>
</template>
