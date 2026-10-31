<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PhArrowSquareOut, PhClock, PhFilePdf, PhQuotes, PhScales } from '@phosphor-icons/vue';
import ConsequenceFields from '@/components/counseling/ConsequenceFields.vue';
import PmConsent from '@/components/counseling/PmConsent.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useNow } from '@/composables/useNow';
import { consequenceBadges, leaderForm, timeLeft, zulu } from '@/lib/counseling';
import { downloadCounselingDocument } from '@/lib/counselingPdf';
import { onHttpException } from '@/lib/httpError';
import { toast } from 'vue-sonner';

// The appeals queue (#565, appeals-desktop / appeals-decide mockups): the Letters of Reprimand from the units directly
// under the viewer's that the trooper appealed. Waiting ones carry their 72-hour clock; the decision is final and goes
// out in a PM from the viewer's forum account. Deciding opens a Sheet with the trooper's reason and the letter as
// written; "Have amended" lets the authority change the recommended consequence. Hiding a control is cosmetic: the
// decide route checks again.
const props = defineProps({
    // `CounselingRecordView::of()` per record, with `trooper` and `canDecide`.
    appeals: { type: Array, default: () => [] },
    pm: { type: Object, required: true },
    status: { type: String, default: null },
});

const waiting = computed(() => props.appeals.filter((record) => record.appeal?.pending));
const decided = computed(() => props.appeals.filter((record) => !record.appeal?.pending));
const now = useNow();
const who = (trooper) => [trooper.rank, trooper.name].filter(Boolean).join(' ');
const consequence = (record) => consequenceBadges(record.form).join(', ') || 'None recommended';

// The due time and the whole hours left (`2 Nov 0912Z · 58 h`); under 12 hours to go, the clock turns red.
const HOUR = 60 * 60 * 1000;
const clock = (record) => {
    const left = new Date(record.appeal.dueAt).getTime() - now.value;
    const rest = left >= HOUR ? `${Math.floor(left / HOUR)} h` : timeLeft(record.appeal.dueAt, now.value);

    return { text: rest ? `${zulu(record.appeal.dueAt)} · ${rest}` : 'Overdue', variant: left < 12 * HOUR ? 'danger' : 'warning' };
};

// ---- Deciding ----

const deciding = ref(null);
const sheetOpen = computed({
    get: () => deciding.value !== null,
    set: (open) => {
        if (!open && !form.processing) deciding.value = null;
    },
});
const form = useForm({ outcome: 'not_amended', reason: '', form: { consequences: [], removal: null, special_punishments: '' } });

function openDecide(record) {
    const written = leaderForm(record.form);
    form.clearErrors();
    form.outcome = 'not_amended';
    form.reason = '';
    form.form = { consequences: [...written.consequences], removal: written.removal, special_punishments: written.special_punishments };
    deciding.value = record;
}

function decide() {
    const record = deciding.value;
    if (!record) return;
    if (!form.reason.trim()) {
        form.setError('reason', 'Give the reason for your decision.');
        return;
    }
    form.transform((data) => ({
        outcome: data.outcome,
        reason: data.reason,
        form: {
            consequences: data.form.consequences,
            removal: data.form.consequences.includes('removal') ? data.form.removal : null,
            special_punishments: data.form.special_punishments,
        },
    }));
    form.post(`/counseling/${record.id}/decide`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            deciding.value = null;
            toast.success('Decision recorded', { description: `${record.trooper.name}, the issuer and S1 have been told.` });
        },
        onHttpException: onHttpException('Your decision was not recorded', (message) => toast.error(message)),
    });
}

async function download(record) {
    try {
        await downloadCounselingDocument(record.id);
    } catch (error) {
        toast.error('The PDF could not be downloaded', { description: error?.message });
    }
}
</script>

<template>
    <Head title="Appeals · 7Cav Leadership Portal" />

    <div class="flex flex-col gap-4">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Appeals</h1>
            <p class="mt-1 text-[13px] text-muted-foreground">
                Letters of Reprimand from the units directly under yours that the trooper appealed. Decide each within 72 hours. Your decision is final.
            </p>
        </div>

        <PmConsent v-if="waiting.some((record) => record.canDecide)" :pm="pm" sends="your decision" />

        <Tabs default-value="waiting" class="gap-0 rounded-xl border border-border bg-card">
            <TabsList aria-label="Appeals" class="px-4">
                <TabsTrigger value="waiting" data-testid="tab-waiting">
                    Waiting <Badge :variant="waiting.length ? 'warning' : 'secondary'" class="tabular-nums">{{ waiting.length }}</Badge>
                </TabsTrigger>
                <TabsTrigger value="decided" data-testid="tab-decided">
                    Decided <Badge variant="secondary" class="tabular-nums">{{ decided.length }}</Badge>
                </TabsTrigger>
            </TabsList>

            <TabsContent value="waiting" data-testid="appeals-waiting">
                <EmptyState v-if="!waiting.length" :icon="PhScales" title="No appeals waiting" class="py-10">New ones show here with a 72-hour clock.</EmptyState>
                <template v-else>
                    <table class="hidden w-full text-left text-[13px] md:table">
                        <thead class="text-[11px] text-muted-foreground">
                            <tr class="border-b border-border">
                                <th scope="col" class="px-4 py-2 font-medium">Trooper</th>
                                <th scope="col" class="px-2 py-2 font-medium">Issued by</th>
                                <th scope="col" class="px-2 py-2 font-medium">Consequence</th>
                                <th scope="col" class="px-2 py-2 font-medium">Appealed</th>
                                <th scope="col" class="px-2 py-2 font-medium">Decide by</th>
                                <th scope="col" class="px-4 py-2"><span class="sr-only">Action</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="record in waiting" :key="record.id" class="border-b border-border last:border-0" data-testid="appeal-row">
                                <td class="px-4 py-3">
                                    <span class="block font-medium">{{ who(record.trooper) }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ record.trooper.billet }}</span>
                                </td>
                                <td class="px-2 py-3">{{ record.issuer }}</td>
                                <td class="px-2 py-3">{{ consequence(record) }}</td>
                                <td class="px-2 py-3 text-muted-foreground">{{ zulu(record.appeal.at) }}</td>
                                <td class="px-2 py-3">
                                    <Badge :variant="clock(record).variant"><PhClock aria-hidden="true" />{{ clock(record).text }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button v-if="record.canDecide" variant="outline" size="sm" data-testid="appeal-decide" @click="openDecide(record)">
                                        Decide<span class="sr-only">: {{ record.trooper.name }}</span>
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <ul class="md:hidden">
                        <li v-for="record in waiting" :key="record.id" class="flex items-center gap-3 border-b border-border px-4 py-3 text-[13px] last:border-0" data-testid="appeal-card">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ who(record.trooper) }}</p>
                                <p class="text-xs text-muted-foreground">From {{ record.issuer }} · {{ consequence(record) }}</p>
                                <Badge :variant="clock(record).variant" class="mt-1"><PhClock aria-hidden="true" />{{ clock(record).text }}</Badge>
                            </div>
                            <Button v-if="record.canDecide" variant="outline" size="sm" @click="openDecide(record)">Decide<span class="sr-only">: {{ record.trooper.name }}</span></Button>
                        </li>
                    </ul>
                </template>
            </TabsContent>

            <TabsContent value="decided" data-testid="appeals-decided">
                <EmptyState v-if="!decided.length" :icon="PhScales" title="Nothing decided in the last 90 days" class="py-10" />
                <ul v-else>
                    <li v-for="record in decided" :key="record.id" class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3 text-[13px] last:border-0" data-testid="decided-row">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                {{ who(record.trooper) }}
                                <Badge v-if="record.appeal.decided" variant="info" class="ml-1">{{ record.appeal.decided.outcomeLabel }}</Badge>
                                <Badge v-else variant="secondary" class="ml-1">Lapsed</Badge>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                From {{ record.issuer }} · {{ consequence(record) }}
                                <template v-if="record.appeal.decided"> · decided {{ zulu(record.appeal.decided.at) }}</template>
                            </p>
                        </div>
                        <Button as-child variant="ghost" size="sm">
                            <Link :href="`/counseling/${record.id}`">View<span class="sr-only">: {{ record.trooper.name }}</span></Link>
                        </Button>
                    </li>
                </ul>
            </TabsContent>
        </Tabs>

        <Sheet v-model:open="sheetOpen">
            <SheetContent class="w-full gap-0 bg-card p-0 sm:max-w-[32rem]" data-testid="decide-sheet">
                <template v-if="deciding">
                    <SheetHeader class="border-b border-border p-5 pr-12">
                        <SheetTitle class="text-base">Appeal: {{ who(deciding.trooper) }}</SheetTitle>
                        <SheetDescription class="text-xs">LOR from {{ deciding.issuer }} · decide by {{ zulu(deciding.appeal.dueAt) }}</SheetDescription>
                    </SheetHeader>

                    <form id="decide-form" class="flex flex-1 flex-col gap-4 overflow-y-auto p-5 text-[13px]" @submit.prevent="decide">
                        <figure class="rounded-md border border-border bg-muted/50 p-3">
                            <figcaption class="flex items-center gap-2 text-xs text-muted-foreground"><PhQuotes class="size-4 shrink-0" aria-hidden="true" />The trooper's reason</figcaption>
                            <blockquote class="mt-1 pl-6 whitespace-pre-line" data-testid="decide-reason-quote">{{ deciding.appeal.reason }}</blockquote>
                        </figure>
                        <div>
                            <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Evidenced infraction</p>
                            <p class="mt-1 whitespace-pre-line">{{ deciding.form?.infraction }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Consequence as written</p>
                            <p class="mt-1 flex flex-wrap gap-1.5">
                                <Badge v-for="badge in consequenceBadges(deciding.form)" :key="badge" variant="warning">{{ badge }}</Badge>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            <button type="button" class="inline-flex items-center gap-1 text-primary hover:underline" data-testid="decide-pdf" @click="download(deciding)">
                                <PhFilePdf aria-hidden="true" />Open the full letter (PDF)
                            </button>
                            <Link :href="`/counseling/${deciding.id}`" class="inline-flex items-center gap-1 text-primary hover:underline">
                                <PhArrowSquareOut aria-hidden="true" />The record
                            </Link>
                        </div>

                        <div class="grid gap-1.5">
                            <Label id="decide-outcome-label">Your decision</Label>
                            <ToggleGroup v-model="form.outcome" type="single" variant="segment" class="w-fit rounded-md bg-muted p-0.5" aria-labelledby="decide-outcome-label" data-testid="decide-outcome">
                                <ToggleGroupItem value="not_amended" data-testid="decide-not-amended">Have not amended</ToggleGroupItem>
                                <ToggleGroupItem value="amended" data-testid="decide-amended">Have amended</ToggleGroupItem>
                            </ToggleGroup>
                            <p v-if="form.errors.outcome" class="text-xs text-destructive">{{ form.errors.outcome }}</p>
                        </div>

                        <ConsequenceFields
                            v-if="form.outcome === 'amended'"
                            id="decide"
                            v-model:consequences="form.form.consequences"
                            v-model:removal="form.form.removal"
                            v-model:special-punishments="form.form.special_punishments"
                            :errors="form.errors"
                        />

                        <div class="grid gap-1.5">
                            <Label for="decide-reason">Reason</Label>
                            <Textarea
                                id="decide-reason"
                                v-model="form.reason"
                                rows="3"
                                placeholder="Required. The trooper, the issuer and S1 read this."
                                :aria-invalid="!!form.errors.reason"
                                data-testid="decide-reason"
                            />
                            <p v-if="form.errors.reason" class="text-xs text-destructive" data-testid="decide-reason-error">{{ form.errors.reason }}</p>
                        </div>

                        <PmConsent :pm="pm" :consent-error="form.errors.pm_consent" :pm-error="form.errors.pm" sends="your decision" />
                    </form>

                    <SheetFooter class="flex-row justify-end gap-2 border-t border-border p-4">
                        <Button variant="outline" :disabled="form.processing" @click="sheetOpen = false">Cancel</Button>
                        <Button type="submit" form="decide-form" :disabled="form.processing || !pm.allowed || !form.outcome" data-testid="decide-submit">Record decision</Button>
                    </SheetFooter>
                </template>
            </SheetContent>
        </Sheet>
    </div>
</template>
