<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhArrowSquareOut, PhCaretDown, PhCheckCircle, PhCircleDashed, PhEye, PhTextAa } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { Progress } from '@/components/ui/progress';
import { unaccountedTroopers, unitLabel } from '@/lib/report';

// "Ready to send" (P3): each check with its inline fix. Rollcall and practice marks are fixed
// in the rollcall screen; a reason per unaccounted trooper, the subject and the review are fixed
// here. Open checks start expanded; a check that completes folds itself away.
const props = defineProps({
    checks: { type: Array, required: true },
    troopers: { type: Array, default: () => [] },
    children: { type: Array, default: () => [] },
    unaccounted: { type: Array, required: true },
    reasons: { type: Array, required: true },
    rollcallUrl: { type: String, default: null },
    readOnly: { type: Boolean, default: false },
    // `YYYY-MM-DD`, for links to the children's own reports.
    weekDate: { type: String, default: null },
});

const emit = defineEmits(['update:unaccounted', 'focus']);

const done = computed(() => props.checks.filter((c) => c.done).length);
const open = ref(new Set(props.checks.filter((c) => !c.done).map((c) => c.id)));

watch(
    () => props.checks.map((c) => `${c.id}:${c.done}`).join(),
    (_, before) => {
        const was = new Map((before ?? '').split(',').map((pair) => pair.split(':')));
        const next = new Set(open.value);
        props.checks.forEach((c) => {
            if (c.done && was.get(c.id) === 'false') next.delete(c.id);
            if (!c.done && was.get(c.id) === 'true') next.add(c.id);
        });
        open.value = next;
    },
);

function toggle(id) {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
}

const missing = computed(() => unaccountedTroopers(props.troopers));
const reasonOf = (id) => props.unaccounted.find((r) => r.trooper_id === id)?.reason ?? '';

function setReason(trooperId, reason) {
    const rest = props.unaccounted.filter((r) => r.trooper_id !== trooperId);
    emit('update:unaccounted', reason ? [...rest, { trooper_id: trooperId, reason }] : rest);
}

const waiting = computed(() => props.children.filter((c) => c.status !== 'sent'));
</script>

<template>
    <section class="rounded-lg border border-border bg-card" aria-labelledby="checklist-title" data-testid="checklist">
        <div class="flex items-center gap-3 px-4 pt-3.5 pb-3">
            <h2 id="checklist-title" class="text-[15px] font-semibold">Ready to send</h2>
            <span class="ml-auto text-xs text-muted-foreground tabular-nums">{{ done }} of {{ checks.length }} done</span>
        </div>
        <div class="px-4"><Progress :model-value="(done / checks.length) * 100" aria-label="Checks done" /></div>

        <ol class="mt-1 p-2">
            <li v-for="check in checks" :key="check.id" :data-check="check.id" class="[&+li]:border-t [&+li]:border-border/70">
                <button
                    type="button"
                    class="grid w-full grid-cols-[1.25rem_minmax(0,1fr)_auto_1rem] items-start gap-x-2.5 gap-y-0.5 rounded-md p-3 text-left hover:bg-accent/55 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-expanded="open.has(check.id)"
                    :aria-controls="`check-${check.id}`"
                    @click="toggle(check.id)"
                >
                    <PhCheckCircle v-if="check.done" class="size-[1.125rem] text-success" aria-hidden="true" />
                    <PhCircleDashed v-else class="size-[1.125rem] text-warning" aria-hidden="true" />
                    <span class="min-w-0">
                        <span class="block text-[13px] font-medium" :class="{ 'text-muted-foreground': check.done }">
                            {{ check.title }}<span class="sr-only">{{ check.done ? ', done' : ', not done' }}</span>
                        </span>
                        <span class="mt-0.5 block truncate text-xs text-muted-foreground">{{ check.detail }}</span>
                    </span>
                    <span class="text-xs font-medium tabular-nums" :class="check.done ? 'text-success' : 'text-warning'">{{ check.count }}</span>
                    <PhCaretDown class="mt-0.5 text-muted-foreground transition-transform" :class="{ 'rotate-180': open.has(check.id) }" aria-hidden="true" />
                </button>

                <div v-show="open.has(check.id)" :id="`check-${check.id}`" class="px-3 pb-3.5">
                    <template v-if="check.id === 'rollcall' || check.id === 'practice'">
                        <p v-if="check.id === 'practice'" class="mb-2.5 text-xs text-muted-foreground">
                            Practice marks are recorded in rollcall, where the change is audited.
                        </p>
                        <p v-else class="mb-2.5 text-xs text-muted-foreground">
                            Signature changes after you send stay out of this report.
                        </p>
                        <Button v-if="rollcallUrl" variant="outline" size="sm" as-child>
                            <Link :href="rollcallUrl"><PhArrowSquareOut aria-hidden="true" />Open rollcall</Link>
                        </Button>
                    </template>

                    <div v-else-if="check.id === 'unaccounted'" class="space-y-3">
                        <p v-if="!missing.length" class="text-xs text-muted-foreground">
                            Nobody is marked missed without a makeup this week.
                        </p>
                        <div v-for="t in missing" :key="t.id" class="rounded-md border border-border bg-background px-3 py-2.5">
                            <Label :for="`reason-${t.id}`" class="text-[13px]">Reason for {{ t.name }}</Label>
                            <NativeSelect
                                :id="`reason-${t.id}`"
                                class="mt-1.5"
                                :model-value="reasonOf(t.id)"
                                :disabled="readOnly"
                                :aria-describedby="`reason-${t.id}-help`"
                                :data-reason="t.id"
                                @update:model-value="setReason(t.id, $event)"
                            >
                                <NativeSelectOption value="">Pick a reason</NativeSelectOption>
                                <NativeSelectOption v-for="r in reasons" :key="r.value" :value="r.value">{{ r.label }}</NativeSelectOption>
                            </NativeSelect>
                            <p :id="`reason-${t.id}-help`" class="mt-1.5 text-xs text-muted-foreground">
                                {{ t.atRisk ? `Marked missed with no makeup. ${t.missed} misses in 30 days, at-risk.` : 'Marked missed with no makeup.' }}
                            </p>
                        </div>
                    </div>

                    <Button v-else-if="check.id === 'subject'" variant="outline" size="sm" @click="emit('focus', 'training-subject')">
                        <PhTextAa aria-hidden="true" />Go to training subject
                    </Button>

                    <div v-else-if="check.id === 'reviewed'" class="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" @click="emit('focus', 'preview')"><PhEye aria-hidden="true" />Show preview</Button>
                        <Button variant="ghost" size="sm" @click="emit('focus', 'reviewed')">Go to confirmation</Button>
                    </div>

                    <ul v-else-if="check.id === 'children'" class="space-y-1.5 text-[13px]">
                        <li v-for="c in waiting" :key="c.id" class="flex flex-wrap items-center gap-x-2">
                            <Link v-if="weekDate" :href="`/reports/${c.id}/${weekDate}`" class="font-medium underline-offset-2 hover:underline">{{ unitLabel(c) }}</Link>
                            <span class="text-xs text-muted-foreground">{{ c.status === 'draft' ? 'Draft, not counted until sent' : 'Not started' }}</span>
                        </li>
                    </ul>
                </div>
            </li>
        </ol>
    </section>
</template>
