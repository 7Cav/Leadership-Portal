<script setup>
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import SearchSelect from '@/components/SearchSelect.vue';

// The section's choice of rollcall template (#375): one of the section's own templates, one of those its company switched
// on, the regiment default, or none, which follows the company default. `picker` is `TemplateResolver::pickerFor()`'s answer and `template` the
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
const optionLabel = (option) => {
    if (option.isRegimentDefault) return `${option.name} (regiment)`;

    return option.isSection ? `${option.name} (this section)` : option.name;
};

// A platoon moved here from another company gets copies of its templates (#378). When the new company switches a copy
// off, the section is told what its post uses now.
const moved = computed(() => props.picker.moved ?? null);

const rows = computed(() => props.picker.options.map((option) => ({ value: String(option.id), label: optionLabel(option) })));

function choose(picked) {
    const id = picked === '' || picked === null ? null : Number(picked);
    if (id !== props.picker.picked) emit('pick', id);
}
</script>

<template>
    <div class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1.5" data-testid="template-pick">
        <Label for="rollcall-template" class="text-[13px]">Template</Label>
        <div class="min-w-0 flex-1 sm:max-w-xs">
            <SearchSelect
                id="rollcall-template"
                testid="template-pick-select"
                :model-value="value"
                :options="rows"
                any-label="Company default"
                any-value=""
                search-placeholder="Search templates"
                empty-text="No template matches that search."
                :disabled="Boolean(disabledReason) || saving"
                :title="disabledReason ?? undefined"
                :aria-busy="saving"
                @update:model-value="choose"
            />
        </div>
        <p v-if="template?.name" class="text-xs text-muted-foreground" data-testid="template-pick-note">
            Using {{ template.name }}. {{ note }}
        </p>
        <p v-if="moved" role="status" class="basis-full rounded-md border border-warning/40 bg-warning/7 px-3 py-2 text-xs" data-testid="template-moved">
            <template v-if="moved.from">This platoon moved here from {{ moved.from }}. </template>Its template came along as
            {{ moved.copy }}, which is switched off here. The post now uses {{ moved.using ?? 'no template' }}.
        </p>
    </div>
</template>
