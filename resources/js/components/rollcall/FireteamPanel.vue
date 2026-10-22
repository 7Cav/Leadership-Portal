<script setup>
import { computed, ref } from 'vue';
import { PhBuildings, PhCaretDown, PhCircleNotch, PhUsersFour } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { NativeSelect } from '@/components/ui/native-select';
import { messageFor, unreachable } from '@/composables/useRollcallAutosave';
import { ROLE_LABELS, fireteamNames, leadsFireteam } from '@/lib/fireteams';
import { postJson } from '@/lib/postJson';
import { fullName } from '@/lib/rollcall';

// The rollcall's fireteam controls (the owner's order, 2026-10-16), on the review step above the forum post they order.
// The post lists the SL, then the ASL, then everyone by rank; the section's leaders may show unofficial fireteam leaders
// (FTLs) as well, all above the rest or each above their own fireteam, named by letter in the order they appear. A
// company's command may enforce either choice for every section, and the enforced value shows here, locked, as "Set by
// company"; only the company lets the SL and ASL lead a fireteam. Every change is saved at once and the server answers
// the section's fireteams as they now stand, which the page takes as they are (`updated`).
const props = defineProps({
    sectionId: { type: Number, required: true },
    // The rollcall rows in the order the post lists them: `[{ id, name, rankShort, role, leadsFireteam, fireteamLeaderId, ... }]`.
    troopers: { type: Array, required: true },
    // `{ show, order, slAslLead, alphabet, section: { show, order }, company: { id, designation, show, order, slAslLead } | null,
    // can: { edit, editPolicy } }`.
    fireteams: { type: Object, required: true },
    // False during a view-as preview, which changes nothing.
    editable: { type: Boolean, default: true },
    disabledReason: { type: String, default: null },
});

const emit = defineEmits(['updated']);

const busy = ref(false);
const error = ref(null);
const open = ref(false);
// Bumped when a change is refused, so each select redraws with the value the server still holds.
const revision = ref(0);

const canEdit = computed(() => props.editable && props.fireteams.can.edit);
const canEditPolicy = computed(() => props.editable && props.fireteams.can.editPolicy);
const readOnlyNote = computed(() => {
    if (props.disabledReason) return props.disabledReason;

    return props.fireteams.can.edit ? null : 'Only the leaders who command this section can change its fireteams';
});

const company = computed(() => props.fireteams.company);
const showEnforced = computed(() => company.value?.show !== null && company.value?.show !== undefined);
const orderEnforced = computed(() => company.value?.order !== null && company.value?.order !== undefined);

// Every fireteam leader, the SL or ASL included where the company allows it, and the letter each fireteam goes by.
const ftls = computed(() => props.troopers.filter(leadsFireteam));
const names = computed(() => fireteamNames(props.troopers, props.fireteams));
const commands = (trooper) => trooper.role === 'sl' || trooper.role === 'asl';
// The SL and ASL get a select only where the company lets them lead a fireteam; they are never a member of one.
const selectable = (trooper) => !commands(trooper) || props.fireteams.slAslLead;
const teamLabel = (ftl) => `${names.value.get(ftl.id) ?? 'Fireteam'} (${fullName(ftl)})`;

const summary = computed(() => {
    if (!props.fireteams.show) return 'The post lists the SL, then the ASL, then everyone by rank.';
    if (props.fireteams.order === 'by_fireteam') return 'The post lists the SL, the ASL, then each fireteam leader above their fireteam, then everyone else, each by rank.';

    return 'The post lists the SL, the ASL, then the fireteam leaders, then everyone else, each by rank.';
});

// A trooper's choice in their select: '' (no fireteam), 'ftl', or 'member:<leader id>'.
const valueOf = (trooper) => (leadsFireteam(trooper) ? 'ftl' : trooper.fireteamLeaderId ? `member:${trooper.fireteamLeaderId}` : '');

// Fireteams matter to the order only "by fireteam", so that is when a trooper is offered one; a trooper already in one
// keeps it listed so the select shows the truth.
const teamsFor = (trooper) =>
    commands(trooper) ? [] : ftls.value.filter((ftl) => ftl.id !== trooper.id && (props.fireteams.order === 'by_fireteam' || trooper.fireteamLeaderId === ftl.id));

async function send(path, body) {
    if (busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        const result = await postJson(`/rollcall/${props.sectionId}/fireteams${path}`, body).catch(unreachable);
        if (result.ok && result.data?.fireteams) emit('updated', result.data);
        else {
            error.value = messageFor(result);
            revision.value++;
        }
    } finally {
        busy.value = false;
    }
}

function setRole(trooper, value) {
    if (!canEdit.value || value === valueOf(trooper)) return;
    const [role, leader] = value.split(':');
    send('', { trooper_id: trooper.id, role: role || null, leader_id: leader ? Number(leader) : null });
}

const setShow = (show) => canEdit.value && !showEnforced.value && show !== props.fireteams.show && send('/options', { show });
const setOrder = (order) => canEdit.value && !orderEnforced.value && order !== props.fireteams.order && send('/options', { order });

// The whole policy goes each time: `show` true, false or null (each section chooses), `order` 'leaders_first' or null,
// and whether the SL and ASL may lead a fireteam.
const POLICY_SHOW = { '': null, shown: true, hidden: false };
const policyShowValue = computed(() => (company.value?.show === true ? 'shown' : company.value?.show === false ? 'hidden' : ''));

function setPolicy(field, raw) {
    if (!canEditPolicy.value) return;
    const policy = { show: company.value.show ?? null, order: company.value.order ?? null, sl_asl_lead: company.value.slAslLead ?? false };
    if (field === 'show') policy.show = POLICY_SHOW[raw] ?? null;
    if (field === 'order') policy.order = raw || null;
    if (field === 'sl_asl_lead') policy.sl_asl_lead = raw === 'yes';
    send('/policy', policy);
}

const segment =
    'inline-flex h-[26px] items-center gap-1.5 rounded-sm px-2.5 text-xs font-medium text-muted-foreground aria-pressed:bg-card aria-pressed:text-foreground aria-pressed:shadow-xs disabled:cursor-not-allowed disabled:opacity-60';
</script>

<template>
    <section aria-labelledby="fireteams-title" class="mt-5 rounded-lg border border-border bg-card text-[13px]" data-testid="fireteams">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 pt-3.5">
            <h3 id="fireteams-title" class="flex items-center gap-2 font-semibold">
                <PhUsersFour class="size-4 text-muted-foreground" aria-hidden="true" />Order and fireteams
            </h3>
            <PhCircleNotch v-if="busy" class="size-3.5 animate-spin text-muted-foreground" aria-label="Saving" />
            <span v-if="readOnlyNote" class="ml-auto text-xs text-muted-foreground" data-testid="fireteams-read-only">{{ readOnlyNote }}</span>
        </div>
        <p class="px-4 pt-0.5 text-xs text-muted-foreground" data-testid="fireteams-summary">{{ summary }}</p>

        <div class="flex flex-wrap items-center gap-x-5 gap-y-2.5 px-4 pt-3">
            <div class="flex flex-wrap items-center gap-2">
                <span id="fireteams-show-label" class="text-xs text-muted-foreground">Fireteam leaders</span>
                <div class="inline-flex gap-0.5 rounded-md bg-muted p-0.5" role="group" aria-labelledby="fireteams-show-label" data-testid="fireteams-show">
                    <button
                        v-for="option in [{ value: false, label: 'Hidden' }, { value: true, label: 'Shown' }]"
                        :key="option.label"
                        type="button"
                        :class="segment"
                        :aria-pressed="fireteams.show === option.value"
                        :disabled="!canEdit || showEnforced || busy"
                        :title="readOnlyNote ?? undefined"
                        @click="setShow(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <span v-if="showEnforced" class="text-xs text-muted-foreground" data-testid="fireteams-show-enforced">Set by company</span>
            </div>

            <div v-if="fireteams.show" class="flex flex-wrap items-center gap-2">
                <span id="fireteams-order-label" class="text-xs text-muted-foreground">List them</span>
                <div class="inline-flex gap-0.5 rounded-md bg-muted p-0.5" role="group" aria-labelledby="fireteams-order-label" data-testid="fireteams-order">
                    <button
                        v-for="option in [{ value: 'leaders_first', label: 'All above the rest' }, { value: 'by_fireteam', label: 'Above their fireteam' }]"
                        :key="option.value"
                        type="button"
                        :class="segment"
                        :aria-pressed="fireteams.order === option.value"
                        :disabled="!canEdit || orderEnforced || busy"
                        :title="readOnlyNote ?? undefined"
                        @click="setOrder(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <span v-if="orderEnforced" class="text-xs text-muted-foreground" data-testid="fireteams-order-enforced">Set by company</span>
            </div>
        </div>

        <p v-if="error" role="alert" class="mx-4 mt-3 rounded-md bg-destructive/10 px-2.5 py-1.5 text-destructive" data-testid="fireteams-error">{{ error }}</p>

        <template v-if="fireteams.show">
            <button
                type="button"
                class="mx-4 mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-foreground hover:underline"
                :aria-expanded="open"
                aria-controls="fireteams-list"
                data-testid="fireteams-toggle"
                @click="open = !open"
            >
                <PhCaretDown :class="['size-3.5 transition-transform', open ? '' : '-rotate-90']" aria-hidden="true" />
                {{ canEdit ? 'Choose fireteam leaders' : 'Fireteam leaders' }}
                <span class="text-muted-foreground tabular-nums">{{ ftls.length }}</span>
            </button>
            <ul v-show="open" id="fireteams-list" :key="revision" class="mx-2 mt-1.5 divide-y divide-border" data-testid="fireteams-list">
                <li
                    v-for="trooper in troopers"
                    :key="trooper.id"
                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-2 py-1.5"
                    :data-testid="`fireteam-${trooper.id}`"
                >
                    <span class="flex min-w-[11rem] flex-1 items-center gap-2">
                        <span class="truncate">{{ fullName(trooper) }}</span>
                        <Badge v-if="commands(trooper)" variant="outline" class="shrink-0 font-normal">{{ ROLE_LABELS[trooper.role] }}</Badge>
                        <Badge v-if="names.has(trooper.id)" variant="secondary" class="shrink-0 font-normal" data-testid="fireteam-name">{{ names.get(trooper.id) }}</Badge>
                    </span>
                    <span v-if="!selectable(trooper)" class="shrink-0 text-xs text-muted-foreground">Listed first</span>
                    <div v-else class="w-56 max-w-full shrink-0">
                        <NativeSelect
                            :model-value="valueOf(trooper)"
                            :aria-label="`Fireteam role for ${fullName(trooper)}`"
                            :disabled="!canEdit || busy"
                            :title="readOnlyNote ?? undefined"
                            data-testid="fireteam-role"
                            @update:model-value="(value) => setRole(trooper, value)"
                        >
                            <option value="">No fireteam role</option>
                            <option value="ftl">Fireteam leader</option>
                            <option v-for="ftl in teamsFor(trooper)" :key="ftl.id" :value="`member:${ftl.id}`">In {{ teamLabel(ftl) }}</option>
                        </NativeSelect>
                    </div>
                </li>
            </ul>
        </template>

        <div v-if="company && canEditPolicy" :key="revision" class="mt-3 border-t border-border px-4 py-3" data-testid="fireteams-policy">
            <p class="flex items-center gap-2 text-xs font-semibold">
                <PhBuildings class="size-4 text-muted-foreground" aria-hidden="true" />Company policy, every section in {{ company.designation }}
            </p>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                <label class="grid gap-1 text-xs text-muted-foreground">
                    Fireteam leaders
                    <NativeSelect
                        :model-value="policyShowValue"
                        :disabled="busy"
                        data-testid="fireteams-policy-show"
                        @update:model-value="(value) => setPolicy('show', value)"
                    >
                        <option value="">Each section chooses</option>
                        <option value="shown">Shown on every section</option>
                        <option value="hidden">Hidden on every section</option>
                    </NativeSelect>
                </label>
                <label class="grid gap-1 text-xs text-muted-foreground">
                    Order
                    <NativeSelect
                        :model-value="company.order ?? ''"
                        :disabled="busy"
                        data-testid="fireteams-policy-order"
                        @update:model-value="(value) => setPolicy('order', value)"
                    >
                        <option value="">Each section chooses</option>
                        <option value="leaders_first">All fireteam leaders above the rest</option>
                    </NativeSelect>
                </label>
                <label class="grid gap-1 text-xs text-muted-foreground">
                    SL and ASL can lead a fireteam
                    <NativeSelect
                        :model-value="company.slAslLead ? 'yes' : 'no'"
                        :disabled="busy"
                        data-testid="fireteams-policy-sl-asl"
                        @update:model-value="(value) => setPolicy('sl_asl_lead', value)"
                    >
                        <option value="no">No, they stay above the fireteams</option>
                        <option value="yes">Yes, on every section</option>
                    </NativeSelect>
                </label>
            </div>
        </div>
        <div v-else class="pb-3.5" />
    </section>
</template>
