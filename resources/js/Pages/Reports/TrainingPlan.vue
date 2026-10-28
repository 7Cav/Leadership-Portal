<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PhArrowRight, PhCaretLeft, PhCaretRight, PhChatText, PhCheck, PhPaperPlaneTilt, PhUsersThree } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import EmptyState from '@/components/EmptyState.vue';
import { forumDate } from '@/lib/forumDate';
import { onHttpException } from '@/lib/httpError';
import { unitLabel } from '@/lib/report';
import { lockedNote, planHistory, planNextStep, planStatus, subjectsOf } from '@/lib/trainingPlan';

// Monthly training plans (#380). On a platoon: one training subject per Cav week of the month, written by the
// platoon's chain of command and approved or sent back by the company's. Once approved, each week's subject fills in
// every section's report (TrainingPlanController). Above a platoon: the month's plans of every platoon beneath.
const props = defineProps({
    unit: { type: Object, required: true },
    // `{ value: 'YYYY-MM', label, previous, next }`; previous and next are null outside the plan window.
    month: { type: Object, required: true },
    // A platoon's page: `[{ start, subject, locked: null|'sent'|'floor', reportUrl }]`.
    weeks: { type: Array, default: null },
    plan: { type: Object, default: null },
    canEdit: { type: Boolean, default: false },
    canReview: { type: Boolean, default: false },
    company: { type: Object, default: null },
    // Above a platoon: `[{ id, name, callsign, type, status, weeksPlanned }]`.
    platoons: { type: Array, default: null },
});

const page = usePage();
const viewAs = computed(() => page.props.viewAs ?? null);
const label = computed(() => unitLabel(props.unit));
const monthUrl = (month) => `/reports/${props.unit.id}/plans/${month}`;
const planUrl = computed(() => monthUrl(props.month.value));

// ---- The writer's form ----
const values = reactive({});
const enforced = ref(false);
const busy = ref(false);
const error = ref(null);

function reset() {
    for (const week of props.weeks ?? []) values[week.start] = week.subject ?? '';
    enforced.value = props.plan?.enforced ?? false;
}
reset();
watch(() => [props.weeks, props.plan], reset);

const saved = computed(() => subjectsOf(props.weeks ?? [], Object.fromEntries((props.weeks ?? []).map((w) => [w.start, w.subject ?? '']))));
const current = computed(() => subjectsOf(props.weeks ?? [], values));
const changed = computed(
    () => JSON.stringify(current.value) !== JSON.stringify(saved.value) || enforced.value !== (props.plan?.enforced ?? false),
);
// A plan sent back goes up again as it stands; anything else only when something changed.
const canSend = computed(() => Object.keys(current.value).length > 0 && (changed.value || props.plan?.status === 'returned'));

const status = computed(() => planStatus(props.plan?.status ?? 'none'));
const history = computed(() => planHistory(props.plan));
const nextStep = computed(() => planNextStep(props.plan, enforced.value));

function options(done, failed) {
    return {
        preserveScroll: true,
        onSuccess: () => toast.success(done),
        onError: (errors) => (error.value = errors.plan ?? errors.comment ?? Object.values(errors)[0]),
        onHttpException: onHttpException(failed, (message) => (error.value = message)),
        onNetworkError: () => {
            error.value = 'Could not reach the portal. Try again.';

            return false;
        },
        onFinish: () => (busy.value = false),
    };
}

function send() {
    busy.value = true;
    error.value = null;
    router.put(planUrl.value, { subjects: { ...values }, enforced: enforced.value }, options('Plan sent for approval', 'The plan was not sent'));
}

// ---- The reviewer's panel ----
const comment = ref('');
const reviewing = computed(() => props.canReview && props.plan?.status === 'pending');

function approve() {
    busy.value = true;
    error.value = null;
    router.post(`${planUrl.value}/approve`, { revision: props.plan.revision }, options('Plan approved', 'The plan was not approved'));
}

function sendBack() {
    busy.value = true;
    error.value = null;
    router.post(`${planUrl.value}/return`, { revision: props.plan.revision, comment: comment.value.trim() }, {
        ...options('Plan sent back', 'The plan was not sent back'),
        onSuccess: () => {
            comment.value = '';
            toast.success('Plan sent back');
        },
    });
}
</script>

<template>
    <Head :title="`${unitLabel(unit)} training plan, ${month.label} · 7Cav Leadership Portal`" />

    <div class="flex flex-col gap-4" data-testid="training-plan-page">
        <section aria-labelledby="page-title" class="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div class="min-w-0">
                <p class="text-xs text-muted-foreground">{{ label }} training plan</p>
                <h1 id="page-title" class="flex flex-wrap items-center gap-2.5 text-xl font-semibold tracking-tight sm:text-[22px]">
                    {{ month.label }}
                    <Badge v-if="weeks" :variant="status.variant" data-testid="plan-status">{{ status.label }}</Badge>
                </h1>
            </div>
            <nav class="flex items-center gap-1" aria-label="Choose month">
                <Button v-if="month.previous" variant="ghost" size="icon-sm" as-child>
                    <Link :href="monthUrl(month.previous)" aria-label="Previous month"><PhCaretLeft /></Link>
                </Button>
                <Button v-else variant="ghost" size="icon-sm" disabled aria-label="Previous month, too far back"><PhCaretLeft /></Button>
                <Button v-if="month.next" variant="ghost" size="icon-sm" as-child>
                    <Link :href="monthUrl(month.next)" aria-label="Next month" data-testid="next-month"><PhCaretRight /></Link>
                </Button>
                <Button v-else variant="ghost" size="icon-sm" disabled aria-label="Next month, too far ahead"><PhCaretRight /></Button>
            </nav>
        </section>

        <!-- Above a platoon: where each platoon's plan stands. -->
        <section v-if="platoons" class="rounded-lg border border-border bg-card" aria-label="Platoon plans" data-testid="platoon-plans">
            <EmptyState v-if="!platoons.length" :icon="PhUsersThree" title="No platoons" class="py-10">There are no platoons under this unit, so there are no plans to review.</EmptyState>
            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Platoon</TableHead>
                        <TableHead>Plan</TableHead>
                        <TableHead class="text-right">Weeks planned</TableHead>
                        <TableHead><span class="sr-only">Open</span></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="platoon in platoons" :key="platoon.id" :data-testid="`platoon-${platoon.id}`">
                        <TableCell class="font-medium">{{ unitLabel(platoon) }}</TableCell>
                        <TableCell><Badge :variant="planStatus(platoon.status).variant">{{ planStatus(platoon.status).label }}</Badge></TableCell>
                        <TableCell class="text-right tabular-nums">{{ platoon.weeksPlanned }}</TableCell>
                        <TableCell class="text-right">
                            <Button variant="ghost" size="sm" as-child>
                                <Link :href="`/reports/${platoon.id}/plans/${month.value}`">Open<PhArrowRight aria-hidden="true" /></Link>
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </section>

        <!-- A platoon: its plan for the month. -->
        <div v-else class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
            <section class="flex flex-col gap-4 rounded-lg border border-border bg-card p-5" aria-labelledby="weeks-title">
                <div>
                    <h2 id="weeks-title" class="text-[15px] font-semibold">Training subject for each week</h2>
                    <p class="text-[13px] text-muted-foreground" data-testid="plan-history">{{ history ?? 'No plan for this month yet.' }}</p>
                </div>

                <div
                    v-if="plan?.status === 'returned' && plan.comment"
                    class="flex items-start gap-2.5 rounded-lg border border-destructive/30 bg-destructive/8 px-4 py-3 text-[13px]"
                    data-testid="plan-comment"
                >
                    <PhChatText class="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden="true" />
                    <p class="whitespace-pre-line">{{ plan.comment }}</p>
                </div>

                <div v-for="week in weeks" :key="week.start" class="space-y-1.5">
                    <div class="flex items-baseline justify-between gap-2">
                        <Label :for="`week-${week.start}`" class="text-[13px]">Week of {{ forumDate(week.start) }}</Label>
                        <Link v-if="week.reportUrl" :href="week.reportUrl" class="text-xs text-muted-foreground hover:text-foreground">Open report</Link>
                    </div>
                    <Input
                        :id="`week-${week.start}`"
                        v-model="values[week.start]"
                        :disabled="!canEdit || !!week.locked || busy"
                        maxlength="255"
                        autocomplete="off"
                        :aria-describedby="week.locked ? `week-${week.start}-note` : undefined"
                    />
                    <p v-if="week.locked" :id="`week-${week.start}-note`" class="text-xs text-muted-foreground">{{ lockedNote(week.locked) }}</p>
                </div>

                <template v-if="canEdit">
                    <div class="flex items-center gap-2 text-[13px]">
                        <Checkbox id="plan-enforced" v-model="enforced" :disabled="busy" data-testid="plan-enforced" />
                        <Label for="plan-enforced" class="text-[13px] font-normal">Lock these subjects, so sections cannot change them</Label>
                    </div>
                    <p class="text-xs text-muted-foreground" data-testid="plan-next-step">{{ nextStep }}</p>
                    <div>
                        <Button size="sm" :disabled="busy || !canSend" data-testid="plan-send" @click="send">
                            <PhPaperPlaneTilt aria-hidden="true" />Send for approval
                        </Button>
                    </div>
                </template>
                <p v-else-if="viewAs" class="text-xs text-muted-foreground" data-testid="plan-read-only">Read-only while viewing as {{ viewAs.label }}</p>
                <p v-else class="text-xs text-muted-foreground" data-testid="plan-read-only">
                    Read-only: only the platoon's chain of command writes this plan.
                    <template v-if="plan">{{ plan.enforced ? 'Once approved, its subjects are locked on every section report.' : 'Once approved, each section can change the subject on its report.' }}</template>
                </p>

                <p v-if="error" class="text-[13px] text-destructive" role="alert" data-testid="plan-error">{{ error }}</p>
            </section>

            <section v-if="reviewing" class="flex flex-col gap-3 rounded-lg border border-border bg-card p-5" aria-labelledby="review-title" data-testid="plan-review">
                <div>
                    <h2 id="review-title" class="text-[15px] font-semibold">Review for {{ unitLabel(company) || 'the company' }}</h2>
                    <p class="text-[13px] text-muted-foreground">
                        Approving fills in each week's subject on the platoon's section reports. A week whose report is already sent keeps its subject.
                    </p>
                </div>
                <div>
                    <Button size="sm" :disabled="busy" data-testid="plan-approve" @click="approve"><PhCheck aria-hidden="true" />Approve</Button>
                </div>
                <div class="space-y-1.5">
                    <Label for="plan-return-comment" class="text-[13px]">What should change</Label>
                    <Textarea id="plan-return-comment" v-model="comment" maxlength="1000" rows="3" :disabled="busy" />
                </div>
                <div>
                    <Button size="sm" variant="outline" :disabled="busy || !comment.trim()" data-testid="plan-return" @click="sendBack">Send back</Button>
                </div>
            </section>
        </div>
    </div>
</template>
