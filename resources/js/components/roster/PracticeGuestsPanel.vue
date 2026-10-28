<script setup>
import { computed } from 'vue';
import { PhMedal, PhUsers } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import EmptyState from '@/components/EmptyState.vue';
import RankBadge from '@/components/roster/RankBadge.vue';
import { forumDate } from '@/lib/forumDate';
import { percentLabel } from '@/lib/roster';

// "Guests at our practices" (JSAM evidence, decided 2026-10-23): troopers from outside this unit who attended its
// sections' practices, how often, when last, and whether they also attend their own section. The Joint Service
// Achievement Medal is recommended by the commander of the unit that benefited; the portal never awards it, it only
// marks the candidates. Every number comes from RosterController (own-section SP from the shared KPI service, which
// leaves makeups out); the panel is read-only for every viewer, like the rest of the roster. Guests come from the host
// leaders adding them on their rollcall: the game server records them again once practices have set times.

// Shown with the own-section SP: why it can be lower than the trooper's SP elsewhere.
const OWN_SP_NOTE = 'Practices of their own section they attended, out of those it held. Makeups are not counted.';
const props = defineProps({
    // `{ criteria: { minVisits, minOwnSpPercent, windowWeeks }, rows: [{ trooperId, name, displayName, rank, rankFull,
    // section: { id, designation } | null, visits, lastSeen, ownSp: [n, d], candidate }] }`.
    guests: { type: Object, required: true },
    // The unit on screen, for the heading (`3/2/C/1-7`).
    unitLabel: { type: String, required: true },
});

// Candidates first, then the most visits, the most recently seen, and by name: the server sends them in this order
// too, kept here so the panel never depends on it.
const rows = computed(() =>
    [...props.guests.rows].sort(
        (a, b) =>
            Number(b.candidate) - Number(a.candidate) ||
            b.visits - a.visits ||
            b.lastSeen.localeCompare(a.lastSeen) ||
            (a.name ?? '').localeCompare(b.name ?? '', undefined, { sensitivity: 'base' }),
    ),
);
const criteria = computed(() => props.guests.criteria);
const candidates = computed(() => rows.value.filter((row) => row.candidate).length);
</script>

<template>
    <section class="rounded-[10px] border border-border bg-card" aria-labelledby="guests-heading" data-testid="practice-guests">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 pt-3.5 pb-2">
            <h2 id="guests-heading" class="flex items-center gap-2 text-sm font-semibold">
                <PhUsers class="size-4 text-muted-foreground" aria-hidden="true" />Guests at our practices
            </h2>
            <span class="text-xs text-muted-foreground tabular-nums" data-testid="practice-guests-count">
                {{ rows.length }} guest{{ rows.length === 1 ? '' : 's' }}<template v-if="candidates">, {{ candidates }} JSAM candidate{{ candidates === 1 ? '' : 's' }}</template>
            </span>
            <p class="w-full max-w-[80ch] text-xs text-muted-foreground" data-testid="practice-guests-criteria">
                Troopers from other units recorded as guests at {{ unitLabel }}'s practices over the last {{ criteria.windowWeeks }} Cav
                weeks; section leaders add them on the rollcall. A JSAM candidate attended at least {{ criteria.minVisits }} of them and
                {{ criteria.minOwnSpPercent }}% or more of their own section's practices over the same weeks (makeups do not count toward
                their own). The portal only shows the evidence; the recommendation is yours.
            </p>
        </div>

        <EmptyState v-if="!rows.length" :icon="PhUsers" title="No guests yet" class="py-8" data-testid="practice-guests-empty">
            Nobody from another unit was added as a guest at these practices in the last {{ criteria.windowWeeks }} Cav weeks. Section
            leaders add guests on their rollcall.
        </EmptyState>

        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[36rem] text-[13px]">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-y border-border">
                        <th scope="col" class="px-4 py-2 font-medium">Trooper</th>
                        <th scope="col" class="px-2 py-2 font-medium">Own section</th>
                        <th scope="col" class="px-2 py-2 text-right font-medium">Visits</th>
                        <th scope="col" class="px-2 py-2 font-medium">Last seen</th>
                        <th scope="col" class="px-2 py-2 text-right font-medium" :title="OWN_SP_NOTE">Own section SP</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">JSAM</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in rows" :key="row.trooperId" :data-testid="`practice-guest-${row.trooperId}`">
                        <td class="px-4 py-2">
                            <span class="flex items-center gap-2">
                                <RankBadge :rank="row.rank" :title="row.rankFull" />
                                <span class="truncate font-medium">{{ row.name }}</span>
                            </span>
                        </td>
                        <td class="px-2 py-2 font-mono text-xs text-muted-foreground">{{ row.section?.designation ?? 'No section' }}</td>
                        <td class="px-2 py-2 text-right tabular-nums" data-testid="practice-guest-visits">{{ row.visits }}</td>
                        <td class="px-2 py-2 text-muted-foreground tabular-nums">Week of {{ forumDate(row.lastSeen) }}</td>
                        <td
                            class="px-2 py-2 text-right tabular-nums"
                            data-testid="practice-guest-sp"
                            :title="row.ownSp[1] ? `${row.ownSp[0]} of ${row.ownSp[1]} practices of their own section. Makeups are not counted.` : 'Their own section held no practice to count.'"
                        >
                            {{ percentLabel(row.ownSp) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Badge v-if="row.candidate" variant="outline" class="border-success/35 bg-success/8 font-normal text-success" data-testid="jsam-candidate">
                                <PhMedal aria-hidden="true" />JSAM candidate
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
