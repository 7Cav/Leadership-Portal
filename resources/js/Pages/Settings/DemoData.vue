<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { PhCheck, PhFlask, PhLockSimple, PhSparkle, PhTrash, PhTreeStructure, PhWarning } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import EmptyState from '@/components/EmptyState.vue';

// Settings → Demo data (#115). Generates plausible past weeks of rollcall, SP marks and sent reports
// for a unit's real troopers, or purges what was generated. Only reachable while DEMO_TOOLS is on.
const props = defineProps({
    units: { type: Array, required: true },
    existing: { type: Object, required: true },
    weeks: { type: Object, required: true },
    recentWeeks: { type: Array, required: true },
    result: { type: Object, default: null },
});

const page = usePage();

// A view-as preview is read-only (Story 1.13): the server refuses writes, so the controls say so
// up front instead of letting a request fail.
const viewAs = computed(() => page.props.viewAs ?? null);
const readOnlyHint = computed(() => (viewAs.value ? `Read-only while viewing as ${viewAs.value.label}` : null));

const GROUPS = [
    { type: 'battalion', heading: 'Battalions' },
    { type: 'company', heading: 'Companies' },
    { type: 'platoon', heading: 'Platoons' },
    { type: 'section', heading: 'Sections' },
];
const grouped = computed(() =>
    GROUPS.map((group) => ({ ...group, units: props.units.filter((unit) => unit.type === group.type) })).filter((group) => group.units.length),
);

const selectedId = ref(null);
const selected = computed(() => props.units.find((unit) => unit.id === selectedId.value) ?? null);
const label = (unit) => unit.callsign || unit.name;

const form = useForm({ unit_id: null, weeks: props.weeks.default });

const weeksValid = computed(() => Number.isInteger(Number(form.weeks)) && form.weeks >= 1 && form.weeks <= props.weeks.max);
const span = computed(() => {
    if (!weeksValid.value) return null;
    const covered = props.recentWeeks.slice(-Number(form.weeks));

    return { from: covered[0], to: covered[covered.length - 1] };
});

const canAct = computed(() => selected.value !== null && !viewAs.value && !form.processing);

const confirming = ref(null); // null | 'generate' | 'purge'

function submit() {
    const action = confirming.value;
    form.unit_id = selectedId.value;

    const failed = (message) => {
        confirming.value = null;
        toast.error(message);

        return false; // handled here: no raw error page over the screen
    };
    const options = {
        preserveScroll: true,
        onSuccess: (page) => {
            confirming.value = null;
            announce(page.props.result);
        },
        onError: () => (confirming.value = null),
        onHttpException: (response) =>
            failed(
                response.status === 403
                    ? 'That is not allowed. Exit view as, or reload and pick the unit again.'
                    : `${action === 'purge' ? 'The purge' : 'Generating'} failed (error ${response.status}). Earlier weeks may already be written; reload to see what the portal holds now.`,
            ),
        onNetworkError: () => failed('The portal could not be reached. Check your connection and try again.'),
    };

    if (action === 'generate') {
        form.transform((data) => data).post('/settings/demo-data', options);
    } else {
        form.transform((data) => ({ unit_id: data.unit_id })).delete('/settings/demo-data', options);
    }
}

// Every generate or purge comes back as a flashed `result`: toast it once per run, from the run's
// own success callback. Watching the prop instead toasted again whenever the page was shown with
// that prop, such as going back to it from history.
function announce(result) {
    if (!result) return;
    if (result.empty) toast.info(result.summary);
    else toast.success(result.summary);
}

const stats = computed(() => [
    { key: 'weeks', label: 'Weeks', value: props.existing.weeks },
    { key: 'practices', label: 'Practices', value: props.existing.practices },
    { key: 'attendance', label: 'Attendance rows', value: props.existing.attendance },
    { key: 'reports', label: 'Sent reports', value: props.existing.reports },
]);
const hasDemoData = computed(() => props.existing.practices + props.existing.attendance + props.existing.reports > 0);
</script>

<template>
    <Head title="Settings · Demo data · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Demo data</h1>
                <p class="mt-1 max-w-[72ch] text-[13px] text-muted-foreground">
                    Fill past weeks with plausible roll call signatures, practice marks and sent reports for a unit's real troopers, so every
                    screen has history to show. Everything generated is tagged as demo data and can be purged in one step.
                </p>
            </div>
            <Badge variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin only</Badge>
        </div>

        <EmptyState v-if="!units.length" :icon="PhTreeStructure" title="No units to generate for" class="rounded-lg border border-border bg-card py-12" data-testid="no-units">
            Import the regiment from Cav on
            <Link href="/settings/org-structure" class="underline underline-offset-2">Org structure</Link>
            and sync the roster, then choose a unit here.
        </EmptyState>

        <div v-else class="grid items-start gap-5 lg:grid-cols-[1.15fr_0.85fr]">
            <Card class="min-w-0 gap-0 py-0">
                <div class="px-4 pt-3.5 pb-1">
                    <h2 class="text-[15px] font-semibold">Generate or purge</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Covers the unit and everything beneath it.</p>
                </div>

                <div class="grid gap-4 p-4">
                    <div class="grid gap-1.5">
                        <Label id="demo-unit-label">Unit</Label>
                        <Command v-model="selectedId" class="rounded-md border border-border bg-card" data-testid="unit-picker">
                            <CommandInput placeholder="Search by designation or name" aria-labelledby="demo-unit-label" class="text-[13px]" />
                            <CommandList class="max-h-64 p-1" aria-labelledby="demo-unit-label">
                                <CommandEmpty class="px-4 py-6 text-[13px] text-muted-foreground">No unit matches that search.</CommandEmpty>
                                <CommandGroup v-for="group in grouped" :key="group.type" :heading="group.heading">
                                    <CommandItem
                                        v-for="unit in group.units"
                                        :key="unit.id"
                                        :value="unit.id"
                                        :data-testid="`unit-option-${unit.id}`"
                                        class="gap-2.5 py-1.5 text-[13px]"
                                    >
                                        <span class="w-[9ch] shrink-0 truncate font-mono text-[12px]">{{ unit.callsign ?? '—' }}</span>
                                        <span class="min-w-0 truncate">{{ unit.name }}</span>
                                        <span class="ml-auto shrink-0 text-xs tabular-nums text-muted-foreground">
                                            {{ unit.troopers }} {{ unit.troopers === 1 ? 'trooper' : 'troopers' }}
                                        </span>
                                        <PhCheck v-if="unit.id === selectedId" class="size-4 text-foreground" aria-hidden="true" />
                                    </CommandItem>
                                </CommandGroup>
                            </CommandList>
                        </Command>
                        <p class="text-xs text-muted-foreground" data-testid="selected-unit" aria-live="polite">
                            <template v-if="selected">
                                Selected <span class="font-medium text-foreground">{{ label(selected) }}</span> · {{ selected.name }}
                            </template>
                            <template v-else>Choose a unit to generate or purge its demo data.</template>
                        </p>
                        <p v-if="selected && selected.troopers === 0" class="flex items-center gap-1.5 text-xs text-warning" data-testid="no-troopers-hint">
                            <PhWarning aria-hidden="true" />
                            Nobody is placed in this unit's sections yet, so there is nothing to generate. Sync the roster first.
                        </p>
                        <p v-if="form.errors.unit_id" class="text-xs text-destructive" data-testid="error-unit_id">{{ form.errors.unit_id }}</p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="demo-weeks">Weeks</Label>
                        <Input
                            id="demo-weeks"
                            class="w-28"
                            v-model.number="form.weeks"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            :max="weeks.max"
                            data-testid="weeks"
                            :aria-invalid="!weeksValid || !!form.errors.weeks"
                            aria-describedby="demo-weeks-hint"
                        />
                        <p id="demo-weeks-hint" class="text-xs text-muted-foreground">
                            <template v-if="span">
                                Completed weeks <span class="whitespace-nowrap">{{ span.from }}</span> to
                                <span class="whitespace-nowrap">{{ span.to }}</span>. The current week stays untouched.
                            </template>
                            <template v-else>Choose 1 to {{ weeks.max }} completed weeks.</template>
                        </p>
                        <p v-if="form.errors.weeks" class="text-xs text-destructive" data-testid="error-weeks">{{ form.errors.weeks }}</p>
                    </div>

                    <div class="flex flex-wrap gap-2 border-t border-border pt-4">
                        <Tooltip :disabled="!readOnlyHint">
                            <TooltipTrigger as-child>
                                <span tabindex="-1">
                                    <Button data-testid="generate" :disabled="!canAct || !weeksValid" @click="confirming = 'generate'">
                                        <PhSparkle aria-hidden="true" />Generate
                                    </Button>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>{{ readOnlyHint }}</TooltipContent>
                        </Tooltip>
                        <Tooltip :disabled="!readOnlyHint">
                            <TooltipTrigger as-child>
                                <span tabindex="-1">
                                    <Button
                                        variant="outline"
                                        data-testid="purge"
                                        class="text-destructive hover:text-destructive"
                                        :disabled="!canAct"
                                        @click="confirming = 'purge'"
                                    >
                                        <PhTrash aria-hidden="true" />Purge
                                    </Button>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>{{ readOnlyHint }}</TooltipContent>
                        </Tooltip>
                        <p v-if="readOnlyHint" class="w-full text-xs text-muted-foreground" data-testid="read-only">{{ readOnlyHint }}.</p>
                    </div>
                </div>
            </Card>

            <div class="grid min-w-0 gap-5">
                <Card class="gap-0 py-0" data-testid="existing">
                    <div class="px-4 pt-3.5 pb-2">
                        <h2 class="text-[15px] font-semibold">In the portal now</h2>
                    </div>
                    <dl v-if="hasDemoData" class="grid grid-cols-2 border-t border-border">
                        <div
                            v-for="(stat, index) in stats"
                            :key="stat.key"
                            :class="['px-4 py-3', index % 2 === 0 ? 'border-r border-border' : '', index < 2 ? 'border-b border-border' : '']"
                        >
                            <dt class="text-xs text-muted-foreground">{{ stat.label }}</dt>
                            <dd class="mt-0.5 text-lg font-semibold tabular-nums" :data-testid="`existing-${stat.key}`">{{ stat.value }}</dd>
                        </div>
                    </dl>
                    <EmptyState v-else :icon="PhFlask" title="No demo data yet" class="border-t border-border py-8">
                        Generated weeks show up here, across every unit.
                    </EmptyState>
                </Card>

                <Card v-if="result" class="gap-2 p-4" data-testid="result" role="status">
                    <h2 class="text-[15px] font-semibold">{{ result.action === 'purged' ? 'Last purge' : 'Last generation' }}</h2>
                    <p class="text-[13px]">{{ result.summary }}</p>
                    <div v-if="result.generated_weeks?.length" class="flex flex-wrap gap-1">
                        <Badge v-for="week in result.generated_weeks" :key="week" variant="outline" class="font-mono text-[11px] tabular-nums">{{ week }}</Badge>
                    </div>
                    <p v-if="result.skipped_weeks?.length" class="flex items-start gap-1.5 text-xs text-warning" data-testid="skipped">
                        <PhWarning class="mt-px shrink-0" aria-hidden="true" />
                        Skipped {{ result.skipped_weeks.join(', ') }}: those weeks already hold real attendance or reports, which are never overwritten.
                    </p>
                </Card>

                <ul class="space-y-1.5 px-1 text-xs text-muted-foreground">
                    <li>Uses the troopers synced from the roster and the sections imported from Cav. Troopers, units and users are never changed.</li>
                    <li>Re-running replaces earlier demo data for those weeks with the same pattern.</li>
                    <li>Platoon and company reports are sent for every week but the latest, so that week's roll-up is left to demo live.</li>
                </ul>
            </div>
        </div>
    </div>

    <Dialog :open="confirming !== null" @update:open="(open) => !open && !form.processing && (confirming = null)">
        <DialogContent class="sm:max-w-[440px]" data-testid="confirm-dialog">
            <DialogHeader>
                <DialogTitle>
                    {{ confirming === 'purge' ? `Purge demo data for ${selected && label(selected)}?` : `Generate demo data for ${selected && label(selected)}?` }}
                </DialogTitle>
                <DialogDescription v-if="confirming === 'purge'">
                    Deletes every demo practice, attendance row and report in {{ selected && label(selected) }} and the units beneath it. Real
                    records are never touched.
                </DialogDescription>
                <DialogDescription v-else>
                    Writes {{ form.weeks }} {{ Number(form.weeks) === 1 ? 'week' : 'weeks' }} of demo history<template v-if="span">
                        (<span class="whitespace-nowrap">{{ span.from }}</span> to <span class="whitespace-nowrap">{{ span.to }}</span>)</template
                    >
                    for {{ selected?.troopers ?? 0 }} {{ (selected?.troopers ?? 0) === 1 ? 'trooper' : 'troopers' }}. Earlier demo data for those weeks is replaced; weeks that already hold real records
                    are skipped.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <DialogClose as-child>
                    <Button variant="outline" :disabled="form.processing">Cancel</Button>
                </DialogClose>
                <Button
                    :variant="confirming === 'purge' ? 'destructive' : 'default'"
                    :disabled="form.processing"
                    data-testid="confirm"
                    @click="submit"
                >
                    {{ form.processing ? (confirming === 'purge' ? 'Purging' : 'Generating') : confirming === 'purge' ? 'Purge demo data' : 'Generate demo data' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
