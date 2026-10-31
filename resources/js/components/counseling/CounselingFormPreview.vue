<script setup>
import { computed } from 'vue';
import { COUNSELING_TYPES, LOR_CONSEQUENCES, LOR_REMOVALS, letterDate } from '@/lib/counseling';

// The regiment's form as a sheet of paper on the page (#565): the live preview beside a draft, and the record as
// written beside a shared one. The portal's security policy lets no page show a PDF inside itself (no `blob:` frames,
// `object-src 'none'`), so this draws the form's sections in HTML from the same values the PDF is filled with; the real
// PDF is the "Draft PDF" or "Download PDF" button beside it. Paper stays white in both themes.
const props = defineProps({
    type: { type: String, required: true },
    // The Date of Letter, `Y-m-d`, or null.
    date: { type: String, default: null },
    // `{ trooper: { rank, name, billet }, authority: { … } }`, from the page's `section1`.
    section1: { type: Object, default: null },
    // The record's form values: the leader's and, once they responded, the trooper's.
    form: { type: Object, default: () => ({}) },
    // The appeal authority's decision on an LOR (`amended`, `not_amended`), or null.
    outcome: { type: String, default: null },
});

const lor = computed(() => props.type === 'lor');
const meta = computed(() => COUNSELING_TYPES[props.type] ?? COUNSELING_TYPES.dcs);
const party = (who) => [who?.rank, who?.name, who?.billet].filter(Boolean).join(' · ');
const value = (key) => (typeof props.form?.[key] === 'string' ? props.form[key].trim() : '');
const picked = (consequence) => Array.isArray(props.form?.consequences) && props.form.consequences.includes(consequence);
const removal = (which) => picked('removal') && props.form?.removal === which;

const rows = computed(() => [
    {
        title: 'Section 1 · Administrative data',
        rows: [
            { label: 'Trooper', text: party(props.section1?.trooper) },
            { label: meta.value.authority, text: party(props.section1?.authority) },
        ],
    },
    {
        title: 'Section 2 · Infraction',
        rows: [
            { label: 'Evidenced infraction', text: value('infraction') },
            { label: 'Regulation / SOP', text: value('regulation') },
            { label: 'Notes of discussion', text: value('discussion') },
        ],
    },
    {
        title: 'Section 3 · Plan of action',
        rows: [
            { label: meta.value.authority, text: value('plan_authority') },
            { label: 'Trooper', text: value('plan_trooper'), byTrooper: true },
            { label: 'Misc / others', text: value('plan_other'), optional: true },
        ],
    },
    {
        title: 'Any comments',
        rows: [
            { label: meta.value.authority, text: value('comments_authority'), optional: true },
            { label: 'Trooper', text: value('comments_trooper'), byTrooper: true },
            { label: 'Misc / others', text: value('comments_other'), optional: true },
        ],
    },
]);
</script>

<template>
    <div class="rounded-md bg-white p-4 font-sans text-[11px] leading-snug text-neutral-900 shadow-sm ring-1 ring-black/5" :aria-label="`${meta.label}, as the form reads`" role="document" data-testid="form-preview">
        <p class="text-center text-[12px] font-semibold tracking-wide uppercase">7th Cavalry Regiment · {{ meta.label }}</p>
        <p class="mb-3 text-center text-[10px] text-neutral-500">
            {{ meta.form }} · Date of letter <span class="text-blue-900" data-testid="form-preview-date">{{ letterDate(date) || '—' }}</span>
        </p>

        <section v-for="section in rows" :key="section.title" class="mb-2 border border-neutral-300">
            <h4 class="bg-neutral-100 px-1.5 py-0.5 text-[10px] font-semibold">{{ section.title }}</h4>
            <div v-for="row in section.rows" :key="row.label + section.title" class="grid grid-cols-[7.5rem_minmax(0,1fr)] border-t border-neutral-300">
                <span class="border-r border-neutral-300 px-1.5 py-0.5 text-neutral-600">{{ row.label }}</span>
                <span v-if="row.text" class="px-1.5 py-0.5 break-words whitespace-pre-line text-blue-900">{{ row.text }}</span>
                <span v-else-if="row.byTrooper" class="px-1.5 py-0.5 text-neutral-400">Filled in by the trooper</span>
                <span v-else-if="row.optional" class="px-1.5 py-0.5 text-neutral-400">—</span>
                <span v-else class="mx-1.5 my-1.5 border-b border-dashed border-amber-300" aria-label="Not filled in yet" />
            </div>
        </section>

        <template v-if="lor">
            <section class="mb-2 border border-neutral-300" data-testid="form-preview-consequence">
                <h4 class="bg-neutral-100 px-1.5 py-0.5 text-[10px] font-semibold">Section 4 · Consequence</h4>
                <ul class="space-y-0.5 border-t border-neutral-300 px-1.5 py-1">
                    <li v-for="(consequence, key) in LOR_CONSEQUENCES" :key="key" class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block size-2.5 border border-neutral-600" :class="{ 'bg-blue-900': picked(key) }" aria-hidden="true" />
                            <span :class="{ 'font-medium': picked(key) }">{{ consequence.short }}</span>
                            <span class="sr-only">{{ picked(key) ? '(ticked)' : '(not ticked)' }}</span>
                        </span>
                        <template v-if="key === 'removal'">
                            <span v-for="(which, name) in LOR_REMOVALS" :key="name" class="inline-flex items-center gap-1">
                                <span class="inline-block size-2.5 rounded-full border border-neutral-600" :class="{ 'bg-blue-900': removal(name) }" aria-hidden="true" />
                                {{ which.short }}<span class="sr-only">{{ removal(name) ? '(chosen)' : '' }}</span>
                            </span>
                        </template>
                    </li>
                </ul>
                <div class="grid grid-cols-[7.5rem_minmax(0,1fr)] border-t border-neutral-300">
                    <span class="border-r border-neutral-300 px-1.5 py-0.5 text-neutral-600">Special punishments</span>
                    <span class="px-1.5 py-0.5 whitespace-pre-line" :class="value('special_punishments') ? 'text-blue-900' : 'text-neutral-400'">{{ value('special_punishments') || '—' }}</span>
                </div>
            </section>
            <section class="border border-neutral-300" data-testid="form-preview-appeal">
                <h4 class="bg-neutral-100 px-1.5 py-0.5 text-[10px] font-semibold">Section 5 · Appeal</h4>
                <p class="border-t border-neutral-300 px-1.5 py-0.5" :class="form?.appeal ? 'text-blue-900' : 'text-neutral-400'">
                    <template v-if="form?.appeal === 'do'">The trooper DOES wish to appeal</template>
                    <template v-else-if="form?.appeal === 'do_not'">The trooper DOES NOT wish to appeal</template>
                    <template v-else>DO / DO NOT wish to appeal · filled in by the trooper</template>
                </p>
                <p v-if="outcome" class="border-t border-neutral-300 px-1.5 py-0.5 text-blue-900" data-testid="form-preview-outcome">
                    Appeal outcome: {{ outcome === 'amended' ? 'HAVE AMENDED' : 'HAVE NOT AMENDED' }}
                </p>
            </section>
        </template>
        <p v-else class="text-[10px] text-neutral-500">Counselling statements are not punitive and not subject to appeal.</p>
    </div>
</template>
