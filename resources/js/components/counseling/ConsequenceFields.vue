<script setup>
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { LOR_CONSEQUENCES, LOR_REMOVALS } from '@/lib/counseling';

// An LOR's recommended consequence (#565), as the form has it: four boxes, the position a 2nd-instance removal is
// from, and the special punishments ordered. The leader's Consequence step and the appeal authority's amendment both
// use it.
const consequences = defineModel('consequences', { type: Array, required: true });
const removal = defineModel('removal', { type: String, default: null });
const specialPunishments = defineModel('specialPunishments', { type: String, default: '' });

defineProps({
    // Prefix for the inputs' ids, so two of these never clash on one page.
    id: { type: String, required: true },
    errors: { type: Object, default: () => ({}) },
    max: { type: Number, default: null },
    disabled: { type: Boolean, default: false },
});

function toggle(value, checked) {
    const rest = consequences.value.filter((c) => c !== value);
    consequences.value = checked ? Object.keys(LOR_CONSEQUENCES).filter((c) => c === value || rest.includes(c)) : rest;
    if (value === 'removal' && !checked) removal.value = null;
}
</script>

<template>
    <div class="space-y-4">
        <fieldset class="grid gap-2" :data-testid="`${id}-consequences`">
            <legend class="mb-1 text-[13px] font-medium">Recommended consequence</legend>
            <template v-for="(consequence, value) in LOR_CONSEQUENCES" :key="value">
                <label class="inline-flex items-start gap-2 text-[13px]">
                    <input
                        type="checkbox"
                        class="mt-0.5 size-4 accent-primary"
                        :checked="consequences.includes(value)"
                        :disabled="disabled"
                        :data-testid="`${id}-consequence-${value}`"
                        @change="toggle(value, $event.target.checked)"
                    />
                    {{ consequence.label }}
                </label>
                <fieldset v-if="value === 'removal' && consequences.includes('removal')" class="ml-6 grid gap-1.5" :data-testid="`${id}-removal`">
                    <legend class="mb-1 text-xs text-muted-foreground">Removal from</legend>
                    <label v-for="(which, name) in LOR_REMOVALS" :key="name" class="inline-flex items-center gap-2 text-[13px]">
                        <input v-model="removal" type="radio" class="size-4 accent-primary" :name="`${id}-removal`" :value="name" :disabled="disabled" :data-testid="`${id}-removal-${name}`" />
                        {{ which.label }}
                    </label>
                </fieldset>
            </template>
            <p v-if="errors['form.consequences'] || errors.consequences" class="text-xs text-destructive">{{ errors['form.consequences'] ?? errors.consequences }}</p>
            <p v-if="errors['form.removal'] || errors.removal" class="text-xs text-destructive">{{ errors['form.removal'] ?? errors.removal }}</p>
        </fieldset>

        <div class="grid gap-1.5">
            <Label :for="`${id}-special`">Special punishments ordered</Label>
            <Textarea :id="`${id}-special`" v-model="specialPunishments" rows="2" :maxlength="max ?? undefined" :disabled="disabled" placeholder="Optional" :data-testid="`${id}-special`" />
            <p v-if="errors['form.special_punishments']" class="text-xs text-destructive">{{ errors['form.special_punishments'] }}</p>
        </div>
    </div>
</template>
