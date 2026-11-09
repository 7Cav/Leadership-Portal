<script setup>
import { ref } from 'vue';
import { PhCaretRight } from '@phosphor-icons/vue';
import { forumDate, zuluTime } from '@/lib/forumDate';
import { namedSentence, presenceAnswer } from '@/lib/staffReport';

// The versions an edit replaced on a sent staff report, newest first: each opens to what it said, and when and why it
// was sent again. `revisions` are `[{ sentAt, sentBy, attended, practiceType, ftx, officeHour, presenceType, practices, otherEvents,
// notes, replaced: { at, by, reason } | null }]`.
defineProps({
    revisions: { type: Array, required: true },
});

const open = ref(null);

// "24OCT26 23 59Z".
function at(value) {
    return value ? `${forumDate(value)} ${zuluTime(value)}` : null;
}

function answer(revision) {
    if (!revision.attended) return 'Did not attend a section practice.';
    const named = namedSentence(revision);
    if (named) return named;
    if (revision.practiceType === 'office_hour') return `Held an office hour${revision.officeHour?.date ? ` on ${forumDate(revision.officeHour.date)}` : ''}.`;
    const count = revision.practices.length;

    return `Attended ${count} ${count === 1 ? 'section practice' : 'section practices'}.`;
}
</script>

<template>
    <div class="border-t border-border px-4 py-3" data-testid="staff-report-revisions">
        <h3 class="mb-1.5 text-[13px] font-semibold">Earlier versions</h3>
        <ul class="space-y-1">
            <li v-for="revision in revisions" :key="revision.sentAt" data-testid="staff-report-revision">
                <button
                    type="button"
                    class="flex w-full items-center gap-1.5 rounded-md py-1 text-left hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-expanded="open === revision.sentAt"
                    @click="open = open === revision.sentAt ? null : revision.sentAt"
                >
                    <PhCaretRight class="size-3.5 flex-none transition-transform" :class="{ 'rotate-90': open === revision.sentAt }" aria-hidden="true" />
                    <span>Sent {{ at(revision.sentAt) }}<template v-if="revision.sentBy"> by {{ revision.sentBy }}</template></span>
                </button>
                <dl v-if="open === revision.sentAt" class="mt-1 mb-3 ml-5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1" data-testid="staff-report-revision-body">
                    <template v-if="revision.replaced">
                        <dt class="text-muted-foreground">Sent again</dt>
                        <dd>
                            {{ at(revision.replaced.at) }}<template v-if="revision.replaced.by"> by {{ revision.replaced.by }}</template
                            ><template v-if="revision.replaced.reason">: {{ revision.replaced.reason }}</template>
                        </dd>
                    </template>
                    <dt class="text-muted-foreground">Answer</dt>
                    <dd>{{ answer(revision) }}</dd>
                    <template v-if="presenceAnswer(revision)">
                        <dt class="text-muted-foreground">Presence</dt>
                        <dd>{{ presenceAnswer(revision) }}</dd>
                    </template>
                    <template v-if="revision.officeHour?.note">
                        <dt class="text-muted-foreground">Office hour</dt>
                        <dd class="whitespace-pre-line">{{ revision.officeHour.note }}</dd>
                    </template>
                    <template v-if="revision.practices.length">
                        <dt class="text-muted-foreground">Practices</dt>
                        <dd class="font-mono">{{ revision.practices.map((practice) => practice.section ?? 'A section since removed').join(', ') }}</dd>
                    </template>
                    <template v-if="revision.otherEvents">
                        <dt class="text-muted-foreground">Other events attended</dt>
                        <dd class="whitespace-pre-line">{{ revision.otherEvents }}</dd>
                    </template>
                    <template v-if="revision.notes">
                        <dt class="text-muted-foreground">Notes</dt>
                        <dd class="whitespace-pre-line">{{ revision.notes }}</dd>
                    </template>
                </dl>
            </li>
        </ul>
    </div>
</template>
