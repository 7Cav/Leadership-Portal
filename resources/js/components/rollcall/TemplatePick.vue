<script setup>
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';

// The section's choice of rollcall template (#375): one of the templates its company switched on, the regiment default,
// or none, which follows the company default. `picker` is `TemplateResolver::pickerFor()`'s answer and `template` the
// one the post now renders from. Saving the pick is the page's job: this only says which one was chosen.
const props = defineProps({
    picker: { type: Object, required: true },
    template: { type: Object, default: null },
    // Why the pick cannot be changed here (a preview, or a section the viewer only reads), or null.
    disabledReason: { type: String, default: null },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(['pick']);

const SOURCE_NOTES = {
    picked: 'Picked for this section.',
    company: "The company's default.",
    default: 'The regiment default, as nothing else applies.',
};

const value = computed(() => (props.picker.picked === null ? '' : String(props.picker.picked)));
const note = computed(() => SOURCE_NOTES[props.template?.source] ?? null);
const optionLabel = (option) => (option.isRegimentDefault ? `${option.name} (regiment)` : option.name);

function choose(event) {
    const id = event.target.value === '' ? null : Number(event.target.value);
    if (id !== props.picker.picked) emit('pick', id);
}
</script>

<template>
    <div class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1.5" data-testid="template-pick">
        <Label for="rollcall-template" class="text-[13px]">Template</Label>
        <div class="min-w-0 flex-1 sm:max-w-xs">
            <NativeSelect
                id="rollcall-template"
                data-testid="template-pick-select"
                class="text-[13px]"
                :model-value="value"
                :disabled="Boolean(disabledReason) || saving"
                :title="disabledReason ?? undefined"
                :aria-busy="saving"
                @change="choose"
            >
                <option value="">Company default</option>
                <option v-for="option in picker.options" :key="option.id" :value="String(option.id)">{{ optionLabel(option) }}</option>
            </NativeSelect>
        </div>
        <p v-if="template?.name" class="text-xs text-muted-foreground" data-testid="template-pick-note">
            Using {{ template.name }}. {{ note }}
        </p>
    </div>
</template>
