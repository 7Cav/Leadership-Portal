<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { PhDownloadSimple, PhWarning } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Guidon from '@/components/Guidon.vue';
import { onHttpException } from '@/lib/httpError';
import {
    MAX_TEXT,
    PALETTE,
    cleanText,
    defaultDesign,
    guidonDataUrl,
    guidonFileName,
    guidonPngDataUrl,
    isColor,
    tooClose,
} from '@/lib/guidon';

// The guidon generator (#446), opened from the roster's unit header by those who may save the unit's guidon. It starts
// from the saved design, or from the unit's designation for a unit with none. The preview follows every change and
// nothing is saved until Save. The downloads draw the design on screen, saved or not.
const props = defineProps({
    // `{ id, type, name, callsign, guidon }`, the roster's unit summary.
    unit: { type: Object, required: true },
});

const emit = defineEmits(['saved']);

const start = props.unit.guidon ?? defaultDesign(props.unit);
const form = useForm({ ...start });

const design = computed(() => ({
    primary: form.primary,
    secondary: form.secondary,
    topLeft: form.topLeft,
    bottomRight: form.bottomRight,
}));
const unchanged = computed(() => props.unit.guidon !== null && props.unit.guidon !== undefined && JSON.stringify(design.value) === JSON.stringify(props.unit.guidon));
const close = computed(() => tooClose(form.primary, form.secondary));
const name = computed(() => props.unit.callsign ?? props.unit.name);

const halves = [
    { key: 'primary', text: 'topLeft', label: 'Top left' },
    { key: 'secondary', text: 'bottomRight', label: 'Bottom right' },
];

function pickColor(key, value) {
    if (isColor(value)) form[key] = value.toLowerCase();
}

// A character a guidon may not carry never shows in the field, even when dropping it leaves the text as it was.
function setText(key, event) {
    form[key] = cleanText(event.target.value);
    event.target.value = form[key];
}

function save() {
    form.put(`/units/${props.unit.id}/guidon`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(`Guidon saved for ${name.value}`);
            emit('saved');
        },
        onHttpException: onHttpException('The guidon was not saved', (message) => toast.error(message)),
    });
}

function download(href, extension) {
    const link = document.createElement('a');
    link.href = href;
    link.download = guidonFileName(props.unit, extension);
    document.body.appendChild(link);
    link.click();
    link.remove();
}

function downloadSvg() {
    download(guidonDataUrl(design.value), 'svg');
}

async function downloadPng() {
    try {
        download(await guidonPngDataUrl(design.value), 'png');
    } catch {
        toast.error('The PNG could not be made. Download the SVG instead.');
    }
}
</script>

<template>
    <form class="space-y-4" data-testid="guidon-generator" @submit.prevent="save">
        <div class="flex justify-center rounded-md border border-border bg-muted/40 p-4">
            <Guidon :design="design" :height="144" :alt="`Guidon of ${name}`" />
        </div>

        <fieldset v-for="half in halves" :key="half.key" class="space-y-2">
            <legend class="text-[13px] font-medium">{{ half.label }}</legend>
            <div class="flex flex-wrap items-center gap-1.5" role="group" :aria-label="`${half.label} color`">
                <button
                    v-for="color in PALETTE"
                    :key="color.hex"
                    type="button"
                    :title="color.name"
                    :aria-label="color.name"
                    :aria-pressed="form[half.key] === color.hex"
                    :data-testid="`guidon-${half.key}-${color.hex.slice(1)}`"
                    class="size-6 rounded-sm border border-border outline-offset-2 aria-pressed:outline-2 aria-pressed:outline-primary"
                    :style="{ backgroundColor: color.hex }"
                    @click="pickColor(half.key, color.hex)"
                ></button>
                <label class="ml-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                    Custom
                    <input
                        type="color"
                        :value="form[half.key]"
                        :data-testid="`guidon-${half.key}-custom`"
                        class="h-6 w-8 cursor-pointer rounded-sm border border-border bg-transparent"
                        @input="pickColor(half.key, $event.target.value)"
                    />
                </label>
                <span class="font-mono text-xs text-muted-foreground">{{ form[half.key] }}</span>
            </div>
            <p v-if="form.errors[half.key]" class="text-xs text-destructive">{{ form.errors[half.key] }}</p>
            <div class="space-y-1">
                <Label :for="`guidon-${half.text}`">{{ half.label }} text</Label>
                <!-- A plain input, not the Input component: its own copy of the value would put a dropped character back. -->
                <input
                    :id="`guidon-${half.text}`"
                    type="text"
                    :value="form[half.text]"
                    :maxlength="MAX_TEXT"
                    autocomplete="off"
                    :data-testid="`guidon-${half.text}`"
                    :aria-invalid="!!form.errors[half.text]"
                    class="h-8 w-32 rounded-md border border-input bg-card px-2.5 py-1 font-mono text-[13px] outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30"
                    @input="setText(half.text, $event)"
                />
                <p v-if="form.errors[half.text]" class="text-xs text-destructive">{{ form.errors[half.text] }}</p>
            </div>
        </fieldset>

        <p class="text-xs text-muted-foreground">Up to {{ MAX_TEXT }} letters, digits, /, - or spaces on each half. The text is black or white, whichever reads better on its color.</p>

        <p v-if="close" role="status" class="flex items-center gap-1.5 text-xs text-warning" data-testid="guidon-too-close">
            <PhWarning aria-hidden="true" />The two colors are too close to tell apart. Pick colors with more contrast.
        </p>
        <p v-if="form.errors.guidon" role="alert" class="text-xs text-destructive">{{ form.errors.guidon }}</p>

        <div class="flex flex-wrap items-center gap-2">
            <Button type="submit" :disabled="form.processing || unchanged" data-testid="guidon-save">Save guidon</Button>
            <Button type="button" variant="outline" data-testid="guidon-download-svg" @click="downloadSvg"><PhDownloadSimple />Download SVG</Button>
            <Button type="button" variant="outline" data-testid="guidon-download-png" @click="downloadPng"><PhDownloadSimple />Download PNG</Button>
            <span class="text-xs text-muted-foreground">Nothing is saved until you save.</span>
        </div>
    </form>
</template>
