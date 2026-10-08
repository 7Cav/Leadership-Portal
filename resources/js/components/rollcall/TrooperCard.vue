<script setup>
import { computed } from 'vue';
import { PhClockCounterClockwise, PhLockSimple } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { fullName, initials, isLocked, optionsFor, practiceLabel, signatureLabel } from '@/lib/rollcall';

// One trooper on the guided week (mockups/v2/rollcall-r3.html `.rc-card`): who they are, how the
// current value came to be, and big status buttons for the step's question — their rollcall
// signature (step 1) or their Section Practice mark (step 2).
const props = defineProps({
    trooper: { type: Object, required: true },
    // 'signature' | 'practice'
    kind: { type: String, required: true },
    editable: { type: Boolean, default: false },
    // Shown as the buttons' title when they are disabled for a reason (e.g. a view-as preview).
    disabledReason: { type: String, default: null },
});

const emit = defineEmits(['set']);

const locked = computed(() => isLocked(props.trooper));
const options = computed(() => optionsFor(props.kind));
const value = computed(() => (props.kind === 'signature' ? props.trooper.rollcall : props.trooper.status));
const valueLabel = computed(() => (props.kind === 'signature' ? signatureLabel(value.value) : practiceLabel(value.value)));
const name = computed(() => fullName(props.trooper));

// Last week's mark, offered while this week's is still open (3.7 pre-fill).
const suggestion = computed(() =>
    props.kind === 'practice' && props.trooper.status === null && props.trooper.priorStatus
        ? practiceLabel(props.trooper.priorStatus)
        : null,
);

const TONE = {
    ok: 'aria-pressed:bg-success/10 aria-pressed:text-success aria-pressed:shadow-[inset_0_0_0_1px_color-mix(in_oklab,var(--success)_45%,transparent)]',
    bad: 'aria-pressed:bg-destructive/8 aria-pressed:text-destructive aria-pressed:shadow-[inset_0_0_0_1px_color-mix(in_oklab,var(--destructive)_40%,transparent)]',
    info: 'aria-pressed:bg-info/8 aria-pressed:text-info aria-pressed:shadow-[inset_0_0_0_1px_color-mix(in_oklab,var(--info)_40%,transparent)]',
    neutral: 'aria-pressed:bg-card aria-pressed:text-foreground aria-pressed:shadow-xs',
};
</script>

<template>
    <div
        :class="cn('relative overflow-hidden rounded-lg border border-border bg-card', locked && 'bg-muted/55')"
        :data-trooper="trooper.id"
    >
        <div
            class="relative bg-inherit p-3 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            tabindex="0"
            data-card-face
            :aria-label="`${name}, ${kind === 'signature' ? 'rollcall' : 'section practice'}: ${valueLabel}${locked ? ', locked' : ''}`"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <span
                        class="grid size-10 flex-none place-items-center rounded-full border border-border bg-muted text-xs font-semibold"
                        aria-hidden="true"
                    >
                        {{ initials(trooper) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm leading-tight font-semibold">{{ name }}</p>
                        <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                            <slot name="source" :locked="locked">
                                <span class="truncate">{{ valueLabel }}</span>
                            </slot>
                            <span v-if="suggestion" class="inline-flex items-center gap-1" data-testid="prior-mark">
                                <PhClockCounterClockwise class="size-3.5" aria-hidden="true" />Last week: {{ suggestion }}
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="locked"
                    class="flex h-11 items-center gap-2 rounded-md border border-dashed border-border px-3 text-[13px] text-muted-foreground sm:h-9 sm:w-[22rem]"
                >
                    <PhLockSimple class="size-4" aria-hidden="true" />LOA, nothing to mark
                    <Badge variant="outline" class="ml-auto">Locked</Badge>
                </div>
                <div
                    v-else
                    role="group"
                    :aria-label="`${kind === 'signature' ? 'Rollcall signature' : 'Section practice'} for ${name}`"
                    :class="
                        cn(
                            'grid auto-cols-fr grid-flow-col gap-[3px] rounded-md bg-muted p-[3px]',
                            kind === 'practice' ? 'sm:w-[25rem]' : 'sm:w-[22rem]',
                        )
                    "
                >
                    <button
                        v-for="(option, index) in options"
                        :key="option.value"
                        type="button"
                        :aria-pressed="value === option.value"
                        :disabled="!editable"
                        :title="!editable && disabledReason ? disabledReason : undefined"
                        :data-value="option.value"
                        :class="
                            cn(
                                'inline-flex min-h-11 items-center justify-center gap-1.5 rounded-sm px-1.5 text-[13px] font-medium whitespace-nowrap text-muted-foreground transition-colors enabled:hover:text-foreground disabled:cursor-not-allowed sm:min-h-[2.125rem] sm:px-2.5',
                                TONE[option.tone],
                            )
                        "
                        @click="emit('set', option.value)"
                    >
                        <component :is="option.icon" class="hidden size-4 sm:block" aria-hidden="true" />
                        {{ option.label }}<span class="sr-only">, key {{ index + 1 }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
