<script setup>
import { computed } from 'vue';
import { useNow } from '@/composables/useNow';
import { timeLeft, zulu } from '@/lib/counseling';

// Every clock that runs on a record (#565), worked out on the server (`Milpac\CounselingRecordView`) and drawn as a row
// of cards: the meeting window, the trooper's answer (an LOR's appeal choice), the appeal decision and when it locks.
// Before a clock starts, the card says how long it will run once it does.
const props = defineProps({
    record: { type: Object, required: true },
    // Who would hear an appeal, named on the trooper's choice (`appealAuthority`), or null.
    appealAuthority: { type: String, default: null },
});

const now = useNow();
const lor = computed(() => props.record.type === 'lor');

// A running clock's second line: what is left, or that it has passed.
const left = (at, passed) => {
    const rest = timeLeft(at, now.value);

    return rest ? { text: `in ${rest}`, tone: 'text-warning' } : { text: passed, tone: 'text-muted-foreground' };
};

const cards = computed(() => {
    const r = props.record;
    const cards = [];

    cards.push(
        r.meeting?.closesAt
            ? { key: 'meeting', title: 'Meeting window', value: `Closes ${zulu(r.meeting.closesAt)}`, ...(r.sharedAt ? { text: 'Met and shared', tone: 'text-muted-foreground' } : left(r.meeting.closesAt, 'Closed')) }
            : { key: 'meeting', title: 'Meeting window', value: '72 h after the invite', text: lor.value ? 'Invite not sent yet' : 'Optional for a DCS', tone: 'text-muted-foreground' },
    );

    const answer = lor.value ? "Trooper's appeal choice" : "Trooper's response";
    if (r.respondBy) {
        cards.push({
            key: 'respond',
            title: answer,
            value: `By ${zulu(r.respondBy)}`,
            ...(r.respondedAt ? { text: 'Answered', tone: 'text-muted-foreground' } : left(r.respondBy, 'No answer: it stands as written')),
        });
    } else {
        cards.push({ key: 'respond', title: answer, value: lor.value ? '24 h after you share' : 'Until it locks', text: 'Not started', tone: 'text-muted-foreground' });
    }

    if (lor.value) {
        const by = props.appealAuthority ? `, by ${props.appealAuthority}` : '';
        if (r.appeal?.decided) cards.push({ key: 'decision', title: 'Appeal decision', value: r.appeal.decided.outcomeLabel, text: `Decided ${zulu(r.appeal.decided.at)}`, tone: 'text-muted-foreground' });
        else if (r.appeal?.dueAt) cards.push({ key: 'decision', title: 'Appeal decision', value: `By ${zulu(r.appeal.dueAt)}${by}`, ...left(r.appeal.dueAt, 'Lapsed: it stands as written') });
        else cards.push({ key: 'decision', title: 'Appeal decision', value: `72 h${by}`, text: 'Only if appealed', tone: 'text-muted-foreground' });
    }

    if (r.locksAt) {
        cards.push({ key: 'locks', title: 'Locks', value: r.frozen ? 'Locked' : zulu(r.locksAt), ...(r.frozen ? { text: 'Only the RXO can overturn an LOR', tone: 'text-muted-foreground' } : left(r.locksAt, 'Locked')) });
    } else {
        cards.push({ key: 'locks', title: 'Locks', value: '72 h after you share', text: lor.value ? 'Or when an appeal is decided' : 'You can withdraw it until then', tone: 'text-muted-foreground' });
    }

    return cards;
});
</script>

<template>
    <dl class="grid overflow-hidden rounded-xl border border-border bg-card sm:grid-cols-2" :class="lor ? 'xl:grid-cols-4' : 'xl:grid-cols-3'" data-testid="counseling-deadlines">
        <div v-for="card in cards" :key="card.key" class="flex flex-col gap-0.5 border-b border-border px-4 py-3 last:border-b-0 sm:border-r xl:border-b-0 xl:last:border-r-0" :data-testid="`deadline-${card.key}`">
            <dt class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">{{ card.title }}</dt>
            <dd class="text-[13px] font-medium">{{ card.value }}</dd>
            <dd class="text-xs" :class="card.tone">{{ card.text }}</dd>
        </div>
    </dl>
</template>
