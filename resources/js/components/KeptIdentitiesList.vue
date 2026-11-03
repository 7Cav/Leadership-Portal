<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { forumDate } from '@/lib/forumDate';

// Kept identities (Admins only): game IDs kept on file for Cav names with no trooper in the portal, from the import.
// The roster sync moves one onto the trooper of exactly that name when they rejoin; `conflict` says why it could not.
// Delete asks for an optional reason, which goes into the audit log.
defineProps({
    // `[{ id, cavName, game, idKind, platformId, gamertag, addedBy, addedAt, conflict }]`
    kept: { type: Array, required: true },
});

const confirming = ref(null);
const reason = ref('');
const deleting = ref(false);

function ask(id) {
    confirming.value = id;
    reason.value = '';
}

function remove(id) {
    deleting.value = true;
    router.delete(`/game-identities/kept/${id}`, {
        data: { reason: reason.value.trim() || null },
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            confirming.value = null;
        },
    });
}
</script>

<template>
    <Card class="min-w-0 gap-0 py-0" data-testid="kept-identities">
        <h2 class="border-b border-border px-4 py-3 text-sm font-semibold">Kept identities</h2>
        <p v-if="!kept.length" class="px-4 py-3 text-[13px] text-muted-foreground" data-testid="kept-empty">
            None. An import line for a Cav name with no trooper in the portal is kept here until they rejoin.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-[13px]">
                <thead class="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-1.5 font-medium">Name</th>
                        <th class="px-3 py-1.5 font-medium">Game</th>
                        <th class="px-3 py-1.5 font-medium">Kind</th>
                        <th class="px-3 py-1.5 font-medium">ID</th>
                        <th class="px-3 py-1.5 font-medium">Gamertag</th>
                        <th class="px-3 py-1.5 font-medium">Added</th>
                        <th class="px-3 py-1.5 font-medium"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in kept" :key="row.id" class="border-t border-border align-top" :data-testid="`kept-row-${row.id}`">
                        <td class="px-3 py-1.5">
                            {{ row.cavName }}
                            <span v-if="row.conflict" class="block text-xs text-destructive" :data-testid="`kept-conflict-${row.id}`">{{ row.conflict }}</span>
                        </td>
                        <td class="px-3 py-1.5">{{ row.game }}</td>
                        <td class="px-3 py-1.5">{{ row.idKind }}</td>
                        <td class="px-3 py-1.5 font-mono text-xs break-all">{{ row.platformId }}</td>
                        <td class="px-3 py-1.5">{{ row.gamertag ?? '' }}</td>
                        <td class="px-3 py-1.5 text-muted-foreground">
                            {{ row.addedBy ?? 'Unknown' }}<template v-if="row.addedAt">, {{ forumDate(row.addedAt) }}</template>
                        </td>
                        <td class="px-3 py-1.5 text-right">
                            <div v-if="confirming === row.id" class="flex flex-wrap justify-end gap-2">
                                <Input v-model="reason" class="h-8 w-48" placeholder="Reason (optional)" aria-label="Reason (optional)" :data-testid="`kept-reason-${row.id}`" />
                                <Button size="sm" variant="destructive" :disabled="deleting" :data-testid="`kept-confirm-${row.id}`" @click="remove(row.id)">Delete</Button>
                                <Button size="sm" variant="outline" :disabled="deleting" @click="confirming = null">Cancel</Button>
                            </div>
                            <Button v-else size="sm" variant="outline" :data-testid="`kept-delete-${row.id}`" @click="ask(row.id)">Delete</Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
