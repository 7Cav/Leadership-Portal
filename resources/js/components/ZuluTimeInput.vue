<script setup>
import { ref, watch } from 'vue';
import { Input } from '@/components/ui/input';

// A start time in Zulu, typed as 24-hour digits the way the portal writes it (2300, the Z shown beside it). The
// browser's own time field follows the computer's clock settings and shows AM and PM on a 12-hour one, which a Zulu time
// never has. The value stays `HH:MM`, as `timeToMinute()` reads it: `2300`, `23:00` and `23` are all taken as 23:00,
// and anything else is passed on as typed, so the form's own check refuses it.
defineOptions({ inheritAttrs: false });
const model = defineModel({ type: String, default: '' });

const shown = (value) => (/^\d{2}:\d{2}$/.test(value ?? '') ? value.replace(':', '') : (value ?? ''));
const text = ref(shown(model.value));
watch(model, (value) => {
    if (value !== read(text.value)) text.value = shown(value);
});

function read(value) {
    const digits = /^\s*(\d{1,2})(?::?(\d{2}))?\s*z?\s*$/i.exec(value ?? '');
    if (!digits) return value;

    return `${digits[1].padStart(2, '0')}:${digits[2] ?? '00'}`;
}

function onInput(value) {
    text.value = value;
    model.value = read(value);
}
</script>

<template>
    <div class="relative">
        <Input
            v-bind="$attrs"
            :model-value="text"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            maxlength="5"
            placeholder="2300"
            class="pr-7 tabular-nums"
            @update:model-value="onInput"
        />
        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground" aria-hidden="true">Z</span>
    </div>
</template>
